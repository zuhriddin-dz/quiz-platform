<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        /*
         * Options must be distinct after trimming and case-folding (R4.1).
         *
         * `$this->faker->unique()->words(4)` does NOT do that: unique()
         * applies to the ARRAY words() returns, not to the words inside it,
         * so a single question could get ['dog', 'cat', 'dog', 'bird'] and
         * silently fail publish validation. That is what made
         * `withQuestions(15)` unpublishable about half the time.
         *
         * Building from one stem with distinct suffixes guarantees it, with
         * no reliance on faker's uniqueness pool (which 15 questions x 4
         * options can exhaust).
         */
        $stem = $this->faker->word().'-'.Str::lower(Str::random(6));

        $options = array_map(
            static fn (int $index): string => "{$stem}-{$index}",
            range(1, Question::OPTION_COUNT),
        );

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
