<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Quiz;

/**
 * R7.2 — the quiz card, in one place.
 *
 * Explore uses it, and Phase 8's suggestions will use the same shape, so the
 * two lists cannot drift into showing different things about the same quiz.
 *
 * Note what it does NOT include: no pass rate, no difficulty label, no
 * average reached. The spec's card is deliberately plain — counts and badges
 * only — and §7 says outright there is no difficulty label anywhere.
 *
 * The creator's name and avatar are public (R1.4); the email never appears.
 * `questions_count` comes from `withCount` or an eager-loaded relation, so
 * callers must provide one or the other.
 */
final class QuizCardPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Quiz $quiz): array
    {
        return [
            'ulid' => $quiz->ulid,
            'title' => $quiz->title,
            'creator' => [
                'name' => $quiz->creator->name,
                'avatar_url' => $quiz->creator->avatar_url,
            ],
            'mode' => $quiz->mode->value,
            'mode_label' => $quiz->mode->label(),
            'question_count' => self::questionCount($quiz),
            'seconds_per_question' => $quiz->seconds_per_question,
            'upvotes_count' => $quiz->upvotes_count,
            'runs_count' => $quiz->runs_count,
        ];
    }

    private static function questionCount(Quiz $quiz): int
    {
        if ($quiz->questions_count !== null) {
            return (int) $quiz->questions_count;
        }

        return $quiz->relationLoaded('questions')
            ? $quiz->questions->count()
            : $quiz->questions()->count();
    }
}
