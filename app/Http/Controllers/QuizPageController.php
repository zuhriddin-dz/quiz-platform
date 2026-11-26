<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizUserStat;
use App\Queries\SuggestedQuizzes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /q/{ulid} — the quiz page. R7.3, R7.4, R7.5.
 *
 * Also the share link for unlisted quizzes, and the host of the play loop
 * from Phases 5 and 6.
 *
 * Three audiences, three outcomes:
 *   - published            -> everyone, guests included
 *   - unpublished, not you -> the page renders "no longer available" (R4.4),
 *                             NOT a 403: the player may well have it in their
 *                             history and deserves an explanation
 *   - draft, not you       -> 403. A draft was never shared with anyone.
 */
final class QuizPageController
{
    public function show(Request $request, Quiz $quiz, SuggestedQuizzes $suggestedQuizzes): Response
    {
        $user = $request->user();
        $isCreator = $quiz->isOwnedBy($user);

        // R4.4 — the unavailable state. Only the title goes out, which is no
        // more than My plays already shows for the same quiz.
        if (! $quiz->status->isPubliclyPlayable() && ! $isCreator) {
            abort_unless($quiz->status->value === 'unpublished', 403);

            return Inertia::render('Quiz', [
                'unavailable' => true,
                'quiz' => ['title' => $quiz->title],
            ]);
        }

        $quiz->loadCount('questions');
        $quiz->load('creator');

        return Inertia::render('Quiz', [
            'unavailable' => false,
            'quiz' => [
                'ulid' => $quiz->ulid,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'creator' => [
                    'name' => $quiz->creator->name,
                    'avatar_url' => $quiz->creator->avatar_url,
                ],
                'mode' => $quiz->mode->value,
                'mode_label' => $quiz->mode->label(),
                'seconds_per_question' => $quiz->seconds_per_question,
                'question_count' => $quiz->questions_count,
                'is_playable' => $quiz->questions_count > 0,
                'upvotes_count' => $quiz->upvotes_count,
                'runs_count' => $quiz->runs_count,
                // Visible to the creator so a draft or unpublished quiz does
                // not look broken when they open it.
                'status' => $quiz->status->value,
                'is_creator' => $isCreator,
            ],

            /*
             * R7.4 — public stats, from the cached counters, which already
             * exclude the creator's own runs (R5.11).
             *
             * Below 10 counted runs both are null and the page says "Not
             * enough plays yet" instead. There is no difficulty label.
             */
            'stats' => [
                'has_enough_runs' => $quiz->hasEnoughRuns(),
                'minimum_runs' => Quiz::MINIMUM_COUNTED_RUNS,
                'pass_rate' => $quiz->passRate(),
                'average_reached' => $quiz->averageReached(),
                'runs_count' => $quiz->runs_count,
            ],

            // R7.5 — signed-in only. A guest has no stats row at all, which
            // is why their history cannot be migrated at signup.
            'your_stats' => $this->yourStats($user?->id, $quiz->id),

            // R8.1 — signed in, published and listed, not your own.
            'can_vote' => $user !== null && $user->can('vote', $quiz),
            'has_voted' => $user !== null && $quiz->votes()->where('user_id', $user->id)->exists(),

            /*
             * R8.3 — one mixed list of up to 12, computed on page load with no
             * background job. Guests get suggestions too; they simply have no
             * passed-quiz history to exclude.
             */
            'suggestions' => $suggestedQuizzes->for($quiz, $user),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function yourStats(?int $userId, int $quizId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $stat = QuizUserStat::query()
            ->where('user_id', $userId)
            ->where('quiz_id', $quizId)
            ->first();

        if ($stat === null) {
            return null;
        }

        return [
            'attempts' => $stat->attempts,
            'best_reached' => $stat->best_reached,
            'passed' => $stat->passed,
            'first_passed_at' => $stat->first_passed_at?->toDateString(),
        ];
    }
}
