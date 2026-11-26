<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\User;

/**
 * Phase 7 gate — R7.1, R7.2, R7.8.
 */
function exploreCards(array $query = []): array
{
    return test()->get(route('home', $query))
        ->assertOk()
        ->viewData('page')['props']['quizzes']['data'];
}

// ------------------------------------------------------- what appears at all

it('shows published, listed quizzes to guests', function () {
    Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Listed']);

    expect(collect(exploreCards())->pluck('title')->all())->toBe(['Listed']);
});

it('hides drafts, unpublished and unlisted quizzes', function () {
    Quiz::factory()->withQuestions(5)->create(['title' => 'Draft']);
    Quiz::factory()->unpublished()->withQuestions(5)->create(['title' => 'Unpublished']);
    Quiz::factory()->published()->unlisted()->withQuestions(5)->create(['title' => 'Unlisted']);
    Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Listed']);

    expect(collect(exploreCards())->pluck('title')->all())->toBe(['Listed']);
});

it('shows an unlisted quiz nowhere on Explore but still serves it by link', function () {
    $unlisted = Quiz::factory()->published()->unlisted()->withQuestions(5)->create();

    expect(exploreCards())->toHaveCount(0);

    // R7.8 — a guest with the link can open and play it.
    $this->get(route('quiz.show', $unlisted))->assertOk();
});

// -------------------------------------------------------------------- R7.2

it('puts everything the card needs in the payload', function () {
    $creator = User::factory()->create(['name' => 'Amina']);

    Quiz::factory()
        ->for($creator)
        ->published()
        ->casual()
        ->withQuestions(7)
        ->withStats(runs: 40, passes: 10)
        ->withUpvotes(13)
        ->create(['title' => 'Phrasal verbs', 'seconds_per_question' => 8]);

    $card = exploreCards()[0];

    expect($card)->toMatchArray([
        'title' => 'Phrasal verbs',
        'mode' => 'casual',
        'mode_label' => 'Casual',
        'question_count' => 7,
        'seconds_per_question' => 8,
        'upvotes_count' => 13,
        'runs_count' => 40,
    ])->and($card['creator']['name'])->toBe('Amina');
});

it('never leaks the creator’s email onto a card', function () {
    Quiz::factory()
        ->for(User::factory()->create(['email' => 'private@example.com']))
        ->published()
        ->withQuestions(5)
        ->create();

    $this->get(route('home'))->assertDontSee('private@example.com');

    expect(array_keys(exploreCards()[0]['creator']))->toBe(['name', 'avatar_url']);
});

it('shows no pass rate or difficulty label on a card', function () {
    Quiz::factory()->published()->withQuestions(5)->withStats(runs: 50, passes: 25)->create();

    $card = exploreCards()[0];

    expect($card)->not->toHaveKey('pass_rate')
        ->not->toHaveKey('difficulty')
        ->not->toHaveKey('average_reached');
});

// --------------------------------------------------------------- R7.1 sorts

it('sorts by upvotes by default', function () {
    Quiz::factory()->published()->withQuestions(5)->withUpvotes(1)->create(['title' => 'Low']);
    Quiz::factory()->published()->withQuestions(5)->withUpvotes(9)->create(['title' => 'High']);
    Quiz::factory()->published()->withQuestions(5)->withUpvotes(5)->create(['title' => 'Mid']);

    expect(collect(exploreCards())->pluck('title')->all())->toBe(['High', 'Mid', 'Low']);
});

it('sorts by plays', function () {
    Quiz::factory()->published()->withQuestions(5)->withStats(runs: 2, passes: 0)->create(['title' => 'Few']);
    Quiz::factory()->published()->withQuestions(5)->withStats(runs: 90, passes: 0)->create(['title' => 'Many']);

    expect(collect(exploreCards(['sort' => 'played']))->pluck('title')->all())
        ->toBe(['Many', 'Few']);
});

