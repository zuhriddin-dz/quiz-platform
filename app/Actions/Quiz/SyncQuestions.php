<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

/**
 * R3.3 — question CRUD, as one save.
 *
 * The editor PUTs the whole question list, so this reconciles it against what
 * is stored:
 *   - a row WITH an id that belongs to this quiz  -> updated
 *   - a row WITHOUT an id                         -> created
 *   - a stored row absent from the payload        -> deleted
 *
 * Three things it is careful about:
 *
 * 1. An `id` in the payload is never trusted on its own. Only ids already
 *    belonging to THIS quiz can be updated, so a crafted payload cannot
 *    reach into someone else's quiz. Unknown ids are treated as new rows.
 *
 * 2. `position` is rewritten from the payload order on every save. It is
 *    authoring order only and nothing else reads it, so there is no reason to
 *    preserve gaps or let the client choose numbers.
 *
 * 3. Blank prompts and options are stored as empty strings, not null: the
 *    columns are NOT NULL, and a draft is allowed to be incomplete (R3.4).
 *    Emptiness becomes an error only at publish time (R4.1).
 */
final class SyncQuestions
{
    /**
     * @param  array<int, array<string, mixed>>  $payload
     */
    public function handle(Quiz $quiz, array $payload): void
    {
        DB::transaction(function () use ($quiz, $payload): void {
            $existingIds = $quiz->questions()->pluck('id')->all();
            $keptIds = [];

            foreach (array_values($payload) as $index => $row) {
                $attributes = [
                    'position' => $index + 1,
                    'prompt' => $this->text($row['prompt'] ?? null),
                    'options' => $this->options($row['options'] ?? []),
                    'correct_option_index' => $this->correctIndex($row['correct_option_index'] ?? null),
                ];

                $id = isset($row['id']) ? (int) $row['id'] : null;

                if ($id !== null && in_array($id, $existingIds, strict: true)) {
                    $quiz->questions()->whereKey($id)->update($attributes);
                    $keptIds[] = $id;

                    continue;
                }

                $keptIds[] = $quiz->questions()->create($attributes)->id;
            }

            // Whatever the editor no longer shows is gone. Safe here: only
            // drafts reach this action, and a draft's only runs are the
            // creator's own test plays, which cascade.
            $removed = array_diff($existingIds, $keptIds);

            if ($removed !== []) {
                Question::whereKey($removed)->delete();
            }
        });
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Always exactly four slots, padded with empty strings. Storing a short
     * array would make every reader handle two shapes.
     *
     * @return array<int, string>
     */
    private function options(mixed $value): array
    {
        $options = is_array($value) ? array_values($value) : [];

        $options = array_map(
            fn (mixed $option): string => $this->text($option),
            array_slice($options, 0, Question::OPTION_COUNT),
        );

        return array_pad($options, Question::OPTION_COUNT, '');
    }

    private function correctIndex(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $index = (int) $value;

        return $index >= 0 && $index < Question::OPTION_COUNT ? $index : null;
    }
}
