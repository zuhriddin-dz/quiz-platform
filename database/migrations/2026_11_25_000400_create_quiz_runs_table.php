<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 5 of 7 — one attempt. The heart of the app.
 *
 * Role: holds everything a run needs to be refereed, including the shuffles,
 * so the same quiz can be played simultaneously by many players each seeing a
 * different order.
 *
 * Why it looks like this:
 *   - The primary key is a ULID (R2.2) because it appears in URLs:
 *     POST /runs/{run}/answers. Sortable by creation time, unguessable enough
 *     that a run id cannot be walked.
 *   - `question_order` and `option_orders` store the shuffle AT START. They
 *     are the reason strict mode can map a displayed index back to the
 *     original one (R5.2) without ever telling the client which is which.
 *     `option_orders` is strict-only; casual shuffles client-side.
 *   - `salt` is casual-only: answer hashes are sha256(salt:question_id:text),
 *     so a per-run salt stops a player reusing hashes across runs.
 *   - `current_index` / `current_issued_at` are the strict cursor. The
 *     issue time carries millisecond precision because the timeout decision is
 *     `received_at - current_issued_at > seconds_per_question + grace` (R5.3).
 *   - `mode` is COPIED from the quiz at start, not read through the relation.
 *     Published quizzes are immutable so it cannot drift, but a run must stay
 *     interpretable on its own terms.
 *   - R2.3: exactly one of `user_id` / `guest_token` is set. That invariant is
 *     enforced in the model and by test, not by a CHECK constraint — Postgres
 *     and SQLite spell those differently, and R0.4 forbids driver-specific SQL.
 *   - No `created_at` / `updated_at`: `started_at` and `finished_at` are the
 *     run's clock, per the spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            // Exactly one of these two is set.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token', 26)->nullable();

            $table->string('mode', 16);

            $table->json('question_order');
            $table->json('option_orders')->nullable();
            $table->string('salt', 64)->nullable();

            $table->unsignedSmallInteger('current_index')->default(0);
            $table->timestamp('current_issued_at', precision: 3)->nullable();

            $table->string('status', 16)->default('in_progress');
            $table->string('failure_reason', 16)->nullable();
            $table->unsignedSmallInteger('reached')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // R2.3
            $table->index('guest_token');

            // "Abandon any in_progress run by this player on this quiz" runs on
            // every start (R5.1), for both kinds of player.
            $table->index(['quiz_id', 'user_id', 'status']);
            $table->index(['quiz_id', 'guest_token', 'status']);

            // Creator analytics and public stats aggregate per quiz by status.
            $table->index(['quiz_id', 'status']);

            // My plays: this user's attempts, most recent first (R7.6).
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_runs');
    }
};
