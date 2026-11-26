<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Phase 1 gate — R1.1, R1.3, R1.4.
 *
 * Socialite is faked at the facade, so no request ever leaves the process.
 */
function fakeGoogleUser(array $attributes = []): GoogleUser
{
    $googleUser = new GoogleUser;

    $googleUser->map([
        'id' => $attributes['id'] ?? '1098765432109876543210',
        // array_key_exists, not ??: a test passing `name => null` is asserting
        // that Google sent no name, and ?? would quietly restore the default —
        // which made the fallback test pass its own default back in.
        'name' => array_key_exists('name', $attributes) ? $attributes['name'] : 'Amina Yusuf',
        'email' => $attributes['email'] ?? 'amina@example.com',
        'avatar' => $attributes['avatar'] ?? 'https://lh3.googleusercontent.com/a/first',
    ]);

    return $googleUser;
}

function fakeGoogleReturns(GoogleUser $googleUser): void
{
    Socialite::shouldReceive('driver->user')->once()->andReturn($googleUser);
}

it('redirects to Google to start sign-in', function () {
    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirectContains('accounts.google.com');
});

it('creates the account on first sign-in from the Google profile', function () {
    fakeGoogleReturns(fakeGoogleUser());

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home'));

    $user = User::sole();

    expect($user->google_id)->toBe('1098765432109876543210')
        ->and($user->name)->toBe('Amina Yusuf')
        ->and($user->email)->toBe('amina@example.com')
        ->and($user->avatar_url)->toBe('https://lh3.googleusercontent.com/a/first');

    $this->assertAuthenticatedAs($user);
});

it('refreshes the name and avatar on a later login without creating a second account', function () {
    $existing = User::factory()->create([
        'google_id' => '1098765432109876543210',
        'name' => 'Old Name',
        'avatar_url' => 'https://lh3.googleusercontent.com/a/old',
    ]);

    fakeGoogleReturns(fakeGoogleUser([
        'name' => 'New Name',
        'avatar' => 'https://lh3.googleusercontent.com/a/new',
    ]));

    $this->get(route('auth.google.callback'));

    expect(User::count())->toBe(1);

    $existing->refresh();

    expect($existing->name)->toBe('New Name')
        ->and($existing->avatar_url)->toBe('https://lh3.googleusercontent.com/a/new');
});

it('matches on google_id, not email, so a changed Google email does not fork the account', function () {
    User::factory()->create([
        'google_id' => '1098765432109876543210',
        'email' => 'old@example.com',
    ]);

    fakeGoogleReturns(fakeGoogleUser(['email' => 'new@example.com']));

    $this->get(route('auth.google.callback'));

    expect(User::count())->toBe(1)
        ->and(User::sole()->email)->toBe('new@example.com');
});

it('never sends the email to the frontend', function () {
    $user = User::factory()->create(['email' => 'private@example.com']);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertDontSee('private@example.com');

    $props = $response->viewData('page')['props'];

    expect($props['auth']['user'])->toHaveKeys(['id', 'name', 'avatar_url'])
        ->and($props['auth']['user'])->not->toHaveKey('email')
        ->and($props['auth']['user'])->not->toHaveKey('google_id');
});

it('falls back to a default name when Google sends none', function () {
    fakeGoogleReturns(fakeGoogleUser(['name' => null]));

    $this->get(route('auth.google.callback'));

    expect(User::sole()->name)->toBe('Player');
});

it('redirects home with an error when the callback fails', function () {
    Socialite::shouldReceive('driver->user')
        ->once()
        ->andThrow(new RuntimeException('invalid state'));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home'))
        ->assertSessionHas('error');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('signs out on POST and leaves no session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});
