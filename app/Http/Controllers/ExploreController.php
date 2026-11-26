<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QuizMode;
use App\Models\Quiz;
use App\Queries\ExploreQuizzes;
use App\Support\QuizCardPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET / — Explore. R7.1, R7.2.
 *
 * The home page for everyone: guests included (R7.8), with no sign-in prompt
 * in the way.
 */
final class ExploreController
{
    public function index(Request $request, ExploreQuizzes $exploreQuizzes): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'mode' => ['nullable', 'string', 'in:all,'.QuizMode::Strict->value.','.QuizMode::Casual->value],
            'sort' => ['nullable', 'string', 'in:'.implode(',', ExploreQuizzes::SORTS)],
        ]);

        $search = $filters['q'] ?? null;
        $mode = $filters['mode'] ?? 'all';
        $sort = $exploreQuizzes->normaliseSort($filters['sort'] ?? null);

        $quizzes = $exploreQuizzes->paginate($search, $mode, $sort);

        return Inertia::render('Explore', [
            // The filters go back out so the controls stay in sync with the
            // URL, which is what makes an Explore link shareable.
            'filters' => [
                'q' => $search,
                'mode' => $mode,
                'sort' => $sort,
            ],
            'quizzes' => [
                'data' => collect($quizzes->items())
                    ->map(fn (Quiz $quiz): array => QuizCardPayload::from($quiz))
                    ->all(),
                'links' => $quizzes->linkCollection()->toArray(),
                'total' => $quizzes->total(),
                'current_page' => $quizzes->currentPage(),
                'last_page' => $quizzes->lastPage(),
            ],
        ]);
    }
}
