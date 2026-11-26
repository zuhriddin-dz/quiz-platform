<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\RunAnswer;
use App\Models\User;
use App\Queries\QuizAnalytics;

/**
 * Phase 9 gate — R9.1 to R9.5.
 *
 * The fixtures are hand-built rather than played through HTTP: the point is
 * whether the aggregates are right, and five questions of sudden death per
 * run would bury that in setup.
 */

/**
 * Finish a run with an explicit answer log.
 *
 * @param  array<int, array{question: Question, correct: bool, chosen?: int|null, reason?: FailureReason, time_ms?: int}>  $answers
 */
function analyticsRun(
    Quiz $quiz,
    ?User $player,
    array $answers,
    bool $passed = false,
    ?string $status = null,
): QuizRun {
    $run = $player === null
        ? QuizRun::factory()->for($quiz)->forGuest()->create()
        : QuizRun::factory()->for($quiz)->forUser($player)->create();

    foreach ($answers as $answer) {
        RunAnswer::create([
            'quiz_run_id' => $run->id,
            'question_id' => $answer['question']->id,
            'chosen_option_index' => $answer['chosen'] ?? null,
            'is_correct' => $answer['correct'],
            'failure_reason' => $answer['reason'] ?? null,
            'time_ms' => $answer['time_ms'] ?? 1_000,
        ]);
    }

    $reached = collect($answers)->where('correct', true)->count();

    $run->reached = $reached;

    if ($status !== null) {
        // An abandoned run: set it directly, bypassing the bookkeeping.
        $run->status = $status;
        $run->finished_at = now();
        $run->save();

        return $run->fresh();
    }

    $passed
        ? app(App\Actions\Run\FinishRun::class)->pass($run)
        : app(App\Actions\Run\FinishRun::class)->fail($run, FailureReason::WrongAnswer);

    return $run->fresh();
}

function analyticsFor(Quiz $quiz): array
{
    return app(QuizAnalytics::class)->for($quiz->fresh());
}

// -------------------------------------------------------------------- R9.1

it('counts runs, unique players and the pass rate', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();
    $question = $quiz->questions->first();

    $alice = User::factory()->create();
    $bob = User::factory()->create();

    // Alice twice (one pass), Bob once, one guest once.
    analyticsRun($quiz, $alice, [['question' => $question, 'correct' => true]], passed: true);
    analyticsRun($quiz, $alice, [['question' => $question, 'correct' => false, 'chosen' => 1]]);
    analyticsRun($quiz, $bob, [['question' => $question, 'correct' => false, 'chosen' => 1]]);
    analyticsRun($quiz, null, [['question' => $question, 'correct' => false, 'chosen' => 2]]);

    $totals = analyticsFor($quiz)['totals'];

    expect($totals['runs'])->toBe(4)
        ->and($totals['passes'])->toBe(1)
        ->and($totals['pass_rate'])->toBe(0.25)
        // Two users plus one guest token.
        ->and($totals['unique_players'])->toBe(3)
        ->and($totals['unique_users'])->toBe(2)
        ->and($totals['unique_guests'])->toBe(1);
});

it('reports a null pass rate rather than 0% with no runs', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();

    expect(analyticsFor($quiz)['totals']['pass_rate'])->toBeNull()
        ->and(analyticsFor($quiz)['totals']['runs'])->toBe(0);
});

it('excludes the creator’s own test runs', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(3)->create();
    $question = $quiz->questions->first();

    analyticsRun($quiz, $creator, [['question' => $question, 'correct' => true]], passed: true);
    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'chosen' => 1],
    ]);

    $totals = analyticsFor($quiz)['totals'];

    expect($totals['runs'])->toBe(1)
        ->and($totals['passes'])->toBe(0)
        ->and($totals['unique_players'])->toBe(1);
});

it('excludes abandoned and in-progress runs', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();
    $question = $quiz->questions->first();

    analyticsRun($quiz, User::factory()->create(), [['question' => $question, 'correct' => true]], passed: true);

    // Abandoned, with a real answer row attached.
    analyticsRun(
        $quiz,
        User::factory()->create(),
        [['question' => $question, 'correct' => true]],
        status: 'abandoned',
    );

    // Still playing.
    QuizRun::factory()->for($quiz)->create();

    $analytics = analyticsFor($quiz);

    expect($analytics['totals']['runs'])->toBe(1)
        // And the per-question count agrees, so the two never contradict.
        ->and($analytics['questions'][0]['answers'])->toBe(1);
});

// -------------------------------------------------------------------- R9.2

it('reports the correct rate per question', function () {
    $quiz = Quiz::factory()->published()->withQuestions(2)->create();
    [$first, $second] = $quiz->questions->all();

    // Four players reach question 1; three get it right. Three reach
    // question 2; one gets it right.
    foreach (range(1, 3) as $ignored) {
        analyticsRun($quiz, User::factory()->create(), [
            ['question' => $first, 'correct' => true],
            ['question' => $second, 'correct' => false, 'chosen' => 2],
        ]);
    }

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $first, 'correct' => false, 'chosen' => 1],
    ]);

    $questions = analyticsFor($quiz)['questions'];

    expect($questions[0]['answers'])->toBe(4)
        ->and($questions[0]['correct'])->toBe(3)
        ->and($questions[0]['correct_rate'])->toBe(0.75)
        ->and($questions[1]['answers'])->toBe(3)
        ->and($questions[1]['correct_rate'])->toBe(0.0);
});

