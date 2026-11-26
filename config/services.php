<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    */

    // R1.1 — the only auth provider. Use a separate OAuth client for local dev
    // and for the Railway domain; the redirect URI must match exactly.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
