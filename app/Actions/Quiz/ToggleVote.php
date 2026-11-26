<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Quiz;
use App\Models\QuizVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * R8.1, R8.2 — toggle an upvote.
 *
 * The cached count is set from the ROWS inside the same transaction, rather
 * than incremented or decremented:
 *
 *   - It is what R8.2 asks for ("updated in the same transaction as the vote
 *     row"), and it makes the two literally unable to disagree.
 *   - An increment/decrement pair drifts. A lost decrement leaves a count
 *     above the row count forever, and `max(x - 1, 0)` is not portable
 *     (Postgres spells it `greatest`, SQLite `max`), so clamping in SQL was
 *     not an option either.
 *   - A COUNT over `quiz_votes (quiz_id)` is indexed and cheap.
 *
 * So the count is derived, not accumulated, and a drifted column heals itself
 * the next time anyone votes.
 */
final class ToggleVote
{
    /**
     * @return bool true if the user has now voted, false if the vote was removed
     */
    public function handle(User $user, Quiz $quiz): bool
    {
        return DB::transaction(function () use ($user, $quiz): bool {
            $existing = QuizVote::query()
                ->where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->first();

            if ($existing !== null) {
                $existing->delete();
                $voted = false;
            } else {
                // The unique (user_id, quiz_id) index is the real guarantee
                // here: two simultaneous clicks cannot both insert.
                QuizVote::create([
                    'user_id' => $user->id,
                    'quiz_id' => $quiz->id,
                ]);

                $voted = true;
            }

            // Derived from the rows, in this transaction.
            $quiz->upvotes_count = $quiz->votes()->count();
            $quiz->save();

            return $voted;
        });
    }
}