it('shows how many players reached each question', function () {
    // Without this count a 100% rate on a late question is misleading.
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();
    [$first, $second, $third] = $quiz->questions->all();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $first, 'correct' => true],
        ['question' => $second, 'correct' => true],
        ['question' => $third, 'correct' => true],
    ], passed: true);

    foreach (range(1, 5) as $ignored) {
        analyticsRun($quiz, User::factory()->create(), [
            ['question' => $first, 'correct' => false, 'chosen' => 1],
        ]);
    }

    $questions = analyticsFor($quiz)['questions'];

    expect($questions[0]['answers'])->toBe(6)
        ->and($questions[2]['answers'])->toBe(1)
        ->and($questions[2]['correct_rate'])->toBe(1.0);
});

it('averages the answer time', function () {
    $quiz = Quiz::factory()->published()->withQuestions(1)->create();
    $question = $quiz->questions->first();

    foreach ([1_000, 2_000, 3_000] as $ms) {
        analyticsRun($quiz, User::factory()->create(), [
            ['question' => $question, 'correct' => false, 'chosen' => 1, 'time_ms' => $ms],
        ]);
    }

    expect(analyticsFor($quiz)['questions'][0]['average_time_ms'])->toBe(2_000);
});

it('names the most-picked wrong option', function () {
    $quiz = Quiz::factory()->published()->withSpecificQuestions(
        Question::factory()->at(1)->withOptions(['Right', 'Tempting', 'Odd', 'Odder'], 0)
    )->create();

    $question = $quiz->questions()->first();

    // Option 1 picked three times, option 2 once.
    foreach (range(1, 3) as $ignored) {
        analyticsRun($quiz, User::factory()->create(), [
            ['question' => $question, 'correct' => false, 'chosen' => 1],
        ]);
    }

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'chosen' => 2],
    ]);

    $worst = analyticsFor($quiz)['questions'][0]['most_picked_wrong_option'];

    expect($worst['index'])->toBe(1)
        ->and($worst['text'])->toBe('Tempting')
        ->and($worst['picks'])->toBe(3);
});

it('ignores timeouts and focus losses when finding the most-picked wrong option', function () {
    $quiz = Quiz::factory()->published()->withSpecificQuestions(
        Question::factory()->at(1)->withOptions(['Right', 'Wrong', 'Odd', 'Odder'], 0)
    )->create();

    $question = $quiz->questions()->first();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'chosen' => 1],
    ]);

    foreach (range(1, 5) as $ignored) {
        analyticsRun($quiz, User::factory()->create(), [
            ['question' => $question, 'correct' => false, 'reason' => FailureReason::Timeout],
        ]);
    }

    $worst = analyticsFor($quiz)['questions'][0]['most_picked_wrong_option'];

    // Nobody "picked" a timeout.
    expect($worst['index'])->toBe(1)
        ->and($worst['picks'])->toBe(1);
});

it('reports no most-picked wrong option when every answer was correct', function () {
    $quiz = Quiz::factory()->published()->withQuestions(1)->create();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $quiz->questions->first(), 'correct' => true],
    ], passed: true);

    expect(analyticsFor($quiz)['questions'][0]['most_picked_wrong_option'])->toBeNull();
});

it('splits failures into wrong, timeout and focus lost', function () {
    $quiz = Quiz::factory()->published()->withQuestions(1)->create();
    $question = $quiz->questions->first();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'chosen' => 1, 'reason' => FailureReason::WrongAnswer],
    ]);
    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'reason' => FailureReason::Timeout],
    ]);
    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'reason' => FailureReason::Timeout],
    ]);
    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => false, 'reason' => FailureReason::FocusLost],
    ]);

    $failures = analyticsFor($quiz)['questions'][0]['failures'];

    expect($failures)->toBe([
        'wrong_answer' => 1,
        'timeout' => 2,
        'focus_lost' => 1,
    ]);
});

it('lists questions in authoring order, including untouched ones', function () {
    $quiz = Quiz::factory()->published()->withQuestions(4)->create();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $quiz->questions->first(), 'correct' => false, 'chosen' => 1],
    ]);

    $questions = analyticsFor($quiz)['questions'];

    expect($questions)->toHaveCount(4)
        ->and(collect($questions)->pluck('number')->all())->toBe([1, 2, 3, 4])
        ->and($questions[3]['answers'])->toBe(0)
        ->and($questions[3]['correct_rate'])->toBeNull()
        ->and($questions[3]['average_time_ms'])->toBeNull();
});

// -------------------------------------------------------------------- R9.3

