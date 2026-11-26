<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\QuizUserStat;
use App\Models\User;
use App\Queries\SuggestedQuizzes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 gate — R8.3 to R8.7.
 */
function suggestionsFor(Quiz $quiz, ?User $viewer = null): array
{
    $request = $viewer === null ? test() : test()->actingAs($viewer);

    return $request->get(route('quiz.show', $quiz))
        ->assertOk()
        ->viewData('page')['props']['suggestions'];
}

function published(?User $creator = null, int $upvotes = 0): Quiz
{
    $factory = Quiz::factory()->published()->withQuestions(5)->withUpvotes($upvotes);

    return ($creator === null ? $factory : $factory->for($creator))->create();
}

// -------------------------------------------------------------- R8.3 candidates

it('suggests other published listed quizzes', function () {
    $current = published();
    $other = published();

    expect(collect(suggestionsFor($current))->pluck('ulid')->all())->toBe([$other->ulid]);
});

it('never suggests the quiz being viewed', function () {
    $current = published();

    expect(collect(suggestionsFor($current))->pluck('ulid'))->not->toContain($current->ulid);
});

it('excludes drafts, unpublished and unlisted quizzes', function () {
    $current = published();

    Quiz::factory()->withQuestions(5)->create();
    Quiz::factory()->unpublished()->withQuestions(5)->create();
    Quiz::factory()->published()->unlisted()->withQuestions(5)->create();

    expect(suggestionsFor($current))->toHaveCount(0);
});

it('excludes quizzes the viewer has already passed', function () {
    $viewer = User::factory()->create();
    $current = published();
    $passed = published();
    $notPassed = published();

    QuizUserStat::factory()->for($viewer)->for($passed)->passed(5)->create();

    expect(collect(suggestionsFor($current, $viewer))->pluck('ulid')->all())
        ->toBe([$notPassed->ulid]);
});

it('keeps a quiz the viewer attempted but did not pass', function () {
    $viewer = User::factory()->create();
    $current = published();
    $attempted = published();

    QuizUserStat::factory()->for($viewer)->for($attempted)->create(['passed' => false]);

    expect(collect(suggestionsFor($current, $viewer))->pluck('ulid')->all())
        ->toBe([$attempted->ulid]);
});

it('suggests to guests, who have nothing to exclude', function () {
    $current = published();
    $other = published();

    expect(collect(suggestionsFor($current))->pluck('ulid')->all())->toBe([$other->ulid]);
});

it('returns at most twelve', function () {
    $current = published();

    // 20 candidates, each by a different creator so the per-creator cap does
    // not interfere with the limit.
    foreach (range(1, 20) as $index) {
        published(User::factory()->create(), upvotes: $index);
    }

    expect(suggestionsFor($current))->toHaveCount(12);
});

// ------------------------------------------------------------------ R8.4 score

it('computes the documented score', function () {
    $weights = ['author' => 3.0, 'stats' => 2.0, 'votes' => 1.0];
    $query = app(SuggestedQuizzes::class);

    $creator = User::factory()->create();

    // Both quizzes over the 10-run floor, so the stats term is real.
    $current = Quiz::factory()->for($creator)->published()
        ->withStats(runs: 20, passes: 10)->create();          // pass rate 0.5
    $sameCreator = Quiz::factory()->for($creator)->published()->withUpvotes(9)
        ->withStats(runs: 20, passes: 12)->create();          // pass rate 0.6

    // 3*1 + 2*(1 - 0.1) + 1*log10(10) = 3 + 1.8 + 1 = 5.8
    expect($query->score($current, $sameCreator, $weights, 0.5))
        ->toBeGreaterThan(5.79)
        ->toBeLessThan(5.81);

    $otherCreator = Quiz::factory()->published()->withUpvotes(0)
        ->withStats(runs: 20, passes: 10)->create();          // pass rate 0.5

    // 3*0 + 2*(1 - 0) + 1*log10(1) = 2
    expect($query->score($current, $otherCreator, $weights, 0.5))
        ->toBeGreaterThan(1.99)
        ->toBeLessThan(2.01);
});

it('gives zero for the votes term at zero upvotes', function () {
    // log10(1 + 0) = 0, which is why the formula adds 1.
    $query = app(SuggestedQuizzes::class);
    $weights = ['author' => 0.0, 'stats' => 0.0, 'votes' => 1.0];

    expect($query->score(published(), published(), $weights, 0.5))->toBe(0.0);
});

it('prefers the same creator', function () {
    $creator = User::factory()->create();
    $current = published($creator);

    $theirs = published($creator);
    $strangers = published(User::factory()->create(), upvotes: 5);

    // The author bonus of 3 outweighs log10(6) ~= 0.78.
    expect(collect(suggestionsFor($current))->pluck('ulid')->first())->toBe($theirs->ulid);
});

it('prefers more upvotes among strangers', function () {
    $current = published();
    $quiet = published(User::factory()->create(), upvotes: 1);
    $loud = published(User::factory()->create(), upvotes: 100);

    expect(collect(suggestionsFor($current))->pluck('ulid')->all())
        ->toBe([$loud->ulid, $quiet->ulid]);
});

it('reads the weights from config', function () {
    config(['quiz.suggestions.weights.author' => 0]);

    $creator = User::factory()->create();
    $current = published($creator);

    $theirs = published($creator, upvotes: 0);
    $strangers = published(User::factory()->create(), upvotes: 50);

    // With no author bonus, upvotes decide.
    expect(collect(suggestionsFor($current))->pluck('ulid')->first())->toBe($strangers->ulid);
});

// ------------------------------------------------------------ R8.5 neutral term

