<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 3 of 7 — a prompt and its four options.
 *
 * Role: the content a player actually sees, one row per question.
 *
 * Why it looks like this:
 *   - `position` is authoring order only. Play order is always shuffled, and
 *     the shuffle is stored per run in `quiz_runs.question_order`. Nothing
 *     reads `position` except the editor.
 *   - `options` is a JSON array of four strings rather than four columns or a
 *     separate `options` table. The count is fixed at exactly four by the
 *     spec, the options have no identity of their own, and shuffling means
 *     they are always read as a set. `$table->json()` is portable: jsonb-ish
 *     on Postgres, text on SQLite.
 *   - `correct_option_index` points into the ORIGINAL array, never into the
 *     shuffled display order. The mapping between the two lives in
 *     `quiz_runs.option_orders` (R5.2).
 *   - "exactly 4, non-empty, distinct" is NOT enforced here. A draft may be
 *     incomplete (R3.4); those rules are publish validation (R4.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();

            // R2.5 — deleting a draft takes its questions with it.
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');
            $table->string('prompt', 200);
            $table->json('options');
            $table->unsignedTinyInteger('correct_option_index')->nullable();

            $table->timestamps();

            // The editor and every run payload read a quiz's questions in
            // authoring order.
            $table->index(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
