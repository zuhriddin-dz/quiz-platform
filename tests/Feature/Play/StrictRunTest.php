<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\RunStatus;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\User;
use App\Support\GuestToken;

/**
 * Phase 5 gate — R5.1, R5.4, R5.5, R5.6, R5.12.
 */
function playableQuiz(int $questions = 5, int $timer = 5): Quiz
{
    return Quiz::factory()
        ->published()
        ->strict()
        ->withQuestions($questions)
        ->create(['seconds_per_question' => $timer]);
}

// -------------------------------------------------------------------- R5.1

it('starts a run and returns only the first question', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $response = $this->actingAs($player)
        ->postJson(route('runs.store', $quiz))
        ->assertCreated();

    $response->assertJsonPath('run.question_count', 5)
        ->assertJsonPath('run.seconds_per_question', 5)
        ->assertJsonPath('question.number', 1)
        ->assertJsonCount(4, 'question.options');

    // One question, not five.
    expect($response->json('question'))->toHaveKeys(['index', 'number', 'prompt', 'options']);

    $run = QuizRun::sole();

    expect($run->question_order)->toHaveCount(5)
        ->and($run->option_orders)->toHaveCount(5)
        ->and($run->current_index)->toBe(0)
        ->and($run->current_issued_at)->not->toBeNull()
        ->and($run->status)->toBe(RunStatus::InProgress);
});

// -------------------------------------------------------------------- R5.5

it('never sends answer data in the start payload', function () {
    $quiz = playableQuiz();

    $json = $this->actingAs(User::factory()->create())
        ->postJson(route('runs.store', $quiz))
        ->json();

    $flat = json_encode($json);

    expect($flat)->not->toContain('correct_option_index')
        ->not->toContain('answer_hash')
        ->not->toContain('correct');

    // And nothing resembling the stored order leaks either.
    expect($json['question'])->not->toHaveKey('option_orders')
        ->and($json['run'])->not->toHaveKey('question_order');
});

it('never sends answer data in a verdict', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $started = $this->actingAs($player)->postJson(route('runs.store', $quiz))->json();
    $run = QuizRun::sole();

    // Answer wrongly on purpose: the fail payload is where a leak would hurt.
    $question = $quiz->questions->firstWhere('id', $run->currentQuestionId());
    $permutation = $run->option_orders[(string) $question->id];
    $correctDisplayed = (int) array_search($question->correct_option_index, $permutation, strict: true);
    $wrongDisplayed = ($correctDisplayed + 1) % 4;

    $json = $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => $wrongDisplayed])
        ->assertJsonPath('verdict', 'failed')
        ->json();

    expect($json)->not->toHaveKey('correct_option_index')
        ->and($json)->not->toHaveKey('correct_displayed_index')
        ->and($json['chosen_displayed_index'])->toBe($wrongDisplayed);
});

it('sends one question at a time, never ahead', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $question = $quiz->questions->firstWhere('id', $run->currentQuestionId());
    $permutation = $run->option_orders[(string) $question->id];
    $correct = (int) array_search($question->correct_option_index, $permutation, strict: true);

    $json = $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => $correct])
        ->json();

    // Exactly one `question` key, which is the NEXT one.
    expect($json['question']['number'])->toBe(2)
        ->and($json)->not->toHaveKey('questions');
});

// -------------------------------------------------------------------- R5.1(2)

it('abandons the player’s previous run on the same quiz', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $first = QuizRun::sole();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    expect($first->fresh()->status)->toBe(RunStatus::Abandoned)
        ->and(QuizRun::where('status', RunStatus::InProgress)->count())->toBe(1);
});

it('leaves another player’s run alone when starting', function () {
    $quiz = playableQuiz();
    $other = QuizRun::factory()->for($quiz)->create();

    $this->actingAs(User::factory()->create())->postJson(route('runs.store', $quiz));

    expect($other->fresh()->status)->toBe(RunStatus::InProgress);
});

it('leaves the player’s run on a different quiz alone', function () {
    $player = User::factory()->create();
    $elsewhere = QuizRun::factory()->forUser($player)->create();

    $this->actingAs($player)->postJson(route('runs.store', playableQuiz()));

    expect($elsewhere->fresh()->status)->toBe(RunStatus::InProgress);
});

// ---------------------------------------------------------------- R5.4, R5.6

it('passes a run only when every question is correct', function () {
    $quiz = playableQuiz(5);
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    for ($step = 1; $step <= 5; $step++) {
        $run->refresh();
        $question = $quiz->questions->firstWhere('id', $run->currentQuestionId());
        $permutation = $run->option_orders[(string) $question->id];
        $correct = (int) array_search($question->correct_option_index, $permutation, strict: true);

        $response = $this->actingAs($player)
            ->postJson(route('runs.answer', $run), ['displayed_index' => $correct]);

        $response->assertJsonPath('verdict', $step === 5 ? 'passed' : 'correct');
    }

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Passed)
        ->and($run->reached)->toBe(5)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->current_issued_at)->toBeNull()
        ->and($run->answers)->toHaveCount(5);
});

