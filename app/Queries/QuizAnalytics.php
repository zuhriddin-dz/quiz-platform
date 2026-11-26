<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RunStatus;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizRun;
use App\Models\QuizUserStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * R9.1 to R9.3 — everything on the creator's stats page.
 *
 * ONE POPULATION, used by every number on the page: runs that actually
 * reached a verdict (passed or failed), excluding the creator's own.
 *
 *   - Abandoned and in-progress runs are out. An abandoned run is not a play,
 *     and counting its answers would make the per-question totals exceed the
 *     run count, which reads as a bug.
 *   - The creator's own test plays are out. The spec is explicit about this
 *     for PUBLIC stats (R7.4) and silent about the creator's own page, but a
 *     page about how players do is worse than useless if it includes the one
 *     person who knows the answers. The UI says so, so the number is never a
 *     mystery.
 *
 * Portability (R0.4): `sum(case when ... then 1 else 0 end)` everywhere, never
 * `count(*) filter (where ...)`. FILTER is Postgres and SQLite-3.30+, and the
 * CASE form works everywhere.
 */
final class QuizAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function for(Quiz $quiz): array
    {
        $questions = $quiz->questions()->orderBy('position')->get();

        return [
            'totals' => $this->totals($quiz),
            'questions' => $this->perQuestion($quiz, $questions),
            'players' => $this->perPlayer($quiz),
            'guests' => $this->guests($quiz),
        ];
    }

    /**
     * The population. Every other method starts here.
     */
    private function countedRuns(Quiz $quiz): Builder
    {
        return QuizRun::query()
            ->where('quiz_id', $quiz->id)
            ->whereIn('status', [RunStatus::Passed->value, RunStatus::Failed->value])
            // `user_id != creator` alone would drop guest runs, since NULL
            // compares to nothing in SQL.
            ->where(function (Builder $query) use ($quiz): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', '!=', $quiz->user_id);
            });
    }

    /**
     * R9.1 — runs, unique players, pass rate.
     *
     * "Unique players" counts distinct signed-in users PLUS distinct guest
     * tokens. Guests collapse into one ROW in the per-player table (R9.3), but
     * they are still separate players, and reporting them as one would
     * understate the audience.
     *
     * @return array<string, mixed>
     */
    private function totals(Quiz $quiz): array
    {
        $runs = $this->countedRuns($quiz)->count();
        $passes = $this->countedRuns($quiz)->where('status', RunStatus::Passed->value)->count();

        $users = $this->countedRuns($quiz)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');

        $guests = $this->countedRuns($quiz)
            ->whereNotNull('guest_token')
            ->distinct()
            ->count('guest_token');

        return [
            'runs' => $runs,
            'passes' => $passes,
            'unique_players' => $users + $guests,
            'unique_users' => $users,
            'unique_guests' => $guests,
            // Null rather than 0 with no runs: "0% pass rate" from no data is
            // a lie, and the page shows a dash instead.
            //
            // Cast because PHP's `/` returns an INT when the division is
            // exact — 0/4 is int(0), not 0.0 — so without this the JSON type
            // changes depending on the data.
            'pass_rate' => $runs === 0 ? null : (float) ($passes / $runs),
        ];
    }

    /**
     * R9.2 — per question: correct rate, most-picked wrong option, average
     * answer time, and the timeout / wrong / focus-lost split.
     *
     * @param  Collection<int, Question>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function perQuestion(Quiz $quiz, Collection $questions): array
    {
        $aggregates = $this->answerAggregates($quiz)->keyBy('question_id');
        $worstOptions = $this->mostPickedWrongOption($quiz);

        return $questions->map(function (Question $question, int $index) use ($aggregates, $worstOptions): array {
            $row = $aggregates->get($question->id);
            $answers = (int) ($row->answers ?? 0);

            $wrongIndex = $worstOptions[$question->id] ?? null;

            return [
                'position' => $question->position,
                'number' => $index + 1,
                'prompt' => $question->prompt,

                /*
                 * Deliberately exposed: a question deep in a sudden-death
                 * quiz has far fewer answers than question 1, so a 100%
                 * correct rate on question 10 may mean two people got there.
                 * Without this count the rates are misleading.
                 */
                'answers' => $answers,
                'correct' => (int) ($row->correct ?? 0),
                // (float) for the same reason as `pass_rate`: exact division
                // in PHP yields an int.
                'correct_rate' => $answers === 0 ? null : (float) ((int) $row->correct / $answers),
                'average_time_ms' => $row?->avg_time_ms === null ? null : (int) round((float) $row->avg_time_ms),

                'most_picked_wrong_option' => $wrongIndex === null ? null : [
                    'index' => $wrongIndex['index'],
                    'text' => $question->options[$wrongIndex['index']] ?? '',
                    'picks' => $wrongIndex['picks'],
                ],

                'failures' => [
                    'wrong_answer' => (int) ($row->wrong_answers ?? 0),
                    'timeout' => (int) ($row->timeouts ?? 0),
                    'focus_lost' => (int) ($row->focus_losses ?? 0),
                ],
            ];
        })->all();
    }

    /**
     * One grouped query over the whole answer log for this quiz.
     *
     * @return Collection<int, object>
     */
    private function answerAggregates(Quiz $quiz): Collection
    {
        return collect(
            DB::table('run_answers')
                ->join('quiz_runs', 'quiz_runs.id', '=', 'run_answers.quiz_run_id')
                ->where('quiz_runs.quiz_id', $quiz->id)
                ->whereIn('quiz_runs.status', [RunStatus::Passed->value, RunStatus::Failed->value])
                ->where(function ($query) use ($quiz): void {
                    $query->whereNull('quiz_runs.user_id')
                        ->orWhere('quiz_runs.user_id', '!=', $quiz->user_id);
                })
                ->groupBy('run_answers.question_id')
                ->select([
                    'run_answers.question_id',
                    DB::raw('count(*) as answers'),
                    // `case when <bool> then` rather than `= 1`: Postgres
                    // stores a real boolean, SQLite an integer.
                    DB::raw('sum(case when run_answers.is_correct then 1 else 0 end) as correct'),
                    DB::raw('avg(run_answers.time_ms) as avg_time_ms'),
                    DB::raw("sum(case when run_answers.failure_reason = 'wrong_answer' then 1 else 0 end) as wrong_answers"),
                    DB::raw("sum(case when run_answers.failure_reason = 'timeout' then 1 else 0 end) as timeouts"),
                    DB::raw("sum(case when run_answers.failure_reason = 'focus_lost' then 1 else 0 end) as focus_losses"),
                ])
                ->get()
        );
    }

    /**
     * The wrong option chosen most often, per question.
     *
     * Only answers with an actual choice count: a timeout or a focus loss has
     * no `chosen_option_index`, so it is not anybody's pick.
     *
     * Grouped in SQL, reduced to the maximum in PHP — picking one row per
     * group needs a window function otherwise, and this result set is at most
     * three rows per question.
     *
     * @return array<int, array{index: int, picks: int}>
     */
    private function mostPickedWrongOption(Quiz $quiz): array
    {
        $rows = DB::table('run_answers')
            ->join('quiz_runs', 'quiz_runs.id', '=', 'run_answers.quiz_run_id')
            ->where('quiz_runs.quiz_id', $quiz->id)
            ->whereIn('quiz_runs.status', [RunStatus::Passed->value, RunStatus::Failed->value])
            ->where(function ($query) use ($quiz): void {
                $query->whereNull('quiz_runs.user_id')
                    ->orWhere('quiz_runs.user_id', '!=', $quiz->user_id);
            })
            ->whereNotNull('run_answers.chosen_option_index')
            ->where('run_answers.is_correct', false)
            ->groupBy('run_answers.question_id', 'run_answers.chosen_option_index')
            ->select([
                'run_answers.question_id',
                'run_answers.chosen_option_index',
                DB::raw('count(*) as picks'),
            ])
            ->get();

        $best = [];

        foreach ($rows as $row) {
            $questionId = (int) $row->question_id;
            $picks = (int) $row->picks;

            // Ties go to the lower option index, so the answer is stable
            // across reloads rather than whatever the driver returned first.
            if (! isset($best[$questionId])
                || $picks > $best[$questionId]['picks']
                || ($picks === $best[$questionId]['picks']
                    && (int) $row->chosen_option_index < $best[$questionId]['index'])) {
                $best[$questionId] = [
                    'index' => (int) $row->chosen_option_index,
                    'picks' => $picks,
                ];
            }
        }

        return $best;
    }

    /**
     * R9.3 — per signed-in player. Read from `quiz_user_stats`, which the
     * end-of-run transaction already maintains, rather than re-aggregating
     * runs.
     *
     * The creator's own row is excluded to match the population.
     *
     * @return array<int, array<string, mixed>>
     */
    private function perPlayer(Quiz $quiz): array
    {
        return QuizUserStat::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', '!=', $quiz->user_id)
            ->with('user:id,name,avatar_url')
            ->orderByDesc('passed')
            ->orderByDesc('best_reached')
            ->orderByDesc('last_played_at')
            ->get()
            ->map(fn (QuizUserStat $stat): array => [
                'name' => $stat->user->name,
                'avatar_url' => $stat->user->avatar_url,
                'attempts' => $stat->attempts,
                'best_reached' => $stat->best_reached,
                'passed' => $stat->passed,
                'first_passed_at' => $stat->first_passed_at?->toDateString(),
                'last_played_at' => $stat->last_played_at->toDateString(),
            ])
            ->all();
    }

    /**
     * R9.3 — "Guests are grouped as a single 'Guests' row."
     *
     * Aggregated from runs, because guests have no `quiz_user_stats` row at
     * all — which is the same fact that makes migrating guest history at
     * signup impossible.
     *
     * Returns null when no guest has finished a run, so the table simply has
     * no Guests row rather than a row of zeroes.
     *
     * @return array<string, mixed>|null
     */
    private function guests(Quiz $quiz): ?array
    {
        $runs = $this->countedRuns($quiz)->whereNotNull('guest_token');

        $attempts = $runs->count();

        if ($attempts === 0) {
            return null;
        }

        $passedRuns = $this->countedRuns($quiz)
            ->whereNotNull('guest_token')
            ->where('status', RunStatus::Passed->value);

        return [
            'name' => 'Guests',
            'players' => $this->countedRuns($quiz)
                ->whereNotNull('guest_token')
                ->distinct()
                ->count('guest_token'),
            'attempts' => $attempts,
            'best_reached' => (int) $this->countedRuns($quiz)
                ->whereNotNull('guest_token')
                ->max('reached'),
            'passes' => $passedRuns->count(),
            'first_passed_at' => $this->countedRuns($quiz)
                ->whereNotNull('guest_token')
                ->where('status', RunStatus::Passed->value)
                ->min('finished_at'),
        ];
    }
}
