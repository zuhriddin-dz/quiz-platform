<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\QuizStatus;
use App\Enums\RunStatus;
use App\Models\QuizRun;
use App\Support\GuestToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * R4.4 — "Other players' runs still in progress are closed as abandoned on
 * their next request."
 *
 * Lazy rather than eager, exactly as the spec words it. Unpublishing does not
 * reach into other players' sessions; the next thing they do closes the run.
 *
 * Two conditions, both necessary:
 *   - the quiz is no longer published — a draft or an unpublished quiz
 *   - the quiz is not the player's own — a creator test-playing their own
 *     draft must not have their run killed, and drafts are playable by their
 *     creator by design
 *
 * Cost: one indexed lookup per request for a signed-in user or a guest with a
 * token. `quiz_runs (quiz_id, user_id, status)` and
 * `quiz_runs (quiz_id, guest_token, status)` exist for exactly this, and the
 * UPDATE only runs when something is actually stale.
 */
final class CloseStaleRuns
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->close($request);

        return $next($request);
    }

    private function close(Request $request): void
    {
        $user = $request->user();
        $guestToken = GuestToken::fromRequest($request);

        if ($user === null && $guestToken === null) {
            return;
        }

        $staleIds = QuizRun::query()
            ->select('quiz_runs.id')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_runs.quiz_id')
            ->where('quiz_runs.status', RunStatus::InProgress->value)
            ->where('quizzes.status', '!=', QuizStatus::Published->value)
            ->when(
                $user !== null,
                fn ($query) => $query
                    ->where('quiz_runs.user_id', $user->id)
                    // Never the creator's own test runs.
                    ->where('quizzes.user_id', '!=', $user->id),
                fn ($query) => $query->where('quiz_runs.guest_token', $guestToken),
            )
            ->pluck('quiz_runs.id');

        if ($staleIds->isEmpty()) {
            return;
        }

        // A mass update on purpose: no model events, no per-row hydration, and
        // the exactly-one-owner guard has nothing to check here since neither
        // owner column is touched.
        QuizRun::whereKey($staleIds)->update([
            'status' => RunStatus::Abandoned->value,
            'finished_at' => now(),
            // The strict cursor is meaningless once a run is closed.
            'current_issued_at' => null,
        ]);
    }
}