it('ends the run immediately on a wrong answer', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $question = $quiz->questions->firstWhere('id', $run->currentQuestionId());
    $permutation = $run->option_orders[(string) $question->id];
    $correct = (int) array_search($question->correct_option_index, $permutation, strict: true);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => ($correct + 1) % 4])
        ->assertJsonPath('verdict', 'failed')
        ->assertJsonPath('failure_reason', 'wrong_answer');

    expect($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($run->fresh()->failure_reason)->toBe(FailureReason::WrongAnswer);
});

it('ends the run on a reported focus loss', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['reason' => 'focus_lost'])
        ->assertJsonPath('failure_reason', 'focus_lost');

    expect($run->fresh()->failure_reason)->toBe(FailureReason::FocusLost)
        ->and($run->answers()->sole()->chosen_option_index)->toBeNull();
});

it('refuses a second answer on a finished run', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));
    $run = QuizRun::sole();

    $this->actingAs($player)->postJson(route('runs.answer', $run), ['reason' => 'focus_lost']);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['reason' => 'timeout'])
        ->assertStatus(409);

    expect($run->fresh()->answers)->toHaveCount(1);
});

// -------------------------------------------------------------- request shape

it('rejects a body with both an index and a reason', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.answer', QuizRun::sole()), [
            'displayed_index' => 0,
            'reason' => 'timeout',
        ])
        ->assertStatus(422);
});

it('rejects an empty body', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.answer', QuizRun::sole()), [])
        ->assertStatus(422);
});

it('does not let a client declare itself wrong', function () {
    $quiz = playableQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz));

    $this->actingAs($player)
        ->postJson(route('runs.answer', QuizRun::sole()), ['reason' => 'wrong_answer'])
        ->assertStatus(422);
});

// ------------------------------------------------------------------- R5.12

it('refuses an answer from a different user', function () {
    $quiz = playableQuiz();
    $owner = User::factory()->create();

    $this->actingAs($owner)->postJson(route('runs.store', $quiz));

    $this->actingAs(User::factory()->create())
        ->postJson(route('runs.answer', QuizRun::sole()), ['reason' => 'timeout'])
        ->assertForbidden();
});

it('refuses an answer from a guest on a signed-in player’s run', function () {
    $quiz = playableQuiz();

    $this->actingAs(User::factory()->create())->postJson(route('runs.store', $quiz));

    $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
        ->postJson(route('runs.answer', QuizRun::sole()), ['reason' => 'timeout'])
        ->assertForbidden();
});

it('refuses an answer from a guest with a different token', function () {
    $quiz = playableQuiz();

    $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
        ->postJson(route('runs.store', $quiz));

    $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
        ->postJson(route('runs.answer', QuizRun::sole()), ['reason' => 'timeout'])
        ->assertForbidden();
});

// ------------------------------------------------------------------ playing

it('lets a guest play a published quiz', function () {
    $token = GuestToken::generate();

    $this->withUnencryptedCookie(GuestToken::COOKIE, $token)
        ->postJson(route('runs.store', playableQuiz()))
        ->assertCreated();

    $run = QuizRun::sole();

    expect($run->user_id)->toBeNull()
        ->and($run->guest_token)->toBe($token);
});

it('lets a guest play an unlisted published quiz', function () {
    $quiz = Quiz::factory()->published()->unlisted()->strict()->withQuestions(5)->create();

    $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
        ->postJson(route('runs.store', $quiz))
        ->assertCreated();
});

it('refuses to start a run on someone else’s draft', function () {
    $draft = Quiz::factory()->strict()->withQuestions(5)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('runs.store', $draft))
        ->assertForbidden();
});

it('lets the creator test-play their own draft', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->strict()->withQuestions(5)->create();

    $this->actingAs($creator)
        ->postJson(route('runs.store', $draft))
        ->assertCreated();
});

it('refuses to start a run on a quiz with no questions', function () {
    $creator = User::factory()->create();
    $empty = Quiz::factory()->for($creator)->strict()->create();

    $this->actingAs($creator)
        ->postJson(route('runs.store', $empty))
        ->assertStatus(422);
});

it('shuffles: two runs rarely share a question order', function () {
    $quiz = playableQuiz(8);
    $player = User::factory()->create();

    $orders = collect(range(1, 6))->map(function () use ($quiz, $player): string {
        $this->actingAs($player)->postJson(route('runs.store', $quiz));

        return implode(',', QuizRun::latest('started_at')->first()->question_order);
    });

    // Six identical orders out of 8! possibilities would mean no shuffle.
    expect($orders->unique()->count())->toBeGreaterThan(1);
});
