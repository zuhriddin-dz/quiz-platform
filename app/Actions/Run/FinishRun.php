<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Enums\FailureReason;
use App\Enums\RunStatus;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use Illuminate\Support\Facades\DB;

/**
 * R5.11 — end-of-run bookkeeping, in one transaction.
 *
 * Three writes that must all land or none:
 *   1. the run's own status, reason, reached and finished_at
 *   2. the quiz's cached counters — but only if the runner is NOT the creator
 *   3. a quiz_user_stats upsert — only for signed-in players
 *
 * The creator exclusion is the subtle one. A creator test-playing their own
 * quiz would otherwise poison the public pass rate and the average reached,
 * and they are the one person guaranteed to know the answers.
 *
 * Casual mode reuses this unchanged (Phase 6): the bookkeeping does not care
 * how correctness was decided.
 */
final class FinishRun
{
    /**
     * @return array{attempt: int|null, best_reached: int|null, passed: bool|null}
     */
    public function pass(QuizRun $run): array
    {
        return $this->finish($run, RunStatus::Passed, null);
    }

    /**
     * @return array{attempt: int|null, best_reached: int|null, passed: bool|null}
     */
    public function fail(QuizRun $run, ?FailureReason $reason): array
    {
        return $this->finish($run, RunStatus::Failed, $reason);
    }

    /**
     * @return array{attempt: int|null, best_reached: int|null, passed: bool|null}
     */
    private function finish(QuizRun $run, RunStatus $status, ?FailureReason $reason): array
    {
        return DB::transaction(function () use ($run, $status, $reason): array {
            // ---------------------------------------------------------- 1
            $run->status = $status;
            $run->failure_reason = $reason;
            $run->finished_at = now();
            // A finished run has no live question.
            $run->current_issued_at = null;
            $run->save();

            $quiz = $run->quiz;
            $isCreatorsOwnRun = $run->user_id !== null && $run->user_id === $quiz->user_id;

            // ---------------------------------------------------------- 2
            if (! $isCreatorsOwnRun) {
                $quiz->increment('runs_count');
                $quiz->increment('reached_sum', $run->reached);

                if ($status === RunStatus::Passed) {
                    $quiz->increment('passes_count');
                }
            }

            // ---------------------------------------------------------- 3
            // Guests have no stats row, which is why their history cannot be
            // migrated at signup — there is nowhere to move it to.
            if ($run->user_id === null) {
                return ['attempt' => null, 'best_reached' => null, 'passed' => null];
            }

            $stat = QuizUserStat::firstOrNew([
                'user_id' => $run->user_id,
                'quiz_id' => $run->quiz_id,
            ]);

            $stat->attempts = ($stat->attempts ?? 0) + 1;
            $stat->best_reached = max($stat->best_reached ?? 0, $run->reached);
            $stat->last_played_at = now();

            if ($status === RunStatus::Passed) {
                // `first_passed_at` is set once and never moved, so "first
                // passed" stays honest after a later replay.
                $stat->first_passed_at ??= now();
                $stat->passed = true;
            }

            $stat->save();

            return [
                'attempt' => $stat->attempts,
                'best_reached' => $stat->best_reached,
                'passed' => $stat->passed,
            ];
        });
    }
}
