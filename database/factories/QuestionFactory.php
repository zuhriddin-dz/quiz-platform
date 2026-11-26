<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Always produces a COMPLETE question: four distinct non-empty options and
     * a correct index. Incomplete states are opt-in, because the interesting
     * failure is a draft that cannot publish, and a test should name that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Distinct even after trimming and case-folding (R4.1), which rules
        // out faker words colliding.
        $options = $this->faker->unique()->words(Question::OPTION_COUNT);

        return [
            'quiz_id' => Quiz::factory(),
            'position' => 1,
            'prompt' => rtrim($this->faker->sentence(6), '.').'?',
            'options' => $options,
            'correct_option_index' => $this->faker->numberBetween(0, Question::OPTION_COUNT - 1),
        ];
    }

    /** Known options, so a test can assert on a specific answer. */
    public function withOptions(array $options, int $correctIndex): static
    {
        return $this->state(fn (): array => [
            'options' => $options,
            'correct_option_index' => $correctIndex,
        ]);
    }

    public function at(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    // ------------------------------------------------- incomplete draft states

    public function withoutCorrectAnswer(): static
    {
        return $this->state(fn (): array => ['correct_option_index' => null]);
    }

    public function withBlankPrompt(): static
    {
        return $this->state(fn (): array => ['prompt' => '']);
    }

    /** Three options instead of four — a draft, not publishable. */
    public function withTooFewOptions(): static
    {
        return $this->state(fn (): array => [
            'options' => ['alpha', 'beta', 'gamma'],
            'correct_option_index' => 0,
        ]);
    }

    /** Differs only by case and whitespace, so publish validation must reject it. */
    public function withDuplicateOptions(): static
    {
        return $this->state(fn (): array => [
            'options' => ['Apple', ' apple ', 'Pear', 'Plum'],
            'correct_option_index' => 0,
        ]);
    }
}