it('uses the neutral stats term when the current quiz has too few runs', function () {
    $query = app(SuggestedQuizzes::class);
    $weights = ['author' => 0.0, 'stats' => 2.0, 'votes' => 0.0];

    $current = Quiz::factory()->published()->withStats(runs: 9, passes: 0)->create();
    $candidate = Quiz::factory()->published()->withStats(runs: 50, passes: 25)->create();

    // 2 * 0.5, not 2 * (1 - |null - 0.5|).
    expect($query->score($current, $candidate, $weights, 0.5))->toBe(1.0);
});

it('uses the neutral stats term when the candidate has too few runs', function () {
    $query = app(SuggestedQuizzes::class);
    $weights = ['author' => 0.0, 'stats' => 2.0, 'votes' => 0.0];

    $current = Quiz::factory()->published()->withStats(runs: 50, passes: 25)->create();
    $candidate = Quiz::factory()->published()->withStats(runs: 2, passes: 1)->create();

    expect($query->score($current, $candidate, $weights, 0.5))->toBe(1.0);
});

it('uses the real stats term once both clear the floor', function () {
    $query = app(SuggestedQuizzes::class);
    $weights = ['author' => 0.0, 'stats' => 2.0, 'votes' => 0.0];

    $current = Quiz::factory()->published()->withStats(runs: 10, passes: 5)->create();
    $identical = Quiz::factory()->published()->withStats(runs: 10, passes: 5)->create();
    $opposite = Quiz::factory()->published()->withStats(runs: 10, passes: 10)->create();

    // Identical pass rates: 2 * (1 - 0) = 2. Half apart: 2 * (1 - 0.5) = 1.
    expect($query->score($current, $identical, $weights, 0.5))->toBe(2.0)
        ->and($query->score($current, $opposite, $weights, 0.5))->toBe(1.0);
});

// --------------------------------------------------------------- R8.6 the cap

it('allows at most three from the same creator', function () {
    $prolific = User::factory()->create();
    $current = published();

    foreach (range(1, 6) as $index) {
        published($prolific, upvotes: $index * 10);
    }

    $suggestions = suggestionsFor($current);

    expect($suggestions)->toHaveCount(3);
});

it('keeps a creator’s three best, not their first three', function () {
    $prolific = User::factory()->create();
    $current = published();

    $low = collect(range(1, 3))->map(fn (int $i): Quiz => published($prolific, upvotes: $i));
    $high = collect([50, 60, 70])->map(fn (int $u): Quiz => published($prolific, upvotes: $u));

    $suggested = collect(suggestionsFor($current))->pluck('ulid');

    expect($suggested)->toHaveCount(3);

    foreach ($high as $quiz) {
        expect($suggested)->toContain($quiz->ulid);
    }

    foreach ($low as $quiz) {
        expect($suggested)->not->toContain($quiz->ulid);
    }
});

it('does not let the cap starve the list of other creators', function () {
    $prolific = User::factory()->create();
    $current = published();

    foreach (range(1, 8) as $index) {
        published($prolific, upvotes: 100 + $index);
    }

    $others = collect(range(1, 4))->map(fn (): Quiz => published(User::factory()->create()));

    $suggested = collect(suggestionsFor($current))->pluck('ulid');

    // 3 from the prolific creator + the 4 others.
    expect($suggested)->toHaveCount(7);

    foreach ($others as $quiz) {
        expect($suggested)->toContain($quiz->ulid);
    }
});

it('reads the cap from config', function () {
    config(['quiz.suggestions.per_creator' => 1]);

    $prolific = User::factory()->create();
    $current = published();

    foreach (range(1, 4) as $index) {
        published($prolific, upvotes: $index);
    }

    expect(suggestionsFor($current))->toHaveCount(1);
});

// -------------------------------------------------------------------- R8.7

it('runs on SQLite without needing log10 in SQL', function () {
    // The score is computed in PHP precisely so this passes on both drivers:
    // SQLite's log10 is a compile-time option.
    $current = published();
    published(User::factory()->create(), upvotes: 12);

    expect(suggestionsFor($current))->toHaveCount(1);
});

it('uses one query for the candidates', function () {
    $current = published();
    foreach (range(1, 5) as $ignored) {
        published(User::factory()->create());
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    app(SuggestedQuizzes::class)->for($current->fresh(), null);

    // The candidate select, plus the eager loads for creator and question
    // counts. No per-candidate lookups, and no background job.
    expect($queries)->toBeLessThanOrEqual(3);
});

it('returns card payloads, not models', function () {
    $current = published();
    published();

    $suggestion = suggestionsFor($current)[0];

    expect(array_keys($suggestion))->toBe([
        'ulid',
        'title',
        'creator',
        'mode',
        'mode_label',
        'question_count',
        'seconds_per_question',
        'upvotes_count',
        'runs_count',
    ]);
});

it('is deterministic across reloads', function () {
    $current = published();

    // Three candidates with identical scores: same creator-less state, same
    // upvotes, same run counts.
    foreach (range(1, 3) as $ignored) {
        published(User::factory()->create(), upvotes: 5);
    }

    $first = collect(suggestionsFor($current))->pluck('ulid')->all();
    $second = collect(suggestionsFor($current))->pluck('ulid')->all();

    expect($first)->toBe($second);
});

it('sends no suggestions on the unavailable page', function () {
    $quiz = Quiz::factory()->unpublished()->withQuestions(5)->create();

    $props = $this->get(route('quiz.show', $quiz))->viewData('page')['props'];

    expect($props['unavailable'])->toBeTrue()
        ->and($props)->not->toHaveKey('suggestions');
});
