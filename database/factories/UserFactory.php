<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Google's `sub` is an opaque numeric string, not an int.
            'google_id' => (string) $this->faker->unique()->numerify('####################'),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'avatar_url' => 'https://lh3.googleusercontent.com/a/'.Str::random(32),
        ];
    }

    /**
     * A user whose Google profile has no picture set.
     */
    public function withoutAvatar(): static
    {
        return $this->state(fn (): array => ['avatar_url' => null]);
    }
}
