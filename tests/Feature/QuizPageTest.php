<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use App\Models\User;

/**
 * Phase 7 gate — R7.3, R7.4, R7.5, plus R4.4's unavailable state.
 */
function quizPageProps(Quiz $quiz, ?User $as = null): array
{
    $request = $as === null ? test() : test()->actingAs($as);

    return $request->get(route('quiz.show', $quiz))
        ->assertOk()
        ->viewData('page')['props'];
}

// -------------------------------------------------------------------- R7.3

it('shows the quiz details to a guest', function () {
    $creator = User::factory()->create(['name' => 'Amina']);

    $quiz = Quiz::factory()
        ->for($creator)
        ->published()
        ->strict()
        ->withQuestions(6)
        ->withUpvotes(4)
        ->withStats(runs: 20, passes: 5)
        ->create(['title' => 'Phrasal verbs', 'description' => 'The awkward ones.', 'seconds_per_question' => 7]);

    $props = quizPageProps($quiz);

    expect($props['quiz'])->toMatchArray([
        'title' => 'Phrasal verbs',
        'description' => 'The awkward ones.',
        'mode' => 'strict',
        'seconds_per_question' => 7,
        'question_count' => 6,
        'upvotes_count' => 4,
        'runs_count' => 20,
        'is_playable' => true,
    ])->and($props['quiz']['creator']['name'])->toBe('Amina');
});

it('is reachable by ULID for an unlisted quiz', function () {
    $quiz = Quiz::factory()->published()->unlisted()->withQuestions(5)->create();

    expect(quizPageProps($quiz)['quiz']['ulid'])->toBe($quiz->ulid);
});

it('sends no questions or answers to the page', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    $props = quizPageProps($quiz);

    expect($props['quiz'])->not->toHaveKey('questions')
        ->and(json_encode($props))->not->toContain('correct_option_index');
});

// -------------------------------------------------------------------- R7.4

it('hides public stats below ten counted runs', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->withStats(runs: 9, passes: 4)->create();

    $stats = quizPageProps($quiz)['stats'];

    expect($stats['has_enough_runs'])->toBeFalse()
        ->and($stats['pass_rate'])->toBeNull()
        ->and($stats['average_reached'])->toBeNull()
        ->and($stats['minimum_runs'])->toBe(10);
});

it('shows public stats from ten counted runs', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)
        ->withStats(runs: 20, passes: 5, reachedSum: 48)
        ->create();

    $stats = quizPageProps($quiz)['stats'];

    expect($stats['has_enough_runs'])->toBeTrue()
        ->and($stats['pass_rate'])->toBe(0.25)
        ->and($stats['average_reached'])->toBe(2.4);
});

it('excludes the creator’s own runs from public stats', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(5)->create();

    // Ten real plays, then ten by the creator.
    foreach (range(1, 10) as $ignored) {
        $run = QuizRun::factory()->for($quiz)->forUser(User::factory()->create())->create();
        $run->reached = 2;
        app(App\Actions\Run\FinishRun::class)->fail($run, App\Enums\FailureReason::WrongAnswer);
    }

    foreach (range(1, 10) as $ignored) {
        $run = QuizRun::factory()->for($quiz)->forUser($creator)->create();
        $run->reached = 5;
        app(App\Actions\Run\FinishRun::class)->pass($run);
    }

    $stats = quizPageProps($quiz->fresh())['stats'];

    // Only the ten non-creator runs count, none of which passed.
    expect($stats['runs_count'])->toBe(10)
        ->and($stats['pass_rate'])->toBe(0.0)
        ->and($stats['average_reached'])->toBe(2.0);
});

it('never sends a difficulty label', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->withStats(runs: 30, passes: 3)->create();

    expect(quizPageProps($quiz)['stats'])->not->toHaveKey('difficulty');
});

// -------------------------------------------------------------------- R7.5

it('sends no personal stats to a guest', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    expect(quizPageProps($quiz)['your_stats'])->toBeNull();
});

it('sends no personal stats to a user who has not played', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    expect(quizPageProps($quiz, User::factory()->create())['your_stats'])->toBeNull();
});

it('sends attempts, best and passed to a user who has played', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();
    $player = User::factory()->create();

    QuizUserStat::factory()->for($player)->for($quiz)->passed(4)->create(['attempts' => 6]);

    $yours = quizPageProps($quiz, $player)['your_stats'];

    expect($yours['attempts'])->toBe(6)
        ->and($yours['best_reached'])->toBe(4)
        ->and($yours['passed'])->toBeTrue()
        ->and($yours['first_passed_at'])->not->toBeNull();
});

// ------------------------------------------------------------- vote affordance

it('offers the vote button to a signed-in non-creator on a listed quiz', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    expect(quizPageProps($quiz, User::factory()->create())['can_vote'])->toBeTrue();
});

it('withholds it from the creator, from guests and on unlisted quizzes', function () {
    $creator = User::factory()->create();
    $listed = Quiz::factory()->for($creator)->published()->withQuestions(5)->create();
    $unlisted = Quiz::factory()->published()->unlisted()->withQuestions(5)->create();

    expect(quizPageProps($listed, $creator)['can_vote'])->toBeFalse()
        ->and(quizPageProps($listed)['can_vote'])->toBeFalse()
        ->and(quizPageProps($unlisted, User::factory()->create())['can_vote'])->toBeFalse();
});

// -------------------------------------------------------------------- R4.4

it('shows "no longer available" for an unpublished quiz', function () {
    $quiz = Quiz::factory()->unpublished()->withQuestions(5)->create(['title' => 'Gone']);

    $props = quizPageProps($quiz, User::factory()->create());

    expect($props['unavailable'])->toBeTrue()
        ->and($props['quiz']['title'])->toBe('Gone')
        // Nothing else leaks: no questions, no stats, no play payload.
        ->and(array_keys($props['quiz']))->toBe(['title']);
});

it('shows the unavailable state to guests too', function () {
    $quiz = Quiz::factory()->unpublished()->withQuestions(5)->create();

    expect(quizPageProps($quiz)['unavailable'])->toBeTrue();
});

it('still shows the full page to the creator of an unpublished quiz', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->unpublished()->withQuestions(5)->create();

    $props = quizPageProps($quiz, $creator);

    expect($props['unavailable'])->toBeFalse()
        ->and($props['quiz']['status'])->toBe('unpublished')
        ->and($props['quiz']['is_creator'])->toBeTrue();
});

it('refuses a draft to anyone but its creator', function () {
    $quiz = Quiz::factory()->withQuestions(5)->create();

    $this->get(route('quiz.show', $quiz))->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->get(route('quiz.show', $quiz))
        ->assertForbidden();
});

it('lets the creator open their own draft', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->withQuestions(5)->create();

    expect(quizPageProps($quiz, $creator)['quiz']['status'])->toBe('draft');
});
