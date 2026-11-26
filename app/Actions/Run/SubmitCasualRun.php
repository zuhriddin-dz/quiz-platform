<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Enums\FailureReason;
use App\Models\Question;
use App\Models\QuizRun;
use App\Models\RunAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R6.4 — the server re-checks the submitted log and derives pass or fail
 * itself.
 *
 * What it trusts and what it does not:
 *
 *   TRUSTED  `time_ms`, and a reported `timeout` / `focus_lost`. The client
 *            ran the clock, so there is nothing to compare against. This is
 *            why casual results are labelled "self-reported" (R6.5).
 *
 *   CHECKED  correctness, every time, against `correct_option_index`. A log
 *            claiming a wrong option was right simply fails.
 *
 *   REJECTED a log that is structurally impossible: a question that is not in
 *            this run, the same question twice, or a run that stops without
 *            saying why. Those are not "wrong answers" — they are a client
 *            that cannot be interpreted, so they get a 422 rather than a
 *            quietly recorded fail.
 *
 * Sudden death is applied while reading: processing stops at the first entry
 * that is not correct, and anything after it in the log is ignored. A tampered
 * log cannot smuggle extra correct answers in after a failure.
 */
final class SubmitCasualRun
{
    public function __construct(private readonly FinishRun $finishRun) {}

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    public function handle(QuizRun $run, array $answers): array
    {
        $questions = Question::query()
            ->whereIn('id', $run->question_order)
            ->get()
            ->keyBy('id');

        $expectedCount = $run->questionCount();

        $reached = 0;
        $failureReason = null;
        $seen = [];
        $rows = [];

        foreach ($answers as $entry) {
            $questionId = (int) $entry['question_id'];

            // Not part of this run — a different quiz's question, or invented.
            if (! $questions->has($questionId)) {
                throw ValidationException::withMessages([
                    'answers' => 'The answer log refers to a question that is not in this run.',
                ]);
            }

            // The same question twice would let a player retry one question.
            if (isset($seen[$questionId])) {
                throw ValidationException::withMessages([
                    'answers' => 'The answer log contains the same question twice.',
                ]);
            }

            $seen[$questionId] = true;

            $question = $questions->get($questionId);
            $reason = isset($entry['reason']) ? FailureReason::from((string) $entry['reason']) : null;
            $chosenIndex = $entry['chosen_option_index'] ?? null;
            $chosenIndex = $chosenIndex === null ? null : (int) $chosenIndex;

            if ($reason !== null) {
                // Trusted as reported.
                $rows[] = $this->row($run, $question, null, false, $reason, $entry);
                $failureReason = $reason;

                break;
            }

            $isCorrect = $chosenIndex !== null
                && $chosenIndex === $question->correct_option_index;

            $rows[] = $this->row(
                $run,
                $question,
                $chosenIndex,
                $isCorrect,
                $isCorrect ? null : FailureReason::WrongAnswer,
                $entry,
            );

            if (! $isCorrect) {
                $failureReason = FailureReason::WrongAnswer;

                break;
            }

            $reached++;
        }

        /*
         * A log with no failure must be complete. Sudden death means a run
         * ends for a reason, so "I answered three of ten correctly and then
         * stopped" is not a state the client is allowed to report.
         */
        if ($failureReason === null && $reached !== $expectedCount) {
            throw ValidationException::withMessages([
                'answers' => 'The answer log is incomplete and reports no reason for stopping.',
            ]);
        }

        return DB::transaction(function () use ($run, $rows, $reached, $failureReason, $expectedCount): array {
            foreach ($rows as $row) {
                RunAnswer::create($row);
            }

            $run->reached = $reached;

            $stats = $failureReason === null
                ? $this->finishRun->pass($run)
                : $this->finishRun->fail($run, $failureReason);

            return [
                'verdict' => $failureReason === null ? 'passed' : 'failed',
                'failure_reason' => $failureReason?->value,
                'reached' => $reached,
                'question_count' => $expectedCount,
                // Casual play is self-checked, so the client already knows
                // which option it picked; nothing extra is echoed back, and
                // the correct answer is never revealed.
                ...$stats,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function row(
        QuizRun $run,
        Question $question,
        ?int $chosenIndex,
        bool $isCorrect,
        ?FailureReason $reason,
        array $entry,
    ): array {
        return [
            'quiz_run_id' => $run->id,
            'question_id' => $question->id,
            'chosen_option_index' => $chosenIndex,
            'is_correct' => $isCorrect,
            'failure_reason' => $reason,
            // Client-reported for casual. Clamped only against absurd values,
            // since a negative or week-long duration is a bug, not a claim.
            'time_ms' => max(0, min((int) ($entry['time_ms'] ?? 0), 600_000)),
        ];
    }
}
