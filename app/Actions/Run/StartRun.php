<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Enums\QuizMode;
use App\Enums\RunStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\User;
use App\Support\QuestionPayload;
use Illuminate\Support\Facades\DB;

/**
 * R5.1 — start a strict run.
 *
 * The four steps are the spec's, in its order:
 *   1. shuffle and store `question_order` and `option_orders`
 *   2. abandon any in-progress run by the same player on the same quiz
 *   3. return ONLY the first question, with no answer data
 *   4. set `current_issued_at`
 *
 * The shuffle is stored rather than recomputed because every later request
 * has to agree with it: the displayed index the player clicks is meaningless
 * without the permutation that produced it.
 */
final class StartRun
{
    public function handle(Quiz $quiz, ?User $user, ?string $guestToken): array
    {
        return DB::transaction(function () use ($quiz, $user, $guestToken): array {
            // Step 2 first, inside the same transaction: a double-clicked
            // Start must not leave two live runs.
            $this->abandonPreviousRuns($quiz, $user, $guestToken);

            $questions = $quiz->questions()->get();

            // Step 1. Shuffle the questions, then each question's options.
            $questionOrder = $questions->pluck('id')
                ->shuffle()
                ->values()
                ->all();

            $optionOrders = [];

            foreach ($questions as $question) {
                // The permutation is display position -> original index.
                $optionOrders[(string) $question->id] = collect(
                    range(0, count($question->options) - 1)
                )->shuffle()->values()->all();
            }

            $run = QuizRun::create([
                'quiz_id' => $quiz->id,
                'user_id' => $user?->id,
                'guest_token' => $user === null ? $guestToken : null,
                // Copied from the quiz, so the run stays interpretable alone.
                'mode' => QuizMode::Strict,
                'question_order' => $questionOrder,
                'option_orders' => $optionOrders,
                'salt' => null,
                'current_index' => 0,
                // Step 4.
                'current_issued_at' => now(),
                'status' => RunStatus::InProgress,
                'reached' => 0,
                'started_at' => now(),
            ]);

            $first = $questions->firstWhere('id', $questionOrder[0]);

            return [
                'run' => [
                    'id' => $run->id,
                    'mode' => $run->mode->value,
                    'question_count' => count($questionOrder),
                    'seconds_per_question' => $quiz->seconds_per_question,
                ],
                // Step 3 — one question, and QuestionPayload guarantees it
                // carries no answer data.
                'question' => QuestionPayload::forRun($run, $first),
            ];
        });
    }

    /**
     * Only this player's runs on this quiz, and only live ones. Someone else's
     * run, or the same player's run on another quiz, is none of our business.
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
            // Mass update: no events needed, and neither owner column moves.
            ->update([
                'status' => RunStatus::Abandoned->value,
                'finished_at' => now(),
                'current_issued_at' => null,
            ]);
    }

    /**
     * A quiz with no questions cannot be played. Only reachable on a draft,
     * since publishing needs at least five.
     */
    public function canStart(Quiz $quiz): bool
    {
        return $quiz->questions()->exists();
    }
}
