<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuizUserStat;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /plays — My plays. R7.6.
 *
 * Driven by `quiz_user_stats`, not by scanning runs: one row per quiz the user
 * has attempted is exactly what this page lists, and it is already maintained
 * by the end-of-run transaction.
 *
 * Guests never reach here — the sidebar shows them a sign-in card instead
 * (R7.7) — and they have no rows anyway.
 */
final class PlaysController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $plays = QuizUserStat::query()
            ->where('user_id', $user->id)
            ->with(['quiz.creator'])
            ->orderByDesc('last_played_at')
            ->get()
            ->map(function (QuizUserStat $stat) use ($user): array {
                $quiz = $stat->quiz;

                // A quiz the viewer can still open and replay: published, or
                // their own draft they were test-playing.
                $isPlayable = $quiz->status->isPubliclyPlayable() || $quiz->isOwnedBy($user);

                return [
                    'ulid' => $quiz->ulid,
                    'title' => $quiz->title,
                    'creator' => [
                        'name' => $quiz->creator->name,
                        'avatar_url' => $quiz->creator->avatar_url,
                    ],
                    'mode' => $quiz->mode->value,
                    'mode_label' => $quiz->mode->label(),
                    'question_count' => $quiz->questions()->count(),

                    // R7.6 — status, attempts, best, first passed.
                    'passed' => $stat->passed,
                    'attempts' => $stat->attempts,
                    'best_reached' => $stat->best_reached,
                    'first_passed_at' => $stat->first_passed_at?->toDateString(),
                    'last_played_at' => $stat->last_played_at->toDateString(),

                    /*
                     * R4.4 — an unpublished quiz shows as "Unavailable" with
                     * the attempts and best result intact, and no "Play
                     * again". Nothing is deleted; it just cannot be replayed.
                     */
                    'is_playable' => $isPlayable,
                ];
            });

        return Inertia::render('Plays', [
            'plays' => $plays->all(),
        ]);
    }
}
