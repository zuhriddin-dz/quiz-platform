<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 7 of 7 — one signed-in player's standing on one quiz.
 *
 * Role: answers "attempts, best result, passed?" without scanning runs. It is
 * the per-user mirror of the counters on `quizzes`.
 *
 * Why it looks like this:
 *   - Logged-in users only. Guests have no row here at all, which is exactly
 *     why the guest sidebar shows a sign-in card instead of recent plays, and
 *     why guest history cannot be migrated at signup — there is nothing to
 *     move it into.
 *   - Unique `(user_id, quiz_id)`: this is an upsert target, written once per
 *     run inside the end-of-run transaction (R5.11).
 *   - `best_reached` is a running maximum, not something recomputed. The same
 *     transaction that finishes a run raises it.
 *   - `first_passed_at` is set once and never moved, so "first passed" on My
 *     plays stays honest after a later replay.
 *   - No `timestamps()`: `last_played_at` already is the updated-at of this
 *     row, and a second near-identical column invites them to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_user_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('best_reached')->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamp('first_passed_at')->nullable();
            $table->timestamp('last_played_at');

            $table->unique(['user_id', 'quiz_id']);

            // Sidebar: this user's 5 most recently attempted quizzes (R7.7),
            // and My plays in full (R7.6).
            $table->index(['user_id', 'last_played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_user_stats');
    }
};
