<?php

declare(strict_types=1);

use App\Enums\QuizMode;
use App\Exceptions\ImmutableQuizException;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 4 gate — R4.2, the second layer.
 *
 * StudioAuthorizationTest already covers the request layer (403s from
 * QuizPolicy). These go around HTTP entirely and write to the model, which is
 * what a future bug, a tinker session or a seeder would do.
 */

it('refuses to change the title of a published quiz', function () {
    $quiz = Quiz::factory()->published()->create();

    $quiz->update(['title' => 'Changed']);
})->throws(ImmutableQuizException::class);

it('refuses to change the mode of a published quiz', function () {
    $quiz = Quiz::factory()->published()->strict()->create();

    $quiz->update(['mode' => QuizMode::Casual]);
})->throws(ImmutableQuizException::class);

it('refuses to change the timer of a published quiz', function () {
    $quiz = Quiz::factory()->published()->create(['seconds_per_question' => 5]);

    $quiz->update(['seconds_per_question' => 10]);
})->throws(ImmutableQuizException::class);

it('refuses to change the description of a published quiz', function () {
    $quiz = Quiz::factory()->published()->create();

    $quiz->update(['description' => 'Second thoughts']);
})->throws(ImmutableQuizException::class);

it('refuses to reassign a published quiz to another creator', function () {
    $quiz = Quiz::factory()->published()->create();

    $quiz->update(['user_id' => User::factory()->create()->id]);
})->throws(ImmutableQuizException::class);

it('refuses content changes on an unpublished quiz too', function () {
    // Unpublished is published content merely hidden, not reopened for edits.
    $quiz = Quiz::factory()->unpublished()->create();

    $quiz->update(['title' => 'Changed']);
})->throws(ImmutableQuizException::class);

it('still allows the publish transition itself', function () {
    $quiz = Quiz::factory()->unpublished()->create();

    $quiz->update(['status' => App\Enums\QuizStatus::Published, 'is_listed' => false]);

    expect($quiz->fresh()->status)->toBe(App\Enums\QuizStatus::Published)
        ->and($quiz->fresh()->is_listed)->toBeFalse();
});

it('still allows the counters to move on a published quiz', function () {
    // Every finished run increments these, so freezing them would break play.
    $quiz = Quiz::factory()->published()->create();

    $quiz->increment('runs_count');
    $quiz->increment('passes_count');
    $quiz->increment('reached_sum', 4);
    $quiz->increment('upvotes_count');

    expect($quiz->fresh()->runs_count)->toBe(1)
        ->and($quiz->fresh()->passes_count)->toBe(1)
        ->and($quiz->fresh()->reached_sum)->toBe(4)
        ->and($quiz->fresh()->upvotes_count)->toBe(1);
});

it('allows every content change while still a draft', function () {
    $quiz = Quiz::factory()->create();

    $quiz->update([
        'title' => 'Changed',
        'description' => 'Also changed',
        'mode' => QuizMode::Casual,
        'seconds_per_question' => 9,
    ]);

    expect($quiz->fresh()->title)->toBe('Changed')
        ->and($quiz->fresh()->seconds_per_question)->toBe(9);
});

// ------------------------------------------------------------------ questions

it('refuses to add a question to a published quiz', function () {
    $quiz = Quiz::factory()->published()->create();

    Question::factory()->for($quiz)->create();
})->throws(ImmutableQuizException::class);

it('refuses to change a question on a published quiz', function () {
    $quiz = Quiz::factory()->withQuestions(5)->create();
    $question = $quiz->questions->first();

    $quiz->update(['status' => App\Enums\QuizStatus::Published, 'published_at' => now()]);

    $question->update(['prompt' => 'Rewritten']);
})->throws(ImmutableQuizException::class);

it('refuses to delete a question from a published quiz', function () {
    $quiz = Quiz::factory()->withQuestions(5)->create();
    $question = $quiz->questions->first();

    $quiz->update(['status' => App\Enums\QuizStatus::Published, 'published_at' => now()]);

    $question->delete();
})->throws(ImmutableQuizException::class);

it('allows questions to change while the quiz is a draft', function () {
    $quiz = Quiz::factory()->withQuestions(2)->create();

    $quiz->questions->first()->update(['prompt' => 'Fine to change']);
    Question::factory()->for($quiz)->at(3)->create();

    expect($quiz->fresh()->questions)->toHaveCount(3);
});

// -------------------------------------------------------------------- delete

it('refuses a model-level delete of a published quiz', function () {
    Quiz::factory()->published()->create()->delete();
})->throws(ImmutableQuizException::class);

it('refuses a model-level delete of an unpublished quiz', function () {
    Quiz::factory()->unpublished()->create()->delete();
})->throws(ImmutableQuizException::class);

it('allows a model-level delete of a draft', function () {
    Quiz::factory()->withQuestions(3)->create()->delete();

    expect(Quiz::count())->toBe(0);
});
