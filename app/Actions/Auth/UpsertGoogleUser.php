<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Socialite\Contracts\User as GoogleUser;

/**
 * R1.3 — the whole of it, in one place so it can be tested without HTTP.
 *
 * First sign-in creates the account from Google's id, display name, email and
 * avatar. Every later login refreshes the name and avatar, because the spec
 * treats Google as the source of truth for both.
 *
 * Matching is on `google_id`, never on email: a user can change their Google
 * email address, and we must not create a second account when they do.
 */
final class UpsertGoogleUser
{
    public function handle(GoogleUser $googleUser): User
    {
        $googleId = (string) $googleUser->getId();

        return User::updateOrCreate(
            ['google_id' => $googleId],
            [
                'name' => $this->resolveName($googleUser),
                'email' => (string) $googleUser->getEmail(),
                'avatar_url' => $googleUser->getAvatar(),
            ],
        );
    }

    /**
     * Google normally sends a display name, but the field is not guaranteed.
     * There are no nicknames in this app, so fall back rather than prompt.
     */
    private function resolveName(GoogleUser $googleUser): string
    {
        foreach ([$googleUser->getName(), $googleUser->getNickname()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Player';
    }
}
