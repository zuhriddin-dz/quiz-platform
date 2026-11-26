<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * R1.5 — the guest identity.
 *
 * Guests are identified by a random token in a long-lived cookie. Their runs
 * and analytics are recorded against it (`quiz_runs.guest_token`), and run
 * endpoints check ownership against it (Phase 5, R5.12).
 *
 * The format is a ULID: fixed 26 characters, index-friendly, and cheap to
 * validate, which matters because a tampered cookie must never reach a query.
 */
final class GuestToken
{
    public const COOKIE = 'quiz_guest_token';

    /** Where the middleware stashes the resolved token for this request. */
    public const ATTRIBUTE = 'quiz.guest_token';

    /** Two years. "Long-lived" per the spec; survives a browser restart. */
    public const LIFETIME_MINUTES = 60 * 24 * 365 * 2;

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    /**
     * Crockford base32, as ULIDs use. Rejects anything hand-edited.
     */
    public static function isValid(mixed $token): bool
    {
        return is_string($token)
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $token) === 1;
    }

    /**
     * The token for the current request.
     *
     * Normally EnsureGuestToken has already resolved it into a request
     * attribute. But it CANNOT be relied on to have run: `ThrottleRequests`
     * sits in Laravel's default middleware priority list, which hoists it
     * ahead of anything appended to the web group — so a rate limiter asking
     * who this guest is arrives here BEFORE the attribute exists.
     *
     * Without the fallback below, every guest resolved to null inside the
     * limiter and they all shared one `no-token|IP` bucket: one person on a
     * school or office connection could throttle everybody behind it, which
     * is the precise failure the token+IP key exists to prevent.
     *
     * Reading the cookie directly is safe at that point because
     * `EncryptCookies` is higher in the same priority list, so the value is
     * already decrypted.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = $request->attributes->get(self::ATTRIBUTE);

        if (self::isValid($token)) {
            return $token;
        }

        $cookie = $request->cookie(self::COOKIE);

        return self::isValid($cookie) ? $cookie : null;
    }
}
