<?php

declare(strict_types=1);

use App\Enums\QuizStatus;
use App\Enums\RunStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use App\Models\QuizVote;
use App\Models\User;
use App\Support\GuestToken;

/**
 * Phase 4 gate — R4.4, R4.5, R4.6.
 */

// -------------------------------------------------------------- R4.4 unpublish

it('unpublishes a published quiz', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.unpublish', $quiz))
        ->assertRedirect(route('studio.index'));

    expect($quiz->fresh()->status)->toBe(QuizStatus::Unpublished);
});

it('keeps counters and player stats when unpublishing', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->withStats(runs: 20, passes: 6)->create();
    $stat = QuizUserStat::factory()->for($quiz)->passed(5)->create();

    $this->actingAs($creator)->post(route('studio.quizzes.unpublish', $quiz));

    expect($quiz->fresh()->runs_count)->toBe(20)
        ->and($quiz->fresh()->passes_count)->toBe(6)
        ->and($stat->fresh()->passed)->toBeTrue()
        ->and($stat->fresh()->best_reached)->toBe(5);
});

it('leaves creator analytics reachable after unpublishing', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->unpublished()->create();

    expect($creator->can('viewStats', $quiz))->toBeTrue();
});

it('hides an unpublished quiz from everyone but its creator', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->unpublished()->create();

    expect($creator->can('view', $quiz))->toBeTrue()
        ->and(User::factory()->create()->can('view', $quiz))->toBeFalse();
});

it('does not close in-progress runs at the moment of unpublishing', function () {
    // The spec is explicit: they close "on their next request", not here.
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();
    $run = QuizRun::factory()->for($quiz)->create();

    $this->actingAs($creator)->post(route('studio.quizzes.unpublish', $quiz));

    expect($run->fresh()->status)->toBe(RunStatus::InProgress);
});

it('closes another player’s in-progress run on their next request', function () {
    $quiz = Quiz::factory()->unpublished()->create();
    $player = User::factory()->create();
    $run = QuizRun::factory()->for($quiz)->forUser($player)->create();

    $this->actingAs($player)->get(route('home'))->assertOk();

    expect($run->fresh()->status)->toBe(RunStatus::Abandoned)
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and($run->fresh()->current_issued_at)->toBeNull();
});

it('closes a guest’s in-progress run on their next request', function () {
    $quiz = Quiz::factory()->unpublished()->create();
    $token = GuestToken::generate();
    $run = QuizRun::factory()->for($quiz)->forGuest($token)->create();

    $this->withUnencryptedCookie(GuestToken::COOKIE, $token)
        ->get(route('home'))
        ->assertOk();

    expect($run->fresh()->status)->toBe(RunStatus::Abandoned);
});

it('never closes the creator’s own test run on their draft', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->create();
    $run = QuizRun::factory()->for($draft)->forUser($creator)->create();

    $this->actingAs($creator)->get(route('home'))->assertOk();

    expect($run->fresh()->status)->toBe(RunStatus::InProgress);
});

it('leaves runs on published quizzes alone', function () {
    $player = User::factory()->create();
    $run = QuizRun::factory()
        ->for(Quiz::factory()->published()->create())
        ->forUser($player)
        ->create();

    $this->actingAs($player)->get(route('home'));

    expect($run->fresh()->status)->toBe(RunStatus::InProgress);
});

// --------------------------------------------------------------- R4.5 duplicate

