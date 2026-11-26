<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use App\Models\QuizUserStat;
use App\Models\User;
use App\Support\QuizCardPayload;
use Illuminate\Support\Collection;

/**
 * R8.3 to R8.7 — the suggestions list.
 *
 * ONE SQL query selects the candidates; the score is then computed in PHP.
 * That split is deliberate, and §2 of the spec explicitly allows it
 * ("log10 and abs must work on SQLite too, or the score term is computed in
 * PHP"). Three reasons it is the right half to move:
 *
 *   1. `log10()` in SQLite is a COMPILE-TIME option (SQLITE_ENABLE_MATH_FUNCTIONS,
 *      3.35+). PHP's bundled SQLite may or may not have it, so a SQL score
 *      would pass on Postgres and fail in CI — or, worse, differ silently.
 *      `abs()` is core in both, but it is not the one that bites.
 *   2. R8.6's cap of 3 per creator needs a window function to express in SQL
 *      anyway, so something had to happen after the rows came back.
 *   3. The expensive part of the work is the FILTER — the anti-join against
 *      quizzes the viewer has passed — and that stays in SQL where it belongs.
 *
 * The candidate fetch is bounded (`candidate_limit`), and same-creator quizzes
 * are ordered FIRST so the author bonus can never be cut off by that bound.
 * If the catalogue ever outgrows this, the fix is a SQL score with `log10`
 * registered as a custom SQLite function — see docs/phase-8.md.
 */
final class SuggestedQuizzes
{
    /**
     * @return array<int, array<string, mixed>> card payloads, best first
     */
    public function for(Quiz $current, ?User $viewer): array
    {
        $candidates = $this->candidates($current, $viewer);

        if ($candidates->isEmpty()) {
            return [];
        }

        $weights = config('quiz.suggestions.weights');
        $neutral = (float) config('quiz.suggestions.neutral_stats_term');

        return $candidates
            ->map(fn (Quiz $candidate): array => [
                'quiz' => $candidate,
                'score' => $this->score($current, $candidate, $weights, $neutral),
            ])
            // Ties broken by upvotes then id, so the list is deterministic —
            // otherwise two equally scored quizzes could swap on each reload.
            ->sortByDesc(fn (array $row): array => [
                $row['score'],
                $row['quiz']->upvotes_count,
                $row['quiz']->id,
            ])
            ->values()
            ->pipe(fn (Collection $scored): Collection => $this->capPerCreator($scored))
            ->take((int) config('quiz.suggestions.limit'))
            ->map(fn (array $row): array => QuizCardPayload::from($row['quiz']))
            ->values()
            ->all();
    }

    /**
     * R8.3 — published, listed, not this quiz, and not already passed by the
     * viewer.
     *
     * @return Collection<int, Quiz>
     */
    private function candidates(Quiz $current, ?User $viewer): Collection
    {
        return Quiz::query()
            ->where('status', QuizStatus::Published->value)
            ->where('is_listed', true)
            ->whereKeyNot($current->getKey())
            ->when($viewer !== null, function ($query) use ($viewer): void {
                // "that the viewer has not passed" — an anti-join, not a
                // post-filter, so a prolific player's own history does not
                // quietly shrink the candidate set below the limit.
                $query->whereNotExists(function ($sub) use ($viewer): void {
                    $sub->selectRaw('1')
                        ->from('quiz_user_stats')
                        ->whereColumn('quiz_user_stats.quiz_id', 'quizzes.id')
                        ->where('quiz_user_stats.user_id', $viewer->id)
                        ->where('quiz_user_stats.passed', true);
                });
            })
            ->with('creator')
            ->withCount('questions')
            // Same creator first, so `candidate_limit` can never cut off a
            // quiz that the author bonus would have promoted.
            ->orderByRaw('case when user_id = ? then 0 else 1 end', [$current->user_id])
            ->orderByDesc('upvotes_count')
            ->orderByDesc('id')
            ->limit((int) config('quiz.suggestions.candidate_limit'))
            ->get();
    }

    /**
     * R8.4 — the score.
     *
     *   score = w_author * (same creator ? 1 : 0)
     *         + w_stats  * (1 - |pass_rate_a - pass_rate_b|)
     *         + w_votes  * log10(1 + upvotes_count)
     *
     * @param  array{author: float, stats: float, votes: float}  $weights
     */
    public function score(Quiz $current, Quiz $candidate, array $weights, float $neutral): float
    {
        $author = $candidate->user_id === $current->user_id ? 1.0 : 0.0;

        /*
         * R8.5 — if EITHER quiz has fewer than 10 counted runs there is no
         * meaningful pass rate to compare, so the term is neutral. Note it is
         * the TERM that becomes 0.5, not the pass rate: a quiz with no plays
         * is not assumed to be 50% passable.
         */
        $stats = ($current->hasEnoughRuns() && $candidate->hasEnoughRuns())
            ? 1.0 - abs($current->passRate() - $candidate->passRate())
            : $neutral;

        // log10(1 + x) so zero upvotes contributes zero rather than -INF.
        $votes = log10(1.0 + $candidate->upvotes_count);

        return $weights['author'] * $author
            + $weights['stats'] * $stats
            + $weights['votes'] * $votes;
    }

    /**
     * R8.6 — at most 3 from the same creator.
     *
     * Applied after sorting, so a creator keeps their three BEST-scoring
     * quizzes rather than the three the database happened to return first.
     *
     * @param  Collection<int, array{quiz: Quiz, score: float}>  $scored
     * @return Collection<int, array{quiz: Quiz, score: float}>
     */
    private function capPerCreator(Collection $scored): Collection
    {
        $max = (int) config('quiz.suggestions.per_creator');
        $seen = [];

        return $scored->filter(function (array $row) use (&$seen, $max): bool {
            $creatorId = $row['quiz']->user_id;
            $seen[$creatorId] = ($seen[$creatorId] ?? 0) + 1;

            return $seen[$creatorId] <= $max;
        })->values();
    }
}
