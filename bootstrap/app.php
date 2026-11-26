<?php

declare(strict_types=1);

use App\Http\Middleware\CloseStaleRuns;
use App\Http\Middleware\EnsureGuestToken;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Railway's healthcheck target (railway.json → healthcheckPath).
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Both environments put a reverse proxy in front of PHP: nginx
         * locally, Railway's edge in production. Without this, Laravel builds
         * http:// URLs behind Railway's TLS termination, which breaks the
         * Google OAuth redirect and every secure cookie.
         *
         * Trusting all proxies is correct on a PaaS, where the edge address is
         * not fixed. Read from config rather than env() so `config:cache`
         * cannot silently blank it.
         */
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // After Inertia, so a first-time visitor's token is already set
            // when props are shared on the next request.
            EnsureGuestToken::class,
            // R4.4 — must follow EnsureGuestToken: it needs the resolved
            // token to find a guest's stale runs.
            CloseStaleRuns::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * R10.4 — render error statuses as an Inertia page, so a 403 or 404
         * keeps the top bar and the sidebar instead of dead-ending on
         * Laravel's own error page.
         *
         * Two deliberate exclusions:
         *
         *   1. JSON requests pass straight through. The play loop depends on
         *      real status codes — 403 for a run that is not yours, 409 for a
         *      finished one, 422 for a bad body, 429 for the throttle — and
         *      wrapping those in HTML would break every verdict.
         *
         *   2. Local development passes through too, because Laravel's own
         *      error page with its stack trace is far more useful than a
         *      polite message while building.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->expectsJson() || app()->environment('local')) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (! in_array($status, [403, 404, 419, 429, 500, 503], strict: true)) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })
    ->create();
