<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table 4 of 7 — one upvote.
 *
 * Role: records who upvoted what, so the vote can be toggled off again.
 *
 * Why it looks like this:
 *   - The unique `(user_id, quiz_id)` pair is the whole design. It is what
 *     makes a vote idempotent: without it, `upvotes_count` is just a number
 *     anyone can inflate by clicking twice.
 *   - `quizzes.upvotes_count` is updated in the same transaction as this row
 *     (R8.2), so the cached count and the rows can never disagree.
 *   - Guests cannot vote, so `user_id` is not nullable. No guest_token here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'quiz_id']);

            // "Has the viewer voted on this quiz?" on every quiz page load.
            $table->index('quiz_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_votes');
    }
};
