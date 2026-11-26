<?php

declare(strict_types=1);

/**
 * Phase 1 gate — R1.2.
 *
 * The starter kit's password, registration, reset and verification flows are
 * removed, not hidden. This test is the tripwire: if a future dependency
 * upgrade reinstates the kit's routes, it fails.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

dataset('removed GET routes', [
    '/register',
    '/login',
    '/forgot-password',
    '/reset-password/some-token',
    '/verify-email',
    '/confirm-password',
    '/user/profile-information',
]);

dataset('removed POST routes', [
    '/register',
    '/login',
    '/forgot-password',
    '/reset-password',
    '/email/verification-notification',
]);

it('has no GET route for removed auth flows', function (string $path) {
    $this->get($path)->assertNotFound();
})->with('removed GET routes');

it('has no POST route for removed auth flows', function (string $path) {
    $this->post($path)->assertNotFound();
})->with('removed POST routes');

it('registers exactly three auth routes', function () {
    $authRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->getName() ?? '')
        ->filter(fn (string $name): bool => str_starts_with($name, 'auth.') || $name === 'logout')
        ->values()
        ->sort()
        ->values()
        ->all();

    expect($authRoutes)->toBe([
        'auth.google.callback',
        'auth.google.redirect',
        'logout',
    ]);
});

it('does not expose a password column on users', function () {
    expect(Schema::hasColumn('users', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();
});

it('has no password reset tokens table', function () {
    expect(Schema::hasTable('password_reset_tokens'))->toBeFalse();
});