it('sorts by newest', function () {
    Quiz::factory()->published()->withQuestions(5)->create([
        'title' => 'Older', 'published_at' => now()->subYear(),
    ]);
    Quiz::factory()->published()->withQuestions(5)->create([
        'title' => 'Newer', 'published_at' => now(),
    ]);

    expect(collect(exploreCards(['sort' => 'newest']))->pluck('title')->all())
        ->toBe(['Newer', 'Older']);
});

it('falls back to popular for an unknown sort', function () {
    $this->get(route('home', ['sort' => 'nonsense']))->assertSessionHasErrors('sort');
});

// -------------------------------------------------------------- R7.1 search

it('searches the title', function () {
    Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Phrasal verbs']);
    Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Idioms']);

    expect(collect(exploreCards(['q' => 'phrasal']))->pluck('title')->all())
        ->toBe(['Phrasal verbs']);
});

it('searches the description', function () {
    Quiz::factory()->published()->withQuestions(5)->create([
        'title' => 'Set A', 'description' => 'Tricky collocations',
    ]);
    Quiz::factory()->published()->withQuestions(5)->create([
        'title' => 'Set B', 'description' => 'Simple nouns',
    ]);

    expect(collect(exploreCards(['q' => 'collocation']))->pluck('title')->all())->toBe(['Set A']);
});

it('searches case-insensitively on both drivers', function () {
    // The query uses lower(...) like ?, not ILIKE, because the suite runs on
    // SQLite and production is Postgres.
    Quiz::factory()->published()->withQuestions(5)->create(['title' => 'UPPERCASE Title']);

    expect(exploreCards(['q' => 'uppercase']))->toHaveCount(1)
        ->and(exploreCards(['q' => 'UPPERCASE']))->toHaveCount(1);
});

it('copes with a quiz that has no description', function () {
    Quiz::factory()->published()->withQuestions(5)->create([
        'title' => 'No description', 'description' => null,
    ]);

    expect(exploreCards(['q' => 'description']))->toHaveCount(1);
});

// ---------------------------------------------------------------- R7.1 mode

it('filters by mode', function () {
    Quiz::factory()->published()->strict()->withQuestions(5)->create(['title' => 'S']);
    Quiz::factory()->published()->casual()->withQuestions(5)->create(['title' => 'C']);

    expect(collect(exploreCards(['mode' => 'strict']))->pluck('title')->all())->toBe(['S'])
        ->and(collect(exploreCards(['mode' => 'casual']))->pluck('title')->all())->toBe(['C'])
        ->and(exploreCards(['mode' => 'all']))->toHaveCount(2);
});

it('rejects an unknown mode filter', function () {
    $this->get(route('home', ['mode' => 'brutal']))->assertSessionHasErrors('mode');
});

it('combines search, filter and sort', function () {
    Quiz::factory()->published()->strict()->withQuestions(5)->withUpvotes(1)
        ->create(['title' => 'Verbs one']);
    Quiz::factory()->published()->strict()->withQuestions(5)->withUpvotes(8)
        ->create(['title' => 'Verbs two']);
    Quiz::factory()->published()->casual()->withQuestions(5)->withUpvotes(99)
        ->create(['title' => 'Verbs three']);

    $titles = collect(exploreCards(['q' => 'verbs', 'mode' => 'strict']))->pluck('title')->all();

    expect($titles)->toBe(['Verbs two', 'Verbs one']);
});

// -------------------------------------------------------------- pagination

it('paginates', function () {
    Quiz::factory()->count(14)->published()->withQuestions(5)->create();

    $props = $this->get(route('home'))->viewData('page')['props']['quizzes'];

    expect($props['data'])->toHaveCount(12)
        ->and($props['total'])->toBe(14)
        ->and($props['last_page'])->toBe(2);
});

it('echoes the filters back so the controls stay in sync', function () {
    $props = $this->get(route('home', ['q' => 'verbs', 'mode' => 'casual', 'sort' => 'newest']))
        ->viewData('page')['props']['filters'];

    expect($props)->toBe(['q' => 'verbs', 'mode' => 'casual', 'sort' => 'newest']);
});
