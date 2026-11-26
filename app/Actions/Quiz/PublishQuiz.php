<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Enums\QuizStatus;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

/**
 * R4.3 — publish, or republish an unpublished quiz.
 *
 * Content validation is PublishQuizRequest's job (R4.1); by the time this runs
 * the quiz is known to be publishable.
 */
final class PublishQuiz
{
    public function handle(Quiz $quiz, bool $isListed): Quiz
    {
        return DB::transaction(function () use ($quiz, $isListed): Quiz {
            $quiz->status = QuizStatus::Published;

            // R4.3 — chosen again on every publish, so it is always written.
            $quiz->is_listed = $isListed;

            // `published_at` records the FIRST publish only. Republishing an
            // unpublished quiz must not reset it, or Explore's "Newest" sort
            // would let a creator bump their own quiz back to the top.
            $quiz->published_at ??= now();

            $quiz->save();

            return $quiz;
        });
    }
}
