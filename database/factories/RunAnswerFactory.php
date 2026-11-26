<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FailureReason;
use App\Models\Question;
use App\Models\QuizRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RunAnswer>
 */
class RunAnswerFactory extends Factory
{
    /**
     * A correct answer by default, since that is the only way a run continues.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_run_id' => QuizRun::factory(),
            'question_id' => Question::factory(),
            'chosen_option_index' => 0,
            'is_correct' => true,
            'failure_reason' => null,
            'time_ms' => $this->faker->numberBetween(400, 4_000),
        ];
    }

    public function wrong(int $chosenIndex = 1): static
    {
        return $this->state(fn (): array => [
            'chosen_option_index' => $chosenIndex,
            'is_correct' => false,
            'failure_reason' => FailureReason::WrongAnswer,
        ]);
    }

    /** No chosen option: the timer ran out. */
    public function timedOut(): static
    {
        return $this->state(fn (): array => [
            'chosen_option_index' => null,
            'is_correct' => false,
            'failure_reason' => FailureReason::Timeout,
        ]);
    }

    /** No chosen option: the tab was hidden mid-question. */
    public function focusLost(): static
    {
        return $this->state(fn (): array => [
            'chosen_option_index' => null,
            'is_correct' => false,
            'failure_reason' => FailureReason::FocusLost,
        ]);
    }

    public function tookMs(int $timeMs): static
    {
        return $this->state(fn (): array => ['time_ms' => $timeMs]);
    }
}
