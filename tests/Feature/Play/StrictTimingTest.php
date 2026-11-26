<?php

declare(strict_types=1);

use App\Enums\FailureReason;
use App\Enums\RunStatus;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\User;

/**
 * Phase 5 gate — R5.3, the four timing points.
 *
 * `grace` is pinned to 1.5s in config, so the boundary for a 5-second timer is
 * 6.5s. The cases below are: in time, past the timer but inside grace, just
 * past grace, and far past.
 */
function strictRunAt(float $secondsAgo, int $timer = 5): array
{
    $creator = User::factory()->create();
    $player = User::factory()->create();

    $quiz = Quiz::factory()
        ->for($creator)
        ->published()
        ->strict()
        ->withQuestions(5)
        ->create(['seconds_per_question' => $timer]);

    $questions = $quiz->questions;

    $run = QuizRun::factory()
        ->for($quiz)
        ->forUser($player)
        ->withOrder($questions->pluck('id')->all())
        ->issuedSecondsAgo($secondsAgo)
        ->create();

    return [$run, $quiz, $player, $questions];
}

/**
 * The displayed position that maps to the correct option for the question the
 * run is currently showing.
 */
function correctDisplayedIndex(QuizRun $run): int
{
    $question = $run->quiz->questions->firstWhere('id', $run->currentQuestionId());
    $permutation = $run->option_orders[(string) $question->id];

    return (int) array_search($question->correct_option_index, $permutation, strict: true);
}

it('accepts a correct answer well inside the timer', function () {
    [$run, , $player] = strictRunAt(1.0);

    $response = $this->actingAs($player)->postJson(
        route('runs.answer', $run),
        ['displayed_index' => correctDisplayedIndex($run)],
    );

    $response->assertOk()->assertJsonPath('verdict', 'correct');

    expect($run->fresh()->status)->toBe(RunStatus::InProgress)
        ->and($run->fresh()->reached)->toBe(1);
});

it('accepts a correct answer that is past the timer but inside grace', function () {
    // 5s timer, 1.5s grace -> 6.0s is late for the player but allowed.
    [$run, , $player] = strictRunAt(6.0);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)])
        ->assertOk()
        ->assertJsonPath('verdict', 'correct');
});

it('counts a correct answer just beyond grace as a timeout', function () {
    [$run, , $player] = strictRunAt(7.0);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)])
        ->assertOk()
        ->assertJsonPath('verdict', 'failed')
        ->assertJsonPath('failure_reason', 'timeout');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_reason)->toBe(FailureReason::Timeout)
        ->and($run->reached)->toBe(0);
});

it('counts a late answer as a timeout whatever was clicked', function () {
    [$run, , $player] = strictRunAt(30.0);

    // Deliberately the correct option. R5.3: "whatever was clicked".
    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)])
        ->assertJsonPath('failure_reason', 'timeout');
});

it('records a timeout with no chosen option', function () {
    [$run, , $player] = strictRunAt(7.0);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)]);

    $answer = $run->answers()->sole();

    expect($answer->chosen_option_index)->toBeNull()
        ->and($answer->is_correct)->toBeFalse()
        ->and($answer->failure_reason)->toBe(FailureReason::Timeout);
});

it('measures time server-side, not from the client', function () {
    [$run, , $player] = strictRunAt(2.0);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)]);

    // ~2000ms, with slack for test execution. The client never sent a number.
    expect($run->answers()->sole()->time_ms)->toBeGreaterThan(1_500)
        ->and($run->answers()->sole()->time_ms)->toBeLessThan(4_000);
});

it('honours a client-reported timeout while still inside the limit', function () {
    [$run, , $player] = strictRunAt(1.0);

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['reason' => 'timeout'])
        ->assertJsonPath('failure_reason', 'timeout');
});

it('reissues the clock on each new question', function () {
    [$run, , $player] = strictRunAt(1.0);
    $before = $run->current_issued_at;

    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)]);

    $run->refresh();

    expect($run->current_index)->toBe(1)
        ->and($run->current_issued_at->getTimestamp())
        ->toBeGreaterThanOrEqual($before->getTimestamp());
});

it('respects a changed grace setting', function () {
    config(['quiz.grace_seconds' => 0.0]);

    [$run, , $player] = strictRunAt(5.5);

    // With no grace, 5.5s on a 5s timer is late.
    $this->actingAs($player)
        ->postJson(route('runs.answer', $run), ['displayed_index' => correctDisplayedIndex($run)])
        ->assertJsonPath('failure_reason', 'timeout');
});
