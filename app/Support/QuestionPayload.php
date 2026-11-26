<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Question;
use App\Models\QuizRun;

/**
 * R5.5 — the single place a question becomes JSON for a strict-mode client.
 *
 * It exists so there is exactly one answer to "could an answer leak to the
 * browser?". Nothing here reads `correct_option_index` or
 * `correctOptionText()`, and the payload is built by listing fields rather
 * than by hiding them, so a new column on `questions` cannot appear in the
 * response by accident.
 *
 * The options come out in DISPLAY order, derived from the permutation stored
 * on the run at start. The client never learns the original order, so it
 * cannot correlate across runs either.
 */
final class QuestionPayload
{
    /**
     * @return array{index: int, number: int, prompt: string, options: array<int, string>}
     */
    public static function forRun(QuizRun $run, Question $question): array
    {
        return [
            // Zero-based cursor, for the client's own bookkeeping.
            'index' => $run->current_index,
            // One-based, for "Question 3 of 10".
            'number' => $run->current_index + 1,
            'prompt' => $question->prompt,
            'options' => self::displayedOptions($run, $question),
        ];
    }

    /**
     * The question's options in this run's display order.
     *
     * @return array<int, string>
     */
    public static function displayedOptions(QuizRun $run, Question $question): array
    {
        $permutation = self::permutationFor($run, $question);
        $options = $question->options;

        return array_values(array_map(
            static fn (int $originalIndex): string => (string) ($options[$originalIndex] ?? ''),
            $permutation,
        ));
    }

    /**
     * R5.2 — map what the player clicked back to the stored option.
     *
     * `option_orders[questionId][displayedIndex] = originalIndex`.
     * Returns null for an index the run never displayed, which a tampered
     * request would produce.
     */
    public static function toOriginalIndex(QuizRun $run, Question $question, int $displayedIndex): ?int
    {
        $permutation = self::permutationFor($run, $question);

        return $permutation[$displayedIndex] ?? null;
    }

    /**
     * Falls back to the identity order for a run with no stored permutation —
     * casual runs, which shuffle client-side, and any legacy row.
     *
     * @return array<int, int>
     */
    private static function permutationFor(QuizRun $run, Question $question): array
    {
        $orders = $run->option_orders ?? [];
        $permutation = $orders[(string) $question->id] ?? $orders[$question->id] ?? null;

        if (! is_array($permutation) || $permutation === []) {
            return range(0, count($question->options) - 1);
        }

        return array_map(static fn (mixed $index): int => (int) $index, array_values($permutation));
    }
}
