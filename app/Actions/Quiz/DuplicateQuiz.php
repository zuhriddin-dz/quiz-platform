<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

/**
 * R4.5 — duplicate as draft. The only way to change a published quiz.
 *
 * What is copied: the quiz fields and every question, in order.
 *
 * What is NOT copied, and why each matters:
 *   - runs and run_answers — the copy has been played by nobody
 *   - votes and upvotes_count — votes belong to the quiz that earned them
 *   - the four counters — they start at 0, so the 10-run floor applies again
 *   - published_at — the copy has never been published
 *   - quiz_user_stats — nobody has a best result on a quiz that is new
 *
 * The title is copied unchanged, which the spec does not ask to alter. Two
 * rows with the same name are told apart by their status in the Studio list.
 */
final class DuplicateQuiz
{
    public function handle(Quiz $quiz): Quiz
    {
        return DB::transaction(function () use ($quiz): Quiz {
            // Created through the creator's relation, so the copy belongs to
            // whoever owned the original — duplicating is not a transfer.
            $copy = $quiz->creator->quizzes()->create([
                'title' => $quiz->title,
                'description' => $quiz->description,
                'mode' => $quiz->mode,
                'seconds_per_question' => $quiz->seconds_per_question,
                // status defaults to draft, is_listed to true, published_at to
                // null and every counter to 0 — see the quizzes migration.
            ]);

            $quiz->questions()
                ->orderBy('position')
                ->get()
                ->each(function (Question $question, int $index) use ($copy): void {
                    $copy->questions()->create([
                        // Renumbered from the read order, so a gappy original
                        // yields a tidy copy.
                        'position' => $index + 1,
                        'prompt' => $question->prompt,
                        'options' => $question->options,
                        'correct_option_index' => $question->correct_option_index,
                    ]);
                });

            return $copy;
        });
    }
}
