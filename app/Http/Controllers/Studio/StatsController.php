<?php

declare(strict_types=1);

namespace App\Http\Controllers\Studio;

use App\Models\Quiz;
use App\Queries\QuizAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /studio/quizzes/{quiz}/stats — R9.1 to R9.5.
 *
 * R9.5 — `QuizPolicy::viewStats` follows ownership alone and ignores status,
 * so an unpublished quiz's analytics stay fully available. That is the whole
 * point of unpublishing rather than deleting.
 */
final class StatsController
{
    public function show(Request $request, Quiz $quiz, QuizAnalytics $analytics): Response
    {
        abort_unless($request->user()->can('viewStats', $quiz), 403);

        return Inertia::render('studio/Stats', [
            'quiz' => [
                'ulid' => $quiz->ulid,
                'title' => $quiz->title,
                'status' => $quiz->status->value,
                'status_label' => $quiz->status->label(),
                'mode' => $quiz->mode->value,
                'mode_label' => $quiz->mode->label(),
                'seconds_per_question' => $quiz->seconds_per_question,
                // R9.4 — drives the "self-reported" labelling.
                'is_self_reported' => $quiz->mode->isSelfReported(),
            ],
            'analytics' => $analytics->for($quiz),
        ]);
    }
}
