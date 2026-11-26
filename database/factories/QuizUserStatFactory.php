<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizUserStat>
 */
class QuizUserStatFactory extends Factory
{
    /**
     * Attempted but not passed — the common case for a sudden-death quiz.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quiz_id' => Quiz::factory()->published(),
            'attempts' => $this->faker->numberBetween(1, 8),
            'best_reached' => $this->faker->numberBetween(0, 4),
            'passed' => false,
            'first_passed_at' => null,
            'last_played_at' => now(),
        ];
    }

    public function passed(int $bestReached = 5): static
    {
        return $this->state(fn (): array => [
            'passed' => true,
            'best_reached' => $bestReached,
            'first_passed_at' => now()->subHour(),
        ]);
    }

    public function lastPlayed(\DateTimeInterface $at): static
    {
        return $this->state(fn (): array => ['last_played_at' => $at]);
    }
}
