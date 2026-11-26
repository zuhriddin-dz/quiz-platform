<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 2 of 7 — the only content type there is.
 *
 * Role: one quiz is one set of questions, plus everything the lifecycle and
 * Explore need to know about it.
 *
 * Why it looks like this:
 *   - `ulid` is what appears in URLs (R2.2). The autoincrement `id` stays
 *     internal, so a share link leaks no ordering or volume information.
 *   - `mode`, `status` are plain strings cast to PHP enums, never database
 *     enum types: those do not exist in both Postgres and SQLite (R0.4).
 *   - The four counters are denormalised on purpose (R2.4). Explore sorts by
 *     them, the quiz page shows them, and §8 scores suggestions from them, all
 *     in one query with no background jobs.
 *   - `reached_sum` rather than an average: a running sum can be incremented
 *     in the same transaction as a run, and `avg_reached` is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Users are never deleted in v1, and a published quiz can never be
            // deleted, so restrict rather than cascade: losing a creator must
            // never silently delete other players' history.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('title', 80);
            $table->text('description')->nullable();

            $table->string('mode', 16);
            $table->unsignedSmallInteger('seconds_per_question');

            $table->string('status', 16)->default('draft');
            $table->boolean('is_listed')->default(true);
            $table->timestamp('published_at')->nullable();

            // R2.4 — cached aggregates, all excluding the creator's own runs.
            $table->unsignedInteger('upvotes_count')->default(0);
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedInteger('passes_count')->default(0);
            $table->unsignedBigInteger('reached_sum')->default(0);

            $table->timestamps();

            // Explore only ever asks for published + listed quizzes (R7.1);
            // every sort then orders within that set.
            $table->index(['status', 'is_listed']);
            $table->index('upvotes_count');
            $table->index('runs_count');
            $table->index('published_at');

            // Creator Studio lists one creator's quizzes, newest first.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
