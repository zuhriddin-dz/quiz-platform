<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FailureReason;
use App\Enums\QuizMode;
use App\Enums\RunStatus;
use App\Models\Quiz;
use App\Models\User;
use App\Support\GuestToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuizRun>
 */
class QuizRunFactory extends Factory
{
    /**
     * A signed-in player's in-progress strict run.
     *
     * Note the model guard: exactly one of user_id / guest_token (R2.3). The
     * `forGuest()` state nulls the user, it does not merely add a token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory()->published(),
            'user_id' => User::factory(),
            'guest_token' => null,
            'mode' => QuizMode::Strict,
            'question_order' => [],
            'option_orders' => null,
            'salt' => null,
            'current_index' => 0,
            'current_issued_at' => now(),
            'status' => RunStatus::InProgress,
            'failure_reason' => null,
            'reached' => 0,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    /** R2.3 — a guest run has a token and NO user. */
    public function forGuest(?string $token = null): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'guest_token' => $token ?? GuestToken::generate(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'guest_token' => null,
        ]);
    }

    /**
     * The shuffle as stored at start. Pass the question ids in play order.
     *
     * @param  array<int, int>  $questionIds
     */
    public function withOrder(array $questionIds, ?array $optionOrders = null): static
    {
        return $this->state(fn (): array => [
            'question_order' => $questionIds,
            'option_orders' => $optionOrders ?? array_map(
                static fn (): array => [0, 1, 2, 3],
                array_flip($questionIds),
            ),
        ]);
    }

    public function casual(): static
    {
        return $this->state(fn (): array => [
            'mode' => QuizMode::Casual,
            // Casual shuffles client-side, so the server stores no permutation.
            'option_orders' => null,
            'salt' => bin2hex(random_bytes(16)),
        ]);
    }

    public function passed(int $reached = 5): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Passed,
            'reached' => $reached,
            'failure_reason' => null,
            'current_issued_at' => null,
            'finished_at' => now(),
        ]);
    }

    public function failed(
        FailureReason $reason = FailureReason::WrongAnswer,
        int $reached = 2,
    ): static {
        return $this->state(fn (): array => [
            'status' => RunStatus::Failed,
            'failure_reason' => $reason,
            'reached' => $reached,
            'current_issued_at' => null,
            'finished_at' => now(),
        ]);
    }

    /** Superseded by a new run, or closed by an unpublish (R4.4). */
    public function abandoned(): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Abandoned,
            'current_issued_at' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * A run whose question was issued long enough ago to be past the timer,
     * for the strict timing tests (R5.3).
     */
    public function issuedSecondsAgo(float $seconds): static
    {
        return $this->state(fn (): array => [
            'current_issued_at' => now()->subMilliseconds((int) round($seconds * 1000)),
        ]);
    }
}
