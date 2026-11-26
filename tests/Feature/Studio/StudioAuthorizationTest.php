<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 3 gate — R3.5.
 *
 * Two separate rules live here, and they fail differently:
 *   - not the creator        -> 403
 *   - the creator, but not a draft -> 403, because published quizzes are
 *     immutable permanently (§5), not merely locked
 */

it('refuses the editor to a different user', function () {
    $quiz = Quiz::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->get(route('studio.quizzes.edit', $quiz))
        ->assertForbidden();
});

it('refuses an update from a different user', function () {
    $quiz = Quiz::factory()->for(User::factory())->create(['title' => 'Untouched']);

    $this->actingAs(User::factory()->create())
        ->put(route('studio.quizzes.update', $quiz), [
            'title' => 'Hijacked',
            'mode' => 'strict',
            'seconds_per_question' => 5,
            'questions' => [],
        ])
        ->assertForbidden();

    expect($quiz->fresh()->title)->toBe('Untouched');
});

it('refuses to edit a published quiz even for its creator', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();

    $this->actingAs($creator)
        ->get(route('studio.quizzes.edit', $quiz))
        ->assertForbidden();
});

it('refuses to update a published quiz even for its creator', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create(['title' => 'Frozen']);

    $this->actingAs($creator)
        ->put(route('studio.quizzes.update', $quiz), [
            'title' => 'Changed',
            'mode' => 'strict',
            'seconds_per_question' => 5,
            'questions' => [],
        ])
        ->assertForbidden();

    expect($quiz->fresh()->title)->toBe('Frozen');
});

it('refuses to edit an unpublished quiz, which is published content merely hidden', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->unpublished()->create();

    $this->actingAs($creator)
        ->get(route('studio.quizzes.edit', $quiz))
        ->assertForbidden();
});

it('lets the creator edit their own draft', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->create();

    $this->actingAs($creator)
        ->get(route('studio.quizzes.edit', $quiz))
        ->assertOk();
});

it('sends guests to sign in rather than refusing them', function () {
    $quiz = Quiz::factory()->create();

    $this->get(route('studio.quizzes.edit', $quiz))->assertRedirect();
    $this->get(route('studio.quizzes.create'))->assertRedirect();
});

// The policy's own rules, independent of HTTP.
it('allows viewing a published quiz by anyone, and a draft only by its creator', function () {
    $creator = User::factory()->create();
    $stranger = User::factory()->create();

    $draft = Quiz::factory()->for($creator)->create();
    $published = Quiz::factory()->for($creator)->published()->create();
    $unpublished = Quiz::factory()->for($creator)->unpublished()->create();

    expect($creator->can('view', $draft))->toBeTrue()
        ->and($stranger->can('view', $draft))->toBeFalse()
        ->and($stranger->can('view', $published))->toBeTrue()
        ->and($stranger->can('view', $unpublished))->toBeFalse()
        ->and($creator->can('view', $unpublished))->toBeTrue();
});

it('offers duplicate only once a quiz is no longer a draft', function () {
    $creator = User::factory()->create();

    expect($creator->can('duplicate', Quiz::factory()->for($creator)->create()))->toBeFalse()
        ->and($creator->can('duplicate', Quiz::factory()->for($creator)->published()->create()))->toBeTrue();
});

it('offers publish on a draft and on an unpublished quiz, but not on a live one', function () {
    $creator = User::factory()->create();

    expect($creator->can('publish', Quiz::factory()->for($creator)->create()))->toBeTrue()
        ->and($creator->can('publish', Quiz::factory()->for($creator)->unpublished()->create()))->toBeTrue()
        ->and($creator->can('publish', Quiz::factory()->for($creator)->published()->create()))->toBeFalse();
});
