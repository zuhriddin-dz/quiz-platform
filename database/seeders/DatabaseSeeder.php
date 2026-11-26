<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FailureReason;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use App\Models\QuizVote;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * R2.6 — dev data only.
 *
 * Two hard rules:
 *   1. It refuses to run in `production` or `testing`. Tests build exactly the
 *      rows they need through factories; a seeder running underneath them
 *      makes failures impossible to read.
 *   2. It only ever INSERTS. Nothing here truncates, migrates or deletes, so
 *      running it twice adds more data rather than destroying what is there.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The dev seeder must never run in production.');
        }

        if (app()->environment('testing')) {
            // Not an exception: `php artisan test` may boot seeders by
            // configuration, and failing the whole suite would be rude.
            $this->command?->warn('DatabaseSeeder skipped: tests build their own data.');

            return;
        }

        $creators = User::factory()->count(3)->create();
        $players = User::factory()->count(5)->create();

        // A spread of states, so Explore, the Studio and My plays all have
        // something to show on a fresh database.
        foreach ($creators as $creator) {
            // Published and listed: the Explore case.
            $listed = Quiz::factory()
                ->for($creator)
                ->published()
                ->strict()
                ->withQuestions(6)
                ->withStats(runs: 24, passes: 7)
                ->withUpvotes(random_int(0, 40))
                ->create();

            // Published but unlisted: reachable by link only.
            Quiz::factory()
                ->for($creator)
                ->published()
                ->casual()
                ->unlisted()
                ->withQuestions(5)
                ->create();

            // A draft, so the editor has something to open.
            Quiz::factory()
                ->for($creator)
                ->withQuestions(3)
                ->create();

            // An unpublished quiz, for the "Unavailable" states.
            Quiz::factory()
                ->for($creator)
                ->unpublished()
                ->withQuestions(5)
                ->withStats(runs: 11, passes: 2)
                ->create();

            $this->addPlayHistory($listed, $players);
        }

        $this->command?->info('Seeded 3 creators, 5 players and 12 quizzes.');
    }

    /**
     * Enough finished runs and stats rows that the 10-run floor on public
     * stats (R7.4) is cleared for at least one quiz.
     */
    private function addPlayHistory(Quiz $quiz, iterable $players): void
    {
        foreach ($players as $player) {
            $passed = random_int(0, 4) === 0;

            if ($passed) {
                QuizRun::factory()->for($quiz)->forUser($player)->passed(6)->create();
            } else {
                QuizRun::factory()
                    ->for($quiz)
                    ->forUser($player)
                    ->failed(
                        FailureReason::from(['wrong_answer', 'timeout', 'focus_lost'][random_int(0, 2)]),
                        random_int(0, 5),
                    )
                    ->create();
            }

            QuizUserStat::factory()
                ->for($player)
                ->for($quiz)
                ->when($passed, fn ($factory) => $factory->passed(6))
                ->create();

            // Guests play too, and leave no stats row behind.
            QuizRun::factory()->for($quiz)->forGuest()->failed()->create();

            QuizVote::factory()->for($player)->for($quiz)->create();
        }
    }
}
