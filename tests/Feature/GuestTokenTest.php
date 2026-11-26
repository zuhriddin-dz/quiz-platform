<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\GuestToken;

/**
 * Phase 1 gate — R1.5.
 */

it('issues a guest token cookie on the first request', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertCookie(GuestToken::COOKIE);

    expect(GuestToken::isValid($response->getCookie(GuestToken::COOKIE, false)?->getValue()))
        ->toBeTrue();
});

it('reuses an existing token instead of issuing a new one', function () {
    $token = GuestToken::generate();

    $response = $this->withUnencryptedCookie(GuestToken::COOKIE, $token)
        ->get(route('home'));

    $response->assertOk();

    // Nothing new queued: the request already had a valid token.
    expect($response->getCookie(GuestToken::COOKIE, false))->toBeNull();
});

it('replaces a tampered token rather than trusting it', function () {
    $response = $this->withUnencryptedCookie(GuestToken::COOKIE, 'not-a-ulid')
        ->get(route('home'));

    $issued = $response->getCookie(GuestToken::COOKIE, false)?->getValue();

    expect($issued)->not->toBe('not-a-ulid')
        ->and(GuestToken::isValid($issued))->toBeTrue();
});

it('issues a token to signed-in users too, so signing out keeps the identity', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('home'));

    $response->assertCookie(GuestToken::COOKIE);
});

it('sets a long-lived, http-only cookie', function () {
    $cookie = $this->get(route('home'))->getCookie(GuestToken::COOKIE, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addYear()->getTimestamp());
});

it('rejects an invalid token when read back off the request', function () {
    expect(GuestToken::isValid('not-a-ulid'))->toBeFalse()
        ->and(GuestToken::isValid(null))->toBeFalse()
        ->and(GuestToken::isValid(str_repeat('A', 26)))->toBeTrue();
});
