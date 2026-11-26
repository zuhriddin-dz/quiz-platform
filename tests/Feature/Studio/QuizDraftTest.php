<?php

declare(strict_types=1);

use App\Enums\QuizMode;
use App\Enums\QuizStatus;
use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 3 gate — R3.1, R3.2, R3.4 (the quiz's own fields).
 */

// -------------------------------------------------------------------- R3.1

it('lists only the signed-in creator’s quizzes', function () {
    $me = User::factory()->create();
    $someoneElse = User::factory()->create();

    Quiz::factory()->for($me)->create(['title' => 'Mine']);
    Quiz::factory()->for($someoneElse)->create(['title' => 'Theirs']);

    $response = $this->actingAs($me)->get(route('studio.index'));

    $response->assertOk();

    $quizzes = $response->viewData('page')['props']['quizzes'];

    expect($quizzes)->toHaveCount(1)
        ->and($quizzes[0]['title'])->toBe('Mine');
});

it('shows status and visibility for each quiz', function () {
    $me = User::factory()->create();

    Quiz::factory()->for($me)->published()->unlisted()->create();

    $quiz = $this->actingAs($me)
        ->get(route('studio.index'))
        ->viewData('page')['props']['quizzes'][0];

    expect($quiz['status'])->toBe('published')
        ->and($quiz['is_listed'])->toBeFalse()
        // Published quizzes are immutable, so the row must not offer Edit.
        ->and($quiz['can']['update'])->toBeFalse();
});

it('requires sign-in for the studio', function () {
    $this->get(route('studio.index'))->assertRedirect();
});

// -------------------------------------------------------------------- R3.2

it('creates a draft and lands on the editor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('studio.quizzes.store'), [
        'title' => 'Phrasal verbs',
        'description' => 'The awkward ones.',
        'mode' => 'casual',
        'seconds_per_question' => 7,
    ]);

    $quiz = Quiz::sole();

    $response->assertRedirect(route('studio.quizzes.edit', $quiz));

    expect($quiz->user_id)->toBe($user->id)
        ->and($quiz->status)->toBe(QuizStatus::Draft)
        ->and($quiz->mode)->toBe(QuizMode::Casual)
        ->and($quiz->seconds_per_question)->toBe(7)
        ->and($quiz->ulid)->toHaveLength(26)
        // A new draft starts empty — that is the point of R3.4.
        ->and($quiz->questions)->toHaveCount(0);
});

it('rejects a title over 80 characters', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.store'), [
            'title' => str_repeat('a', 81),
            'mode' => 'strict',
            'seconds_per_question' => 5,
        ])
        ->assertSessionHasErrors('title');

    expect(Quiz::count())->toBe(0);
});

it('rejects a description over 500 characters', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.store'), [
            'title' => 'Fine',
            'description' => str_repeat('a', 501),
            'mode' => 'strict',
            'seconds_per_question' => 5,
        ])
        ->assertSessionHasErrors('description');
});

it('keeps the timer between 2 and 10 seconds', function (int $seconds, bool $valid) {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.store'), [
            'title' => 'Timer',
            'mode' => 'strict',
            'seconds_per_question' => $seconds,
        ]);

    $valid
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors('seconds_per_question');
})->with([
    [1, false],
    [2, true],
    [10, true],
    [11, false],
]);

it('rejects an unknown mode', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.store'), [
            'title' => 'Mode',
            'mode' => 'sudden-death',
            'seconds_per_question' => 5,
        ])
        ->assertSessionHasErrors('mode');
});

it('updates the quiz fields of a draft', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create(['title' => 'Before']);

    $this->actingAs($user)
        ->put(route('studio.quizzes.update', $quiz), [
            'title' => 'After',
            'description' => null,
            'mode' => 'strict',
            'seconds_per_question' => 4,
            'questions' => [],
        ])
        ->assertSessionHasNoErrors();

    expect($quiz->fresh()->title)->toBe('After')
        ->and($quiz->fresh()->seconds_per_question)->toBe(4);
});