it('duplicates a published quiz as a fresh draft', function () {
    $creator = User::factory()->create();

    $original = Quiz::factory()
        ->for($creator)
        ->published()
        ->casual()
        ->unlisted()
        ->withQuestions(5)
        ->withStats(runs: 30, passes: 9)
        ->withUpvotes(12)
        ->create(['title' => 'Phrasal verbs', 'seconds_per_question' => 8]);

    $this->actingAs($creator)
        ->post(route('studio.quizzes.duplicate', $original))
        ->assertRedirect();

    $copy = Quiz::where('id', '!=', $original->id)->sole();

    // Content carries over.
    expect($copy->title)->toBe('Phrasal verbs')
        ->and($copy->mode)->toBe($original->mode)
        ->and($copy->seconds_per_question)->toBe(8)
        ->and($copy->questions)->toHaveCount(5)
        ->and($copy->user_id)->toBe($creator->id);

    // History does not.
    expect($copy->status)->toBe(QuizStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->runs_count)->toBe(0)
        ->and($copy->passes_count)->toBe(0)
        ->and($copy->reached_sum)->toBe(0)
        ->and($copy->upvotes_count)->toBe(0)
        ->and($copy->runs)->toHaveCount(0)
        ->and($copy->votes)->toHaveCount(0)
        ->and($copy->ulid)->not->toBe($original->ulid);
});

it('copies question content and renumbers positions', function () {
    $creator = User::factory()->create();
    $original = Quiz::factory()->for($creator)->published()->create();

    Question::factory()->for($original)->at(3)->withOptions(['a', 'b', 'c', 'd'], 2)->create([
        'prompt' => 'Which one?',
    ]);
    Question::factory()->for($original)->at(9)->withOptions(['w', 'x', 'y', 'z'], 0)->create();

    $this->actingAs($creator)->post(route('studio.quizzes.duplicate', $original));

    $copy = Quiz::where('id', '!=', $original->id)->sole();

    expect($copy->questions->pluck('position')->all())->toBe([1, 2])
        ->and($copy->questions[0]->prompt)->toBe('Which one?')
        ->and($copy->questions[0]->options)->toBe(['a', 'b', 'c', 'd'])
        ->and($copy->questions[0]->correct_option_index)->toBe(2);
});

it('leaves the original untouched when duplicating', function () {
    $creator = User::factory()->create();
    $original = Quiz::factory()->for($creator)->published()->withQuestions(5)->create();

    $this->actingAs($creator)->post(route('studio.quizzes.duplicate', $original));

    expect($original->fresh()->status)->toBe(QuizStatus::Published)
        ->and($original->fresh()->questions)->toHaveCount(5);
});

it('refuses to duplicate a draft, which can just be edited', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->create();

    $this->actingAs($creator)
        ->post(route('studio.quizzes.duplicate', $draft))
        ->assertForbidden();
});

it('refuses to duplicate someone else’s quiz', function () {
    $quiz = Quiz::factory()->published()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('studio.quizzes.duplicate', $quiz))
        ->assertForbidden();
});

// ------------------------------------------------------------------ R4.6 delete

it('hard-deletes a draft and cascades its questions and test runs', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->withQuestions(3)->create();
    QuizRun::factory()->for($draft)->forUser($creator)->create();
    QuizVote::factory()->for($draft)->create();

    $this->actingAs($creator)
        ->delete(route('studio.quizzes.destroy', $draft))
        ->assertRedirect(route('studio.index'));

    expect(Quiz::count())->toBe(0)
        ->and(Question::count())->toBe(0)
        ->and(QuizRun::count())->toBe(0)
        ->and(QuizVote::count())->toBe(0);
});

it('refuses to delete a published quiz', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->published()->create();

    $this->actingAs($creator)
        ->delete(route('studio.quizzes.destroy', $quiz))
        ->assertForbidden();

    expect(Quiz::count())->toBe(1);
});

it('refuses to delete an unpublished quiz', function () {
    $creator = User::factory()->create();
    $quiz = Quiz::factory()->for($creator)->unpublished()->create();

    $this->actingAs($creator)
        ->delete(route('studio.quizzes.destroy', $quiz))
        ->assertForbidden();

    expect(Quiz::count())->toBe(1);
});

it('refuses to delete someone else’s draft', function () {
    $quiz = Quiz::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('studio.quizzes.destroy', $quiz))
        ->assertForbidden();

    expect(Quiz::count())->toBe(1);
});
