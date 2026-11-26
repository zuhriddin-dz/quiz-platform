<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UpsertGoogleUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * R1.1 — the only way in. There is no login form, no registration, no reset
 * and no verification (R1.2).
 */
final class GoogleController
{
    /**
     * Hand the visitor off to Google.
     */
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Google sends them back here with a code.
     *
     * The guest cookie is deliberately left alone: guest history is not
     * carried over at signup (spec §3), so there is nothing to migrate, and
     * clearing it would orphan runs already recorded against that token.
     */
    public function callback(Request $request, UpsertGoogleUser $upsertGoogleUser): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            // Denied consent, a stale state token, an expired code — all land
            // here, and none of them is worth a stack trace to the visitor.
            return redirect()
                ->route('home')
                ->with('error', 'Google sign-in did not complete. Please try again.');
        }

        $user = $upsertGoogleUser->handle($googleUser);

        Auth::login($user, remember: true);

        // Fresh session id after a privilege change.
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * Sign out. POST only, so a link cannot log someone out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
