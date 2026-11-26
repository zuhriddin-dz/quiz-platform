<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Enums\QuizMode;
use App\Enums\RunStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\User;
use App\Support\AnswerHash;
use Illuminate\Support\Facades\DB;

/**
 * R6.1 — start a casual run.
 *
 * The opposite trade to strict mode: everything goes to the client at once so
 * play is instant, and correctness is re-derived on submit instead.
 *
 * Two fields differ from a strict run, and both follow from the client doing
 * the shuffling:
 *
 *   - `option_orders` stays NULL. The server never learns the display order,
 *     so it cannot map a displayed index — which is why the submitted log
 *     carries ORIGINAL indexes (R6.3).
 *   - `question_order` holds the quiz's authoring order. For a casual run it
 *     is the candidate SET, not the play order; the play order is whatever
 *     the submitted log turns out to be. It is still what `questionCount()`
 *     and membership checks read.
 */
final class StartCasualRun
{
    public function handle(Quiz $quiz, ?User $user, ?string $guestToken): array
    {
        return DB::transaction(function () use ($quiz, $user, $guestToken): array {
            $this->abandonPreviousRuns($quiz, $user, $guestToken);

            $questions = $quiz->questions()->get();
            $salt = AnswerHash::generateSalt();

            $run = QuizRun::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user?->id,
                'guest_token' => $user === null ? $guestToken : null,
                'mode' => QuizMode::Casual,
                'question_order' => $questions->pluck('id')->all(),
                'option_orders' => null,
                'salt' => $salt,
                'current_index' => 0,
                // No server cursor: there is no per-question round trip to
                // time, and the client runs its own clock (R6.2).
                'current_issued_at' => null,
                'status' => RunStatus::InProgress,
                'reached' => 0,
                'started_at' => now(),
            ]);

            return [
                'run' => [
                    'id' => $run->id,
                    'mode' => $run->mode->value,
                    'question_count' => $questions->count(),
                    'seconds_per_question' => $quiz->seconds_per_question,
                    // The browser needs the salt to hash what the player
                    // clicks. This is what makes the answers readable to a
                    // determined user, and the spec accepts that.
                    'salt' => $salt,
                ],
                'questions' => $questions->map(fn (Question $question): array => [
                    'id' => $question->id,
                    'prompt' => $question->prompt,
                    // ORIGINAL order. The client shuffles for display and
                    // keeps its own mapping back to these indexes.
                    'options' => $question->options,
                    'answer_hash' => AnswerHash::for(
                        $salt,
                        $question->id,
                        (string) $question->correctOptionText(),
                    ),
                ])->values()->all(),
            ];
        });
    }

    /**
     * Same rule as a strict start (R5.1 step 2): one live run per player per
     * quiz.
     */
    private function abandonPreviousRuns(Quiz $quiz, ?User $user, ?string $guestToken): void
    {
        QuizRun::query()
            ->where('quiz_id', $quiz->id)
            ->where('status', RunStatus::InProgress->value)
            ->when(
                $user !== null,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->where('guest_token', $guestToken),
            )
            ->update([
                'status' => RunStatus::Abandoned->value,
                'finished_at' => now(),
                'current_issued_at' => null,
            ]);
    }
}
