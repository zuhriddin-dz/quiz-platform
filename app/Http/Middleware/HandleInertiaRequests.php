<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\QuizUserStat;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props shared with every page.
     *
     * R1.4 — this is an explicit allowlist, not a serialised model. The Google
     * display name and avatar are public; the email is never shown to anyone,
     * so it must not be here even though `$hidden` on the model would also
     * catch it. Two layers, because one leak is permanent.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar_url' => $user->avatar_url,
                ],
            ],

            // One-off messages from a redirect (a failed Google callback, say).
            'flash' => [
                'error' => fn (): ?string => $request->session()->get('error'),
                'status' => fn (): ?string => $request->session()->get('status'),
            ],

            /*
             * R7.7 — the left sidebar, on every page.
             *
             * A signed-in user sees their 5 most recently attempted quizzes
             * and a "Show all" link; a guest sees a sign-in card instead, so
             * this is null for them and costs no query.
             *
             * One indexed lookup against quiz_user_stats (user_id,
             * last_played_at), which exists for exactly this.
             */
            'recent_plays' => $user === null
                ? null
                : fn (): array => QuizUserStat::query()
                    ->where('user_id', $user->id)
                    // user_id is selected because `is_playable` compares it.
                    ->with('quiz:id,ulid,title,status,user_id')
                    ->orderByDesc('last_played_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (QuizUserStat $stat): array => [
                        'ulid' => $stat->quiz->ulid,
                        'title' => $stat->quiz->title,
                        'best_reached' => $stat->best_reached,
                        'passed' => $stat->passed,
                        'is_playable' => $stat->quiz->status->isPubliclyPlayable()
                            || $stat->quiz->user_id === $user->id,
                    ])
                    ->all(),
        ];
    }
}
