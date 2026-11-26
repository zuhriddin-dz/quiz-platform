<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Question;
use App\Models\Quiz;

/**
 * R4.1 — publish validation, as a list of human-readable blockers.
 *
 * One class so three callers agree: PublishQuizRequest rejects a publish with
 * it, the Studio list uses it to disable the button, and the editor shows it
 * as guidance. Phase 3's client-side mirror in Edit.vue is advisory only —
 * this is the one that decides.
 *
 * Per-question completeness lives on the model (`Question::isComplete()`), so
 * the "four distinct non-empty options" rule is written exactly once.
 */
final class CheckPublishable
{
    public const MIN_QUESTIONS = 5;

    public const MAX_QUESTIONS = 15;

    public const MIN_SECONDS = 2;

    public const MAX_SECONDS = 10;

    /**
     * @return array<int, string> empty when the quiz may be published
     */
    public function blockers(Quiz $quiz): array
    {
        $blockers = [];

        $questions = $quiz->relationLoaded('questions')
            ? $quiz->questions
            : $quiz->questions()->get();

        $count = $questions->count();

        if ($count < self::MIN_QUESTIONS) {
            $blockers[] = sprintf(
                'A quiz needs at least %d questions (it has %d).',
                self::MIN_QUESTIONS,
                $count,
            );
        }

        if ($count > self::MAX_QUESTIONS) {
            $blockers[] = sprintf(
                'A quiz can have at most %d questions (it has %d).',
                self::MAX_QUESTIONS,
                $count,
            );
        }

        // Report positions, not ids: that is what the creator sees in the editor.
        $incomplete = $questions
            ->reject(fn (Question $question): bool => $question->isComplete())
            ->map(fn (Question $question): int => $question->position)
            ->values();

        if ($incomplete->isNotEmpty()) {
            $blockers[] = sprintf(
                'Question%s %s %s a prompt, four distinct non-empty options and a correct answer.',
                $incomplete->count() === 1 ? '' : 's',
                $incomplete->join(', ', ' and '),
                $incomplete->count() === 1 ? 'needs' : 'need',
            );
        }

        if ($quiz->seconds_per_question < self::MIN_SECONDS
            || $quiz->seconds_per_question > self::MAX_SECONDS) {
            $blockers[] = sprintf(
                'The timer must be between %d and %d seconds.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
            );
        }

        return $blockers;
    }

    public function passes(Quiz $quiz): bool
    {
        return $this->blockers($quiz) === [];
    }
}