it('lists each signed-in player with attempts, best and first pass', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();
    $question = $quiz->questions->first();
    $alice = User::factory()->create(['name' => 'Alice']);

    analyticsRun($quiz, $alice, [['question' => $question, 'correct' => false, 'chosen' => 1]]);
    analyticsRun($quiz, $alice, [['question' => $question, 'correct' => true]], passed: true);

    $players = analyticsFor($quiz)['players'];

    expect($players)->toHaveCount(1)
        ->and($players[0]['name'])->toBe('Alice')
        ->and($players[0]['attempts'])->toBe(2)
        ->and($players[0]['best_reached'])->toBe(1)
        ->and($players[0]['passed'])->toBeTrue()
        ->and($players[0]['first_passed_at'])->not->toBeNull();
});

it('leaves the creator out of the player table', function () {
    $creator = User::factory()->create(['name' => 'Creator']);
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(3)->create();
    $question = $quiz->questions->first();

    analyticsRun($quiz, $creator, [['question' => $question, 'correct' => true]], passed: true);
    analyticsRun($quiz, User::factory()->create(['name' => 'Player']), [
        ['question' => $question, 'correct' => false, 'chosen' => 1],
    ]);

    expect(collect(analyticsFor($quiz)['players'])->pluck('name')->all())->toBe(['Player']);
});

it('groups every guest into one row', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();
    [$first, $second] = $quiz->questions->all();

    // Three separate guests, four runs between them.
    analyticsRun($quiz, null, [['question' => $first, 'correct' => false, 'chosen' => 1]]);
    analyticsRun($quiz, null, [['question' => $first, 'correct' => false, 'chosen' => 1]]);
    analyticsRun($quiz, null, [
        ['question' => $first, 'correct' => true],
        ['question' => $second, 'correct' => false, 'chosen' => 2],
    ]);
    analyticsRun($quiz, null, [['question' => $first, 'correct' => false, 'chosen' => 1]]);

    $guests = analyticsFor($quiz)['guests'];

    expect($guests['name'])->toBe('Guests')
        ->and($guests['attempts'])->toBe(4)
        ->and($guests['players'])->toBe(4)
        ->and($guests['best_reached'])->toBe(1)
        ->and($guests['passes'])->toBe(0);
});

it('omits the guests row entirely when no guest has finished', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $quiz->questions->first(), 'correct' => false, 'chosen' => 1],
    ]);

    expect(analyticsFor($quiz)['guests'])->toBeNull();
});

it('records a guest pass in the grouped row', function () {
    $quiz = Quiz::factory()->published()->withQuestions(1)->create();

    analyticsRun($quiz, null, [
        ['question' => $quiz->questions->first(), 'correct' => true],
    ], passed: true);

    $guests = analyticsFor($quiz)['guests'];

    expect($guests['passes'])->toBe(1)
        ->and($guests['best_reached'])->toBe(1)
        ->and($guests['first_passed_at'])->not->toBeNull();
});

// --------------------------------------------------------------- R9.4, R9.5

it('labels a casual quiz self-reported', function () {
    $creator = User::factory()->create();
    $casual = Quiz::factory()->for($creator)->published()->casual()->withQuestions(5)->create();
    $strict = Quiz::factory()->for($creator)->published()->strict()->withQuestions(5)->create();

    $casualProps = $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $casual))
        ->viewData('page')['props'];

    $strictProps = $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $strict))
        ->viewData('page')['props'];

    expect($casualProps['quiz']['is_self_reported'])->toBeTrue()
        ->and($strictProps['quiz']['is_self_reported'])->toBeFalse();
});

it('keeps analytics fully available after unpublishing', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(2)->create();
    $question = $quiz->questions->first();

    analyticsRun($quiz, User::factory()->create(), [
        ['question' => $question, 'correct' => true],
    ], passed: true);

    $this->actingAs($creator)->post(route('studio.quizzes.unpublish', $quiz));

    $props = $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $quiz))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['quiz']['status'])->toBe('unpublished')
        ->and($props['analytics']['totals']['runs'])->toBe(1)
        ->and($props['analytics']['totals']['passes'])->toBe(1)
        ->and($props['analytics']['players'])->toHaveCount(1);
});

it('serves stats for a draft too', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->withQuestions(3)->create();

    $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $draft))
        ->assertOk();
});

// ----------------------------------------------------------- authorization

it('refuses stats to anyone but the creator', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('studio.quizzes.stats', $quiz))
        ->assertForbidden();
});

it('sends a guest to sign in', function () {
    $quiz = Quiz::factory()->published()->withQuestions(3)->create();

    $this->get(route('studio.quizzes.stats', $quiz))->assertRedirect();
});

it('never leaks a player’s email', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(1)->create();

    analyticsRun($quiz, User::factory()->create(['email' => 'private@example.com']), [
        ['question' => $quiz->questions->first(), 'correct' => false, 'chosen' => 1],
    ]);

    $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $quiz))
        ->assertDontSee('private@example.com');

    $players = $this->actingAs($creator)
        ->get(route('studio.quizzes.stats', $quiz))
        ->viewData('page')['props']['analytics']['players'];

    expect(array_keys($players[0]))->toBe([
        'name',
        'avatar_url',
        'attempts',
        'best_reached',
        'passed',
        'first_passed_at',
        'last_played_at',
    ]);
});
