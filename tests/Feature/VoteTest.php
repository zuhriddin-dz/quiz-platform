<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\QuizVote;
use App\Models\User;

/**
 * Phase 8 gate — R8.1, R8.2.
 */

// -------------------------------------------------------------------- R8.1

it('adds a vote and removes it again on a second click', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();
    $voter = User::factory()->create();

    $this->actingAs($voter)
        ->from(route('quiz.show', $quiz))
        ->post(route('quiz.vote', $quiz))
        ->assertRedirect(route('quiz.show', $quiz));

    expect(QuizVote::count())->toBe(1)
        ->and($quiz->fresh()->upvotes_count)->toBe(1);

    $this->actingAs($voter)->post(route('quiz.vote', $quiz));

    expect(QuizVote::count())->toBe(0)
        ->and($quiz->fresh()->upvotes_count)->toBe(0);
});

it('counts one vote per user', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs(User::factory()->create())->post(route('quiz.vote', $quiz));
    }

    expect($quiz->fresh()->upvotes_count)->toBe(3)
        ->and(QuizVote::count())->toBe(3);
});

it('does not require having played', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('quiz.vote', $quiz))
        ->assertRedirect();

    expect($quiz->fresh()->upvotes_count)->toBe(1);
});

it('refuses a guest and sends them to sign in', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    $this->post(route('quiz.vote', $quiz))->assertRedirect();

    expect(QuizVote::count())->toBe(0);
});

it('refuses a vote on your own quiz', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withQuestions(5)->create();

    $this->actingAs($creator)
        ->post(route('quiz.vote', $quiz))
        ->assertForbidden();

    expect($quiz->fresh()->upvotes_count)->toBe(0);
});

it('refuses a vote on an unlisted quiz', function () {
    // Shared by link only, so there is no public ranking to influence.
    $quiz = Quiz::factory()->published()->unlisted()->withQuestions(5)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('quiz.vote', $quiz))
        ->assertForbidden();
});

it('refuses a vote on a draft or an unpublished quiz', function () {
    $draft = Quiz::factory()->withQuestions(5)->create();
    $unpublished = Quiz::factory()->unpublished()->withQuestions(5)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('quiz.vote', $draft))
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->post(route('quiz.vote', $unpublished))
        ->assertForbidden();
});

// -------------------------------------------------------------------- R8.2

it('keeps the cached count equal to the rows', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();
    $voters = User::factory()->count(4)->create();

    foreach ($voters as $voter) {
        $this->actingAs($voter)->post(route('quiz.vote', $quiz));
    }

    // Two of them change their mind.
    $this->actingAs($voters[0])->post(route('quiz.vote', $quiz));
    $this->actingAs($voters[1])->post(route('quiz.vote', $quiz));

    expect($quiz->fresh()->upvotes_count)->toBe(QuizVote::where('quiz_id', $quiz->id)->count())
        ->and($quiz->fresh()->upvotes_count)->toBe(2);
});

it('heals a drifted count on the next vote', function () {
    // The count is DERIVED from the rows inside the transaction, not
    // incremented, so a column that has drifted repairs itself.
    $quiz = Quiz::factory()->published()->withQuestions(5)->create(['upvotes_count' => 99]);

    $this->actingAs(User::factory()->create())->post(route('quiz.vote', $quiz));

    expect($quiz->fresh()->upvotes_count)->toBe(1);
});

it('never leaves the count negative', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create(['upvotes_count' => 0]);
    $voter = User::factory()->create();

    // Vote, un-vote, un-vote again — the second removal is a no-op insert.
    $this->actingAs($voter)->post(route('quiz.vote', $quiz));
    $this->actingAs($voter)->post(route('quiz.vote', $quiz));

    expect($quiz->fresh()->upvotes_count)->toBe(0);
});

it('reports the voted state back to the page', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();
    $voter = User::factory()->create();

    $before = $this->actingAs($voter)->get(route('quiz.show', $quiz))
        ->viewData('page')['props'];

    expect($before['can_vote'])->toBeTrue()
        ->and($before['has_voted'])->toBeFalse();

    $this->actingAs($voter)->post(route('quiz.vote', $quiz));

    $after = $this->actingAs($voter)->get(route('quiz.show', $quiz))
        ->viewData('page')['props'];

    expect($after['has_voted'])->toBeTrue()
        ->and($after['quiz']['upvotes_count'])->toBe(1);
});

it('leaves one user’s vote alone when another votes', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->post(route('quiz.vote', $quiz));
    $this->actingAs($second)->post(route('quiz.vote', $quiz));
    $this->actingAs($second)->post(route('quiz.vote', $quiz));

    expect($quiz->fresh()->upvotes_count)->toBe(1)
        ->and(QuizVote::where('user_id', $first->id)->exists())->toBeTrue()
        ->and(QuizVote::where('user_id', $second->id)->exists())->toBeFalse();
});
