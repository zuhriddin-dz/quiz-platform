<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

/**
 * R4.4 — unpublish.
 *
 * Note what this does NOT do: it does not close other players' in-progress
 * runs. The spec says they are "closed as abandoned on their next request",
 * which is CloseStaleRuns' job. Closing them here would be easy and would
 * also be a different behaviour — a player mid-question would have their run
 * killed before they could submit the answer they were already typing.
 *
 * Everything else about an unpublished quiz is a read-side rule and needs no
 * writes here:
 *   - non-creators see "no longer available"  -> QuizPolicy::view (Phase 7)
 *   - history shows "Unavailable", no replay  -> My plays (Phase 7)
 *   - creator analytics stay available        -> QuizPolicy::viewStats
 *   - counters and stats rows are untouched, so nothing is lost
 */
final class UnpublishQuiz
{
    public function handle(Quiz $quiz): Quiz
    {
        return DB::transaction(function () use ($quiz): Quiz {
            $quiz->status = QuizStatus::Unpublished;

            // `is_listed` is deliberately left as it was. It carries no
            // meaning while unpublished, and the creator chooses it afresh on
            // the next publish (R4.3).
            $quiz->save();

            return $quiz;
        });
    }
}
