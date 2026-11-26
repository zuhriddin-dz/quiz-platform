<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Enums\FailureReason;
use App\Models\Question;
use App\Models\QuizRun;
use App\Models\RunAnswer;
use App\Support\QuestionPayload;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * R5.2, R5.3, R5.4, R5.6 — one strict-mode answer.
 *
 * The order of decisions below is the whole of the timing rule, so it is
 * worth stating plainly:
 *
 *   1. The server's clock is checked FIRST. If the answer arrived after
 *      `seconds_per_question + grace`, it is a timeout "whatever was clicked"
 *      (R5.3) — a correct answer that arrives too late is still a loss.
 *   2. Only then is a client-reported reason honoured.
 *   3. Only then is the actual choice compared.
 *
 * Doing it in any other order would let a slow client claim a late correct
 * answer, which is the exact thing strict mode exists to prevent.
 */
final class AnswerQuestion
{
    public function __construct(private readonly FinishRun $finishRun) {}

    /**
     * @param  float  $receivedAt  unix timestamp with fraction, taken from the
     *                             request's arrival rather than from now(), so
     *                             our own processing time is not charged to
     *                             the player
     * @return array<string, mixed> the verdict payload
     */
    public function handle(
        QuizRun $run,
        ?int $displayedIndex,
        ?FailureReason $reportedReason,
        float $receivedAt,
    ): array {
        if ($run->status->isFinished()) {
            throw new RuntimeException('This run has already finished.');
        }

        $question = $this->currentQuestion($run);
        $quiz = $run->quiz;

        $elapsedMs = $this->elapsedMs($run, $receivedAt);
        $limitMs = (int) round(
            ($quiz->seconds_per_question + config('quiz.grace_seconds')) * 1000
        );

        // ---------------------------------------------------------- decide
        $chosenOriginalIndex = null;
        $isCorrect = false;
        $failureReason = null;

        if ($elapsedMs > $limitMs) {
            // 1. Too late, whatever was clicked.
            $failureReason = FailureReason::Timeout;
        } elseif ($reportedReason !== null) {
            // 2. The client says the timer ran out or the tab was hidden.
            $failureReason = $reportedReason;
        } else {
            // 3. A real choice. Map display position -> stored option.
            $chosenOriginalIndex = QuestionPayload::toOriginalIndex(
                $run,
                $question,
                (int) $displayedIndex,
            );

            $isCorrect = $chosenOriginalIndex !== null
                && $chosenOriginalIndex === $question->correct_option_index;

            if (! $isCorrect) {
                $failureReason = FailureReason::WrongAnswer;
            }
        }

        return DB::transaction(function () use (
            $run,
            $question,
            $chosenOriginalIndex,
            $isCorrect,
            $failureReason,
            $elapsedMs,
            $displayedIndex,
        ): array {
            RunAnswer::create([
                'quiz_run_id' => $run->id,
                'question_id' => $question->id,
                // The ORIGINAL index, already mapped back, so analytics never
                // has to re-derive a per-run permutation.
                'chosen_option_index' => $chosenOriginalIndex,
                'is_correct' => $isCorrect,
                'failure_reason' => $failureReason,
                // Server-measured for strict mode.
                'time_ms' => $elapsedMs,
            ]);

            // R5.6 — sudden death. Anything other than correct ends it.
            if (! $isCorrect) {
                $stats = $this->finishRun->fail($run, $failureReason);

                return [
                    'verdict' => 'failed',
                    'failure_reason' => $failureReason->value,
                    // Echoed back so the fail screen can highlight what the
                    // player picked. The CORRECT answer is never sent (R5.4).
                    'chosen_displayed_index' => $failureReason === FailureReason::WrongAnswer
                        ? $displayedIndex
                        : null,
                    'reached' => $run->reached,
                    'question_count' => $run->questionCount(),
                    ...$stats,
                ];
            }

            $run->reached++;

            if ($run->isOnLastQuestion()) {
                $stats = $this->finishRun->pass($run);

                return [
                    'verdict' => 'passed',
                    'reached' => $run->reached,
                    'question_count' => $run->questionCount(),
                    ...$stats,
                ];
            }

            // R5.4 — advance, reissue the clock, send the next question.
            $run->current_index++;
            $run->current_issued_at = now();
            $run->save();

            return [
                'verdict' => 'correct',
                'reached' => $run->reached,
                'question_count' => $run->questionCount(),
                'question' => QuestionPayload::forRun($run, $this->currentQuestion($run)),
            ];
        });
    }

    /**
     * Elapsed since the question was issued. A run with no issue time (which
     * should not happen while in progress) is treated as instant rather than
     * as infinitely late, so a bug cannot fail a player.
     */
    private function elapsedMs(QuizRun $run, float $receivedAt): int
    {
        if ($run->current_issued_at === null) {
            return 0;
        }

        $elapsed = ($receivedAt - $run->current_issued_at->getTimestamp())
            - ($run->current_issued_at->micro / 1_000_000);

        return max(0, (int) round($elapsed * 1000));
    }

    private function currentQuestion(QuizRun $run): Question
    {
        $questionId = $run->currentQuestionId();

        if ($questionId === null) {
            throw new RuntimeException('This run has no current question.');
        }

        return Question::findOrFail($questionId);
    }
}
