<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 6 of 7 — analytics, one row per answered question.
 *
 * Role: the only record of what players actually did. Everything on the
 * creator's stats page (R9.2) is an aggregate over this table.
 *
 * Why it looks like this:
 *   - Append-only, and `created_at` only. An answer is an event; events are
 *     never updated, so `updated_at` would be dead weight.
 *   - `chosen_option_index` is the ORIGINAL index, already mapped back from
 *     the display order. Storing the displayed index would make every
 *     analytics query re-derive the per-run permutation.
 *   - It is nullable because a timeout or a focus loss has no chosen option —
 *     which is also why `failure_reason` lives on the row.
 *   - `time_ms` is server-measured in strict mode and client-reported in
 *     casual. The column cannot tell them apart; `quiz_runs.mode` can, and
 *     that is what drives the "self-reported" label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_answers', function (Blueprint $table): void {
            $table->id();

            // R2.5 — answers follow their run, and runs follow their quiz.
            $table->foreignUlid('quiz_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('chosen_option_index')->nullable();
            $table->boolean('is_correct');
            $table->string('failure_reason', 16)->nullable();
            $table->unsignedInteger('time_ms');

            $table->timestamp('created_at')->nullable();

            // Replaying one run's answers, in order.
            $table->index('quiz_run_id');

            // Per-question analytics: correct rate, most-picked wrong option,
            // average time, failure split (R9.2).
            $table->index(['question_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_answers');
    }
};
