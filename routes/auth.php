<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes
|--------------------------------------------------------------------------
|
| R1.1 / R1.2 — three routes, and no others. The starter kit's register,
| login, password-reset, password-confirm and email-verification routes are
| deleted, not merely hidden: a route that exists is a route that can be hit.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])
        ->name('auth.google.redirect');

    Route::get('auth/google/callback', [GoogleController::class, 'callback'])
        ->name('auth.google.callback');
});

Route::post('logout', [GoogleController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
