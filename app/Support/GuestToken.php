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
     * The token for the current request, set by EnsureGuestToken.
     */
    public static function fromRequest(Request $request): ?string
    {
        $token = $request->attributes->get(self::ATTRIBUTE);

        return self::isValid($token) ? $token : null;
    }
}
