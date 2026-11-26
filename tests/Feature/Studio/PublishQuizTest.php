<?php

declare(strict_types=1);

use App\Actions\Quiz\CheckPublishable;
use App\Enums\QuizStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 4 gate — R4.1 and R4.3.
 */
function publishable(User $creator, int $questions = 5): Quiz
{
    return Quiz::factory()->for($creator)->withQuestions($questions)->create();
}

// -------------------------------------------------------------------- R4.3

it('publishes a valid draft as listed', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertRedirect(route('studio.index'))
        ->assertSessionHasNoErrors();

    $quiz->refresh();

    expect($quiz->status)->toBe(QuizStatus::Published)
        ->and($quiz->is_listed)->toBeTrue()
        ->and($quiz->published_at)->not->toBeNull();
});

it('publishes as unlisted when asked', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => false]);

    expect($quiz->fresh()->is_listed)->toBeFalse()
        ->and($quiz->fresh()->status)->toBe(QuizStatus::Published);
});

it('requires an explicit listed choice on every publish', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), [])
        ->assertSessionHasErrors('is_listed');

    expect($quiz->fresh()->status)->toBe(QuizStatus::Draft);
});

it('asks again when republishing, and can flip the visibility', function () {
    $creator = User::factory()->create();

    $quiz = Quiz::factory()->for($creator)->withQuestions(5)->unpublished()->create([
        'is_listed' => true,
    ]);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => false])
        ->assertSessionHasNoErrors();

    expect($quiz->fresh()->is_listed)->toBeFalse()
        ->and($quiz->fresh()->status)->toBe(QuizStatus::Published);
});

it('keeps the first published_at when republishing', function () {
    $creator = User::factory()->create();
    $firstPublish = now()->subMonth();

    $quiz = Quiz::factory()->for($creator)->withQuestions(5)->unpublished()->create([
        'published_at' => $firstPublish,
    ]);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true]);

    expect($quiz->fresh()->published_at->timestamp)->toBe($firstPublish->timestamp);
});

it('refuses to publish a quiz that is already published', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->withQuestions(5)->published()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertForbidden();
});

it('refuses to publish someone else’s draft', function () {
    $quiz = publishable(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertForbidden();
});

// -------------------------------------------------------------------- R4.1

it('refuses fewer than five questions', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator, 4);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');

    expect($quiz->fresh()->status)->toBe(QuizStatus::Draft);
});

it('accepts exactly five and exactly fifteen questions', function (int $count) {
    $creator = User::factory()->create();
    $quiz = publishable($creator, $count);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasNoErrors();

    expect($quiz->fresh()->status)->toBe(QuizStatus::Published);
})->with([5, 15]);

it('refuses more than fifteen questions', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator, 16);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');
});

it('refuses a question with a blank prompt', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);
    Question::factory()->for($quiz)->at(6)->withBlankPrompt()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');
});

it('refuses a question with no correct answer chosen', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);
    Question::factory()->for($quiz)->at(6)->withoutCorrectAnswer()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');
});

it('refuses a question with fewer than four options', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);
    Question::factory()->for($quiz)->at(6)->withTooFewOptions()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');
});

it('refuses options that differ only by case and whitespace', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);
    Question::factory()->for($quiz)->at(6)->withDuplicateOptions()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.publish', $quiz), ['is_listed' => true])
        ->assertSessionHasErrors('publish');
});

it('names the offending question positions in the blocker', function () {
    $creator = User::factory()->create();
    $quiz = publishable($creator);
    Question::factory()->for($quiz)->at(6)->withoutCorrectAnswer()->create();
    Question::factory()->for($quiz)->at(7)->withBlankPrompt()->create();

    $blockers = app(CheckPublishable::class)->blockers($quiz->fresh());

    expect($blockers)->toHaveCount(1)
        ->and($blockers[0])->toContain('6')
        ->and($blockers[0])->toContain('7');
});

it('reports no blockers for a complete quiz', function () {
    expect(app(CheckPublishable::class)->passes(publishable(User::factory()->create())))
        ->toBeTrue();
});
