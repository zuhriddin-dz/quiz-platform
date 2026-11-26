<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuizMode;
use App\Enums\QuizStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * Drafts by default: that is where every quiz starts, and a test that
     * wants a published one should have to say so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => rtrim($this->faker->sentence(4), '.'),
            'description' => $this->faker->optional()->sentence(12),
            'mode' => QuizMode::Strict,
            'seconds_per_question' => $this->faker->numberBetween(2, 10),
            'status' => QuizStatus::Draft,
            'is_listed' => true,
            'published_at' => null,
        ];
    }

    public function strict(): static
    {
        return $this->state(fn (): array => ['mode' => QuizMode::Strict]);
    }

    public function casual(): static
    {
        return $this->state(fn (): array => ['mode' => QuizMode::Casual]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => QuizStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => [
            'status' => QuizStatus::Unpublished,
            // Unpublished means it WAS published, so published_at stands.
            'published_at' => now()->subDay(),
        ]);
    }

    /** Link-only: reachable by ULID, absent from Explore (R7.1). */
    public function unlisted(): static
    {
        return $this->state(fn (): array => ['is_listed' => false]);
    }

    /**
     * Counters as they would stand after real play. Useful for the 10-run
     * floor on public stats (R7.4) and for suggestion scoring (R8.5).
     */
    public function withStats(int $runs, int $passes, ?int $reachedSum = null): static
    {
        return $this->state(fn (): array => [
            'runs_count' => $runs,
            'passes_count' => $passes,
            'reached_sum' => $reachedSum ?? $runs * 3,
        ]);
    }

    public function withUpvotes(int $count): static
    {
        return $this->state(fn (): array => ['upvotes_count' => $count]);
    }

    /**
     * A publishable quiz: 5-15 complete questions (R4.1).
     */
    public function withQuestions(int $count = 5): static
    {
        return $this->has(QuestionFactory::new()->count($count)->sequence(
            ...array_map(static fn (int $i): array => ['position' => $i], range(1, $count))
        ), 'questions');
    }
}
