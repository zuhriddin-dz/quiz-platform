<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| RefreshDatabase is applied to the whole Feature suite because every test
| in it touches the schema, and the suite runs against SQLite in-memory
| (phpunit.xml, R0.4) where a fresh migrate is cheap.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

uses(TestCase::class)->in('Unit');

/*
 * Every feature test that renders a page goes through app.blade.php, which
 * calls @vite — and @vite throws when `public/build/manifest.json` is absent.
 * That makes the whole suite depend on having run `npm run build`, which is
 * both slow and wrong: these tests are about server behaviour, not assets.
 *
 * `withoutVite()` stubs the directive out, so the suite passes on a clean
 * checkout with no node_modules at all.
 */
beforeEach(function (): void {
    $this->withoutVite();
})->in('Feature');
