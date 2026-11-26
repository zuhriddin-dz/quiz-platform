<?php

declare(strict_types=1);

use App\Models\Quiz;
use App\Models\QuizUserStat;
use App\Models\User;

/**
 * Phase 7 gate — R7.6 and the sidebar half of R7.7.
 */

// -------------------------------------------------------------------- R7.6

it('needs sign-in', function () {
    $this->get(route('plays'))->assertRedirect();
});

it('lists every quiz the user has attempted, most recent first', function () {
    $player = User::factory()->create();

    $old = Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Older']);
    $new = Quiz::factory()->published()->withQuestions(5)->create(['title' => 'Newer']);

    QuizUserStat::factory()->for($player)->for($old)
        ->lastPlayed(now()->subWeek())->create();
    QuizUserStat::factory()->for($player)->for($new)
        ->lastPlayed(now())->create();

    $plays = $this->actingAs($player)->get(route('plays'))
        ->assertOk()
        ->viewData('page')['props']['plays'];

    expect(collect($plays)->pluck('title')->all())->toBe(['Newer', 'Older']);
});

it('shows nobody else’s history', function () {
    $player = User::factory()->create();
    QuizUserStat::factory()->create(); // someone else's

    expect($this->actingAs($player)->get(route('plays'))->viewData('page')['props']['plays'])
        ->toHaveCount(0);
});

it('shows status, attempts, best and first passed', function () {
    $player = User::factory()->create();
    $quiz = Quiz::factory()->published()->withQuestions(8)->create();

    QuizUserStat::factory()->for($player)->for($quiz)->passed(8)->create(['attempts' => 4]);

    $play = $this->actingAs($player)->get(route('plays'))->viewData('page')['props']['plays'][0];

    expect($play['passed'])->toBeTrue()
        ->and($play['attempts'])->toBe(4)
        ->and($play['best_reached'])->toBe(8)
        ->and($play['question_count'])->toBe(8)
        ->and($play['first_passed_at'])->not->toBeNull()
        ->and($play['is_playable'])->toBeTrue();
});

it('shows an attempted but unpassed quiz as replayable', function () {
    $player = User::factory()->create();
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    QuizUserStat::factory()->for($player)->for($quiz)->create([
        'attempts' => 3, 'best_reached' => 2, 'passed' => false,
    ]);

    $play = $this->actingAs($player)->get(route('plays'))->viewData('page')['props']['plays'][0];

    expect($play['passed'])->toBeFalse()
        ->and($play['first_passed_at'])->toBeNull()
        ->and($play['is_playable'])->toBeTrue();
});

// ------------------------------------------------- R4.4 in the history view

it('marks an unpublished quiz unavailable while keeping its numbers', function () {
    $player = User::factory()->create();
    $quiz = Quiz::factory()->unpublished()->withQuestions(5)->create(['title' => 'Withdrawn']);

    QuizUserStat::factory()->for($player)->for($quiz)->create([
        'attempts' => 7, 'best_reached' => 4,
    ]);

    $play = $this->actingAs($player)->get(route('plays'))->viewData('page')['props']['plays'][0];

    expect($play['title'])->toBe('Withdrawn')
        // No "Play again"...
        ->and($play['is_playable'])->toBeFalse()
        // ...but nothing is lost.
        ->and($play['attempts'])->toBe(7)
        ->and($play['best_reached'])->toBe(4);
});

it('keeps the creator’s own draft replayable in their history', function () {
    $creator = User::factory()->create();
    $draft = Quiz::factory()->for($creator)->withQuestions(5)->create();

    QuizUserStat::factory()->for($creator)->for($draft)->create();

    $play = $this->actingAs($creator)->get(route('plays'))->viewData('page')['props']['plays'][0];

    expect($play['is_playable'])->toBeTrue();
});

// -------------------------------------------------------------------- R7.7

it('shares the five most recent plays with every page', function () {
    $player = User::factory()->create();

    foreach (range(1, 7) as $index) {
        QuizUserStat::factory()
            ->for($player)
            ->for(Quiz::factory()->published()->withQuestions(5)->create(['title' => "Quiz {$index}"]))
            ->lastPlayed(now()->subMinutes(10 - $index))
            ->create();
    }

    $recent = $this->actingAs($player)->get(route('home'))
        ->viewData('page')['props']['recent_plays'];

    expect($recent)->toHaveCount(5)
        // Most recent first: quiz 7 was played last.
        ->and($recent[0]['title'])->toBe('Quiz 7');
});

it('shares nothing for a guest, so the sidebar shows the sign-in card', function () {
    expect($this->get(route('home'))->viewData('page')['props']['recent_plays'])->toBeNull();
});

it('marks an unavailable quiz in the recent list too', function () {
    $player = User::factory()->create();
    $quiz = Quiz::factory()->unpublished()->withQuestions(5)->create();

    QuizUserStat::factory()->for($player)->for($quiz)->create();

    $recent = $this->actingAs($player)->get(route('home'))
        ->viewData('page')['props']['recent_plays'];

    expect($recent[0]['is_playable'])->toBeFalse();
});

it('never leaks an email through the shared props', function () {
    $player = User::factory()->create(['email' => 'private@example.com']);

    $this->actingAs($player)->get(route('home'))->assertDontSee('private@example.com');
});
