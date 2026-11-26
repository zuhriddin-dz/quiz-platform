<?php

declare(strict_types=1);

use App\Actions\Run\FinishRun;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use App\Models\User;

/**
 * Phase 5 gate — R5.11, and the creator-exclusion half of R7.4.
 *
 * These drive FinishRun directly: the HTTP path is covered by StrictRunTest,
 * and the bookkeeping is easier to pin down without playing five questions.
 */
function finishedRun(Quiz $quiz, ?User $player, int $reached, bool $passed): QuizRun
{
    $run = $player === null
        ? QuizRun::factory()->for($quiz)->forGuest()->create()
        : QuizRun::factory()->for($quiz)->forUser($player)->create();

    $run->reached = $reached;

    $passed
        ? app(FinishRun::class)->pass($run)
        : app(FinishRun::class)->fail($run, App\Enums\FailureReason::WrongAnswer);

    return $run->fresh();
}

it('increments the quiz counters on a finished run', function () {
    $quiz = Quiz::factory()->published()->create();

    finishedRun($quiz, User::factory()->create(), reached: 3, passed: false);

    $quiz->refresh();

    expect($quiz->runs_count)->toBe(1)
        ->and($quiz->reached_sum)->toBe(3)
        ->and($quiz->passes_count)->toBe(0);
});

it('increments passes_count on a pass', function () {
    $quiz = Quiz::factory()->published()->create();

    finishedRun($quiz, User::factory()->create(), reached: 5, passed: true);

    $quiz->refresh();

    expect($quiz->runs_count)->toBe(1)
        ->and($quiz->passes_count)->toBe(1)
        ->and($quiz->reached_sum)->toBe(5);
});

it('excludes the creator’s own run from the counters', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();

    finishedRun($quiz, $creator, reached: 5, passed: true);

    $quiz->refresh();

    expect($quiz->runs_count)->toBe(0)
        ->and($quiz->passes_count)->toBe(0)
        ->and($quiz->reached_sum)->toBe(0);
});

it('still records the creator’s own run and their stats row', function () {
    // Excluded from PUBLIC counters, not erased: the creator still sees their
    // own attempts and best result.
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();

    finishedRun($quiz, $creator, reached: 4, passed: false);

    expect(QuizRun::count())->toBe(1)
        ->and(QuizUserStat::where('user_id', $creator->id)->sole()->attempts)->toBe(1);
});

it('counts a guest run toward the public counters', function () {
    $quiz = Quiz::factory()->published()->create();

    finishedRun($quiz, null, reached: 2, passed: false);

    expect($quiz->fresh()->runs_count)->toBe(1)
        ->and($quiz->fresh()->reached_sum)->toBe(2);
});

it('creates no stats row for a guest', function () {
    // Which is exactly why guest history cannot be migrated at signup.
    $quiz = Quiz::factory()->published()->create();

    finishedRun($quiz, null, reached: 2, passed: false);

    expect(QuizUserStat::count())->toBe(0);
});

it('upserts one stats row per player per quiz', function () {
    $quiz = Quiz::factory()->published()->create();
    $player = User::factory()->create();

    finishedRun($quiz, $player, reached: 2, passed: false);
    finishedRun($quiz, $player, reached: 4, passed: false);
    finishedRun($quiz, $player, reached: 1, passed: false);

    $stat = QuizUserStat::sole();

    expect($stat->attempts)->toBe(3)
        // A running maximum, not the latest.
        ->and($stat->best_reached)->toBe(4)
        ->and($stat->passed)->toBeFalse()
        ->and($stat->first_passed_at)->toBeNull();
});

it('sets first_passed_at once and never moves it', function () {
    $quiz = Quiz::factory()->published()->create();
    $player = User::factory()->create();

    finishedRun($quiz, $player, reached: 5, passed: true);
    $firstPass = QuizUserStat::sole()->first_passed_at;

    $this->travel(2)->hours();

    finishedRun($quiz, $player, reached: 5, passed: true);

    expect(QuizUserStat::sole()->first_passed_at->timestamp)->toBe($firstPass->timestamp)
        ->and(QuizUserStat::sole()->attempts)->toBe(2);
});

it('keeps passed true after a later failed attempt', function () {
    $quiz = Quiz::factory()->published()->create();
    $player = User::factory()->create();

    finishedRun($quiz, $player, reached: 5, passed: true);
    finishedRun($quiz, $player, reached: 1, passed: false);

    expect(QuizUserStat::sole()->passed)->toBeTrue()
        ->and(QuizUserStat::sole()->best_reached)->toBe(5);
});

it('closes the run and clears the live cursor', function () {
    $quiz = Quiz::factory()->published()->create();

    $run = finishedRun($quiz, User::factory()->create(), reached: 3, passed: false);

    expect($run->status->isFinished())->toBeTrue()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->current_issued_at)->toBeNull()
        ->and($run->failure_reason)->toBe(App\Enums\FailureReason::WrongAnswer);
});

it('returns the stats the result screen needs', function () {
    $quiz = Quiz::factory()->published()->create();
    $player = User::factory()->create();

    $run = QuizRun::factory()->for($quiz)->forUser($player)->create();
    $run->reached = 4;

    $stats = app(FinishRun::class)->fail($run, App\Enums\FailureReason::Timeout);

    expect($stats['attempt'])->toBe(1)
        ->and($stats['best_reached'])->toBe(4)
        ->and($stats['passed'])->toBeFalse();
});

it('returns nulls for a guest, so the result screen hides the stats line', function () {
    $run = QuizRun::factory()->for(Quiz::factory()->published()->create())->forGuest()->create();
    $run->reached = 1;

    $stats = app(FinishRun::class)->fail($run, App\Enums\FailureReason::Timeout);

    expect($stats['attempt'])->toBeNull()
        ->and($stats['best_reached'])->toBeNull()
        ->and($stats['passed'])->toBeNull();
});

it('leaves averages derivable from the counters', function () {
    $quiz = Quiz::factory()->published()->create();

    // 10 runs so the public floor is cleared: 3 passes, 20 reached in total.
    foreach ([5, 5, 5, 2, 2, 2, 1, 1, 1, 0] as $index => $reached) {
        finishedRun($quiz, User::factory()->create(), $reached, passed: $index < 3);
    }

    $quiz->refresh();

    expect($quiz->runs_count)->toBe(10)
        ->and($quiz->passes_count)->toBe(3)
        ->and($quiz->reached_sum)->toBe(24)
        ->and($quiz->passRate())->toBe(0.3)
        ->and($quiz->averageReached())->toBe(2.4);
});
