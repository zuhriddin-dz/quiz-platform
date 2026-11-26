<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\GuestToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R10.1 / §11 — the two named limiters.
 *
 * A provider of its own rather than lines in AppServiceProvider, so the
 * starter kit's own registrations are left alone.
 *
 * Remember to list it in `bootstrap/providers.php`.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->limitStartingRuns();
        $this->limitVoting();
    }

    /**
     * Starting a run is the expensive endpoint: it shuffles, writes a row and
     * abandons the previous run. An unthrottled loop is both a write
     * amplifier and a way to farm a quiz's `runs_count`.
     */
    private function limitStartingRuns(): void
    {
        RateLimiter::for('start-run', function (Request $request): Limit {
            $perMinute = (int) config('quiz.rate_limits.start_run_per_minute');

            if ($user = $request->user()) {
                return Limit::perMinute($perMinute)->by('run:user:'.$user->id);
            }

            /*
             * Guests are keyed by token AND IP, as the spec says.
             *
             * The token alone is a cookie anyone can clear, so it would be
             * trivial to sidestep. The IP alone would throttle a whole school
             * or office sharing one address — and since guests are the
             * majority of players here, that failure mode matters more than
             * the bypass does.
             */
            $token = GuestToken::fromRequest($request) ?? 'no-token';

            return Limit::perMinute($perMinute)->by('run:guest:'.$token.'|'.$request->ip());
        });
    }

    /**
     * Voting is signed-in only, so the user id is the whole key.
     */
    private function limitVoting(): void
    {
        RateLimiter::for('vote', function (Request $request): Limit {
            $perMinute = (int) config('quiz.rate_limits.vote_per_minute');

            return Limit::perMinute($perMinute)->by('vote:user:'.$request->user()?->id);
        });
    }
}
