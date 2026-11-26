<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\QuizMode;
use App\Enums\QuizStatus;
use App\Models\Quiz;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * R7.1 — the Explore listing.
 *
 * Published AND listed, always. An unlisted quiz is reachable by its ULID and
 * playable by anyone who has the link (R7.8), but it never appears here — that
 * is the entire difference between the two visibilities.
 *
 * Portability (R0.4): the search uses `lower(...) like ?`, not `ILIKE`.
 * `ILIKE` is Postgres-only and the test suite runs on SQLite, so it would pass
 * in production and fail in CI — or worse, the reverse.
 */
final class ExploreQuizzes
{
    public const SORTS = ['popular', 'played', 'newest'];

    public const PER_PAGE = 12;

    /**
     * @param  string|null  $mode  null or 'all' means no filter
     */
    public function paginate(
        ?string $search = null,
        ?string $mode = null,
        string $sort = 'popular',
    ): LengthAwarePaginator {
        return Quiz::query()
            ->where('status', QuizStatus::Published->value)
            ->where('is_listed', true)
            // The card shows the creator's name and avatar, so load them once
            // rather than per row.
            ->with('creator')
            ->withCount('questions')
            ->when($search !== null && trim($search) !== '', function ($query) use ($search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('lower(title) like ?', [$term])
                        ->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$term]);
                });
            })
            ->when(
                $mode !== null && $mode !== 'all' && QuizMode::tryFrom($mode) !== null,
                fn ($query) => $query->where('mode', $mode),
            )
            ->tap(fn ($query) => $this->applySort($query, $sort))
            // Not in the spec, but Explore without it would load every
            // published quiz on the site into one page.
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Each sort gets `id` as a tiebreaker, so paging is stable: without it,
     * two quizzes with equal upvotes can swap places between page 1 and 2 and
     * one of them is never seen.
     */
    private function applySort(mixed $query, string $sort): void
    {
        match ($this->normaliseSort($sort)) {
            'played' => $query->orderByDesc('runs_count')->orderByDesc('id'),
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            // Popular is the default (R7.1).
            default => $query->orderByDesc('upvotes_count')->orderByDesc('id'),
        };
    }

    public function normaliseSort(?string $sort): string
    {
        return in_array($sort, self::SORTS, strict: true) ? $sort : 'popular';
    }
}
