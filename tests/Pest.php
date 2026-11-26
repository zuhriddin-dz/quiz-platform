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
