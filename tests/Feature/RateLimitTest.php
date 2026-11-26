<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\User;
use App\Support\GuestToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 10 gate — R10.1 / §11.
 *
 * The limiter is cache-backed and `phpunit.xml` pins CACHE_STORE to `array`,
 * so each test starts with an empty bucket. The explicit clear below guards
 * against ordering surprises anyway.
 */
beforeEach(function (): void {
    RateLimiter::clear('run:user:1');
    config(['quiz.rate_limits.start_run_per_minute' => 3]);
    config(['quiz.rate_limits.vote_per_minute' => 3]);
});

function limitedQuiz(): Quiz
{
    return Quiz::factory()->published()->strict()->withQuestions(5)->create();
}

// ------------------------------------------------------------ starting runs

it('throttles a signed-in player starting runs', function () {
    $quiz = limitedQuiz();
    $player = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($player)
            ->postJson(route('runs.store', $quiz))
            ->assertCreated();
    }

    $this->actingAs($player)
        ->postJson(route('runs.store', $quiz))
        ->assertStatus(429);
});

it('throttles each player separately', function () {
    $quiz = limitedQuiz();
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($first)->postJson(route('runs.store', $quiz));
    }

    // The second player's bucket is untouched.
    $this->actingAs($second)
        ->postJson(route('runs.store', $quiz))
        ->assertCreated();
});

it('throttles a guest by token and IP together', function () {
    $quiz = limitedQuiz();
    $token = GuestToken::generate();

    foreach (range(1, 3) as $ignored) {
        $this->withUnencryptedCookie(GuestToken::COOKIE, $token)
            ->postJson(route('runs.store', $quiz))
            ->assertCreated();
    }

    $this->withUnencryptedCookie(GuestToken::COOKIE, $token)
        ->postJson(route('runs.store', $quiz))
        ->assertStatus(429);
});

it('does not throttle a different guest token on the same IP', function () {
    // The token alone is clearable, so it is combined with the IP rather than
    // replaced by it — but two real guests behind one address must not block
    // each other.
    $quiz = limitedQuiz();

    foreach (range(1, 3) as $ignored) {
        $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
            ->postJson(route('runs.store', $quiz));
    }

    $this->withUnencryptedCookie(GuestToken::COOKIE, GuestToken::generate())
        ->postJson(route('runs.store', $quiz))
        ->assertCreated();
});

it('counts runs across quizzes toward the same limit', function () {
    // The limit is on the player, not the quiz: otherwise the cap is
    // multiplied by however many quizzes exist.
    $player = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($player)->postJson(route('runs.store', limitedQuiz()));
    }

    $this->actingAs($player)
        ->postJson(route('runs.store', limitedQuiz()))
        ->assertStatus(429);
});

it('reads the limit from config', function () {
    config(['quiz.rate_limits.start_run_per_minute' => 1]);

    $quiz = limitedQuiz();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz))->assertCreated();
    $this->actingAs($player)->postJson(route('runs.store', $quiz))->assertStatus(429);
});

// -------------------------------------------------------------------- voting

it('throttles voting per user', function () {
    $voter = User::factory()->create();

    // Three different quizzes, so the toggle does not simply undo itself.
    foreach (range(1, 3) as $ignored) {
        $this->actingAs($voter)
            ->post(route('quiz.vote', Quiz::factory()->published()->withQuestions(5)->create()))
            ->assertRedirect();
    }

    $this->actingAs($voter)
        ->post(route('quiz.vote', Quiz::factory()->published()->withQuestions(5)->create()))
        ->assertStatus(429);
});

it('throttles each voter separately', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs(User::factory()->create())->post(route('quiz.vote', $quiz));
    }

    $this->actingAs(User::factory()->create())
        ->post(route('quiz.vote', $quiz))
        ->assertRedirect();
});

// ------------------------------------------------------------- not throttled

it('does not throttle answering', function () {
    /*
     * Deliberate: a strict run sends one request per question under a 2-10
     * second timer, so a limit tight enough to matter would fail honest
     * players on a fast quiz. Sudden death already bounds how many answers a
     * run can produce.
     */
    $quiz = Quiz::factory()->published()->strict()->withQuestions(15)->create();
    $player = User::factory()->create();

    $this->actingAs($player)->postJson(route('runs.store', $quiz))->assertCreated();
    $run = App\Models\QuizRun::sole();

    // Walk the whole quiz: 15 answers, well past the 3/min start limit.
    foreach (range(1, 15) as $step) {
        $run->refresh();
        $question = $quiz->questions->firstWhere('id', $run->currentQuestionId());
        $permutation = $run->option_orders[(string) $question->id];
        $correct = (int) array_search($question->correct_option_index, $permutation, strict: true);

        $this->actingAs($player)
            ->postJson(route('runs.answer', $run), ['displayed_index' => $correct])
            ->assertOk();
    }

    expect($run->fresh()->status->value)->toBe('passed');
});

it('does not throttle reading pages', function () {
    $quiz = limitedQuiz();

    foreach (range(1, 10) as $ignored) {
        $this->get(route('quiz.show', $quiz))->assertOk();
        $this->get(route('home'))->assertOk();
    }
});
