<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\GuestToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * R1.5 — issues a stable guest token to every visitor.
 *
 * It runs for signed-in users too. That is deliberate: a user who signs out
 * keeps playing as the same guest, and a guest who signs in does not lose the
 * runs already recorded against their token (they are simply never migrated —
 * spec §3).
 *
 * The cookie is queued, not written directly, so Laravel's cookie encryption
 * applies. An unparseable or hand-edited value is replaced rather than trusted.
 */
final class EnsureGuestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(GuestToken::COOKIE);

        if (! GuestToken::isValid($token)) {
            $token = GuestToken::generate();

            Cookie::queue(
                name: GuestToken::COOKIE,
                value: $token,
                minutes: GuestToken::LIFETIME_MINUTES,
                httpOnly: true,
            );
        }

        // Resolved once per request; everything downstream reads it from here
        // rather than touching the cookie again.
        $request->attributes->set(GuestToken::ATTRIBUTE, $token);

        return $next($request);
    }
}
