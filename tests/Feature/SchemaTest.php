<?php

declare(strict_types=1);

use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use App\Models\QuizVote;
use App\Models\RunAnswer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 gate — R2.1 to R2.6.
 *
 * Runs on SQLite in-memory, so passing here also proves the migrations are
 * portable (R0.4). Postgres is covered by running `migrate:fresh` against the
 * dev container.
 */
it('creates all seven tables', function () {
    foreach ([
        'users',
        'quizzes',
        'questions',
        'quiz_votes',
        'quiz_runs',
        'run_answers',
        'quiz_user_stats',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }
});

it('keeps internal ids out of public URLs', function () {
    $quiz = Quiz::factory()->create();

    expect($quiz->ulid)->toHaveLength(26)
        ->and($quiz->getRouteKey())->toBe($quiz->ulid);

    $run = QuizRun::factory()->create();

    expect($run->id)->toBeString()->toHaveLength(26);
});

it('builds a quiz with questions, a run and an answer', function () {
    $quiz = Quiz::factory()->published()->withQuestions(5)->create();

    expect($quiz->questions)->toHaveCount(5);

    $run = QuizRun::factory()
        ->for($quiz)
        ->withOrder($quiz->questions->pluck('id')->all())
        ->create();

    RunAnswer::factory()->for($run, 'run')->for($quiz->questions->first())->create();

    expect($run->answers)->toHaveCount(1)
        ->and($run->questionCount())->toBe(5)
        ->and($run->currentQuestionId())->toBe($quiz->questions->first()->id);
});

it('casts enums and json columns on the way back out', function () {
    $quiz = Quiz::factory()->casual()->published()->create();
    $question = Question::factory()->for($quiz)->withOptions(['a', 'b', 'c', 'd'], 2)->create();

    $quiz->refresh();
    $question->refresh();

    expect($quiz->mode)->toBeInstanceOf(App\Enums\QuizMode::class)
        ->and($quiz->status)->toBeInstanceOf(App\Enums\QuizStatus::class)
        ->and($quiz->is_listed)->toBeBool()
        ->and($question->options)->toBe(['a', 'b', 'c', 'd'])
        ->and($question->correctOptionText())->toBe('c');
});

// -------------------------------------------------------------------- R2.3

it('rejects a run with both a user and a guest token', function () {
    QuizRun::factory()->create([
        'user_id' => User::factory()->create()->id,
        'guest_token' => App\Support\GuestToken::generate(),
    ]);
})->throws(LogicException::class);

it('rejects a run with neither a user nor a guest token', function () {
    QuizRun::factory()->create([
        'user_id' => null,
        'guest_token' => null,
    ]);
})->throws(LogicException::class);

it('accepts a guest run', function () {
    $run = QuizRun::factory()->forGuest()->create();

    expect($run->isGuestRun())->toBeTrue()
        ->and($run->user_id)->toBeNull();
});

// -------------------------------------------------------------------- R2.5

it('cascades a deleted draft to its questions, runs and answers', function () {
    $quiz = Quiz::factory()->withQuestions(5)->create();
    $run = QuizRun::factory()->for($quiz)->create();
    RunAnswer::factory()->for($run, 'run')->for($quiz->questions->first())->create();
    QuizUserStat::factory()->for($quiz)->create();
    QuizVote::factory()->for($quiz)->create();

    $quiz->delete();

    expect(Question::count())->toBe(0)
        ->and(QuizRun::count())->toBe(0)
        ->and(RunAnswer::count())->toBe(0)
        ->and(QuizUserStat::count())->toBe(0)
        ->and(QuizVote::count())->toBe(0);
});

it('refuses to delete a user who still has quizzes', function () {
    $quiz = Quiz::factory()->create();

    $quiz->creator->delete();
})->throws(QueryException::class);

// -------------------------------------------------------------------- R2.4

it('keeps one vote per user per quiz', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->published()->create();

    QuizVote::factory()->for($user)->for($quiz)->create();
    QuizVote::factory()->for($user)->for($quiz)->create();
})->throws(QueryException::class);

it('keeps one stats row per user per quiz', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->published()->create();

    QuizUserStat::factory()->for($user)->for($quiz)->create();
    QuizUserStat::factory()->for($user)->for($quiz)->create();
})->throws(QueryException::class);

it('hides public stats until there are ten counted runs', function () {
    $thin = Quiz::factory()->published()->withStats(runs: 9, passes: 3)->create();
    $thick = Quiz::factory()->published()->withStats(runs: 20, passes: 5, reachedSum: 60)->create();

    expect($thin->hasEnoughRuns())->toBeFalse()
        ->and($thin->passRate())->toBeNull()
        ->and($thin->averageReached())->toBeNull()
        ->and($thick->passRate())->toBe(0.25)
        ->and($thick->averageReached())->toBe(3.0);
});

// -------------------------------------------------------------------- R2.6

it('skips the dev seeder in tests', function () {
    $this->seed(Database\Seeders\DatabaseSeeder::class);

    expect(User::count())->toBe(0);
});
