# Phase 2 — Schema & models

Written code, nothing run. `users` shipped in Phase 1; this phase adds the other six tables,
their models, factories and a dev seeder.

## The seven tables, in build order and why

| # | Table | Its one job | The decision that shaped it |
|---|---|---|---|
| 1 | `users` | everything else points at it | Phase 1. No password, no verification. |
| 2 | `quizzes` | the only content type | `ulid` for URLs; four denormalised counters so Explore, public stats and suggestions are one query with no jobs. |
| 3 | `questions` | a prompt and its four options | `options` as a JSON array, not four columns or a child table: the count is fixed, options have no identity, and shuffling reads them as a set. |
| 4 | `quiz_votes` | one upvote | The unique `(user_id, quiz_id)` pair **is** the feature — it is what makes a vote a toggle instead of a number anyone can inflate. |
| 5 | `quiz_runs` | one attempt | Stores the shuffle at start, so one quiz can be played by many players each seeing a different order, and a displayed index can be mapped back without telling the client anything. |
| 6 | `run_answers` | one answered question | Append-only event rows; `created_at` only. Every number on the creator's stats page is an aggregate over this. |
| 7 | `quiz_user_stats` | one player's standing on one quiz | An upsert target, written once per run. Guests have no row, which is why guest history cannot be migrated at signup — there is nowhere to move it to. |

## Four decisions worth knowing

**1. Enums are strings in the database, PHP enums in the app.** Postgres enum types and SQLite have
no common ground, and migrations must run on both (R0.4). So `mode`, `status` and `failure_reason`
are `string(16)` columns with casts. `app/Enums/` holds the four: `QuizMode`, `QuizStatus`,
`RunStatus`, `FailureReason`. `QuizStatus` carries the lifecycle table's three predicates
(`isEditable`, `isDeletable`, `isPubliclyPlayable`) so Phase 3's policies and Phase 4's guards read
the same rules from one place.

**2. R2.3 is a model guard, not a CHECK constraint.** "Exactly one of `user_id` / `guest_token`"
would be a check constraint in plain SQL, but Postgres and SQLite spell those differently and R0.4
bars driver-specific SQL. `QuizRun::booted()` throws a `LogicException` on save instead, and
`SchemaTest` covers both halves. Every write goes through Eloquent, so the coverage is complete.

**3. "Exactly 4 distinct options" is *not* a database constraint.** A draft may be incomplete
(R3.4), so the schema has to allow a half-written question. Those rules are publish validation
(R4.1). `Question::isComplete()` holds the per-question half of that check now, so Phase 4 composes
rather than reimplements.

**4. `quizzes.user_id` restricts on delete; everything else cascades.** Deleting a creator must
never silently delete other players' history, and a published quiz can never be deleted at all. The
cascades that do exist all hang off a quiz, which only a draft delete can trigger.

## Timing precision

`quiz_runs.current_issued_at` is `timestamp(3)` because the strict-mode timeout decision is
`received_at - current_issued_at > seconds_per_question + grace` (R5.3). Postgres honours the
precision; SQLite stores datetimes as text, so there it is advisory. The cast
(`datetime:Y-m-d H:i:s.v`) keeps milliseconds on the round trip in both.

## Indexes, and what each is for

- `quizzes (status, is_listed)` — every Explore query starts here; the sorts order within it.
- `quizzes` singles on `upvotes_count`, `runs_count`, `published_at` — the three sorts.
- `quizzes (user_id, status)` — the Studio list.
- `questions (quiz_id, position)` — the editor and every run payload.
- `quiz_runs (quiz_id, user_id, status)` and `(quiz_id, guest_token, status)` — "abandon any
  in-progress run by this player on this quiz" runs on every start, for both kinds of player.
- `quiz_runs (user_id, started_at)` — My plays.
- `run_answers (question_id, is_correct)` — per-question analytics.
- `quiz_user_stats (user_id, last_played_at)` — the sidebar's 5 recent plays.

## Seeder (R2.6)

`DatabaseSeeder` refuses `production` outright and **skips** `testing` with a warning rather than
an exception, so `php artisan test` can never have data appear underneath it. It only inserts —
nothing truncates or deletes — so running it twice adds more rather than destroying what is there.

It seeds 3 creators × 4 quizzes (published+listed, published+unlisted, draft, unpublished), plus
finished runs, guest runs, votes and stats rows, so at least one quiz clears the 10-run floor on
public stats.

```powershell
docker compose exec app php artisan db:seed
```

## Phase 2 gate

```powershell
docker compose exec app php artisan test --filter=SchemaTest
```

```powershell
docker compose exec app php artisan migrate:fresh
```

The first runs on SQLite in-memory and so also proves portability; the second is the Postgres half.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Enums/QuizMode.php` | R2.1 |
| `app/Enums/QuizStatus.php` | R2.1, §5 lifecycle |
| `app/Enums/RunStatus.php` | R2.1 |
| `app/Enums/FailureReason.php` | R2.1 |
| `database/migrations/..._000100_create_quizzes_table.php` | R2.1, R2.2, R2.4 |
| `database/migrations/..._000200_create_questions_table.php` | R2.1, R2.5 |
| `database/migrations/..._000300_create_quiz_votes_table.php` | R2.1 |
| `database/migrations/..._000400_create_quiz_runs_table.php` | R2.1, R2.2, R2.3 |
| `database/migrations/..._000500_create_run_answers_table.php` | R2.1, R2.5 |
| `database/migrations/..._000600_create_quiz_user_stats_table.php` | R2.1, R2.5 |
| `app/Models/Quiz.php` | R2.2, R2.4 |
| `app/Models/Question.php` | R2.1 |
| `app/Models/QuizVote.php` | R2.1 |
| `app/Models/QuizRun.php` | R2.2, R2.3 |
| `app/Models/RunAnswer.php` | R2.1 |
| `app/Models/QuizUserStat.php` | R2.1 |
| `app/Models/User.php` (edited) | relations |
| `database/factories/` × 6 | R2.6 |
| `database/seeders/DatabaseSeeder.php` | R2.6 |
| `tests/Feature/SchemaTest.php` | Phase 2 gate |
