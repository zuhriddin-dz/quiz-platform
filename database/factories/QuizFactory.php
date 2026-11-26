<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuizMode;
use App\Enums\QuizStatus;
use App\Models\Quiz;
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

    /**
     * Laravel guesses a `for()` relationship name from the related model's
     * class: `User` becomes `user()`. This model calls that relationship
     * `creator()` instead, because "creator" is what a quiz's owner is
     * everywhere else in the app — so `Quiz::factory()->for($user)` threw
     * "Call to undefined method App\Models\Quiz::user()".
     *
     * Mapping it here rather than passing `for($user, 'creator')` at ~80 call
     * sites keeps the tests reading the way they should, and covers the
     * multi-line and seeder cases a search-and-replace would have missed.
     */
    public function for($factory, $relationship = null): static
    {
        if ($relationship === null && ($factory instanceof User || $factory instanceof UserFactory)) {
            $relationship = 'creator';
        }

        return parent::for($factory, $relationship);
    }

    public function strict(): static
    {
        return $this->state(fn (): array => ['mode' => QuizMode::Strict]);
    }

    public function casual(): static
    {
        return $this->state(fn (): array => ['mode' => QuizMode::Casual]);
    }

    /*
     * `published()` and `unpublished()` set the status in afterCreating, not
     * in the state, and the reason is Phase 4's immutability guard.
     *
     * Laravel creates `has()` children BEFORE running afterCreating callbacks.
     * If the status were part of the state, `published()->withQuestions(5)`
     * would insert the quiz as published and then try to add questions to it —
     * which `Question`'s guard correctly refuses. Flipping the status after the
     * children exist mirrors what really happens: a draft is written, filled,
     * and only then published.
     *
     * `saveQuietly()` because the Quiz guard would otherwise see a status
     * change on a row it has already decided is immutable.
     */
    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()])
            ->afterCreating(function (Quiz $quiz): void {
                $quiz->status = QuizStatus::Published;
                $quiz->saveQuietly();
            });
    }

    public function unpublished(): static
    {
        // Unpublished means it WAS published, so published_at stands.
        return $this->state(fn (): array => ['published_at' => now()->subDay()])
            ->afterCreating(function (Quiz $quiz): void {
                $quiz->status = QuizStatus::Unpublished;
                $quiz->saveQuietly();
            });
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

    /**
     * Specific questions, rather than `withQuestions`' generated ones.
     *
     * Goes through `has()` so the questions are created while the quiz is
     * still a draft — which is what makes this usable together with
     * `published()`. Creating a question on an already-published quiz is
     * refused by design (R4.2), so tests that need known options on a
     * published quiz must come through here.
     *
     * @param  QuestionFactory  ...$questions
     */
    public function withSpecificQuestions(QuestionFactory ...$questions): static
    {
        $factory = $this;

        foreach ($questions as $question) {
            $factory = $factory->has($question, 'questions');
        }

        return $factory;
    }
}
