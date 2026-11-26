# Phase 9 — Creator analytics

Written code, nothing run. The last feature phase; only polish (Phase 10) is left.

## One population, used by every number

Every figure on the page comes from the same set of runs: **finished (passed or failed), excluding
the creator's own**.

**Abandoned and in-progress runs are out.** An abandoned run is not a play, and its answer rows are
real but counting them would make the per-question totals exceed the run count — which reads as a
bug, not as nuance. There is a test that attaches a real answer row to an abandoned run and asserts
both the total and the per-question count stay at 1.

**The creator's own test plays are out, and this is an interpretation.** The spec is explicit about
excluding them from *public* stats (R7.4) and silent about the creator's own page. I excluded them,
because a page about how players do is worse than useless if it includes the one person who knows
the answers. The UI states it in a line under the header, so the missing runs are never a mystery.
If you would rather see your own test plays, it is one `where` clause in
`QuizAnalytics::countedRuns()`.

A detail that is easy to get wrong: the exclusion is

```php
where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $creatorId))
```

not `where('user_id', '!=', $creatorId)` — the latter silently drops every **guest** run, because
NULL compares to nothing in SQL.

## Portability again (R0.4)

Every conditional aggregate is `sum(case when ... then 1 else 0 end)`, never
`count(*) filter (where ...)`. FILTER is Postgres and SQLite 3.30+, and the CASE form works
everywhere.

One subtlety: `case when run_answers.is_correct then 1 else 0 end` rather than `is_correct = 1`.
Postgres stores a real boolean and would reject the integer comparison; SQLite stores 0/1 and treats
0 as false in `CASE WHEN`. The bare-column form is the only one both accept.

## Per question (R9.2)

Two queries: one grouped aggregate for counts, rates, average time and the failure split; one
grouped by `(question_id, chosen_option_index)` for the most-picked wrong option, reduced to a
maximum in PHP. Picking one row per group in SQL needs a window function, and the result set here is
at most three rows per question.

Three decisions in that table:

- **The answer count is shown, not just the rate.** In a sudden-death quiz, question 10 is answered
  by far fewer people than question 1. Without the count, "100% correct" on a late question looks
  like an easy question when it may mean two people got there. A test asserts both numbers.
- **Timeouts and focus losses are excluded from "most-picked wrong option"**, because nobody
  *picked* them — `chosen_option_index` is null for both. A test seeds one wrong pick against five
  timeouts and asserts the pick wins.
- **Ties go to the lower option index**, so the answer is stable across reloads rather than whatever
  the driver happened to return first.

Questions with no answers at all are still listed, in authoring order, with nulls rather than zeroes
— a question nobody reached has no correct rate, and `0%` would be a lie.

## Per player (R9.3)

Signed-in players come from `quiz_user_stats`, which the end-of-run transaction already maintains,
rather than re-aggregating runs. The creator's own row is filtered out to match the population.

**Guests are one row, aggregated from runs**, because they have no `quiz_user_stats` row at all —
the same fact that makes migrating guest history at signup impossible. The row shows the number of
distinct guest tokens alongside the attempts, so "Guests: 12 attempts" does not read as one very
persistent person.

The row is `null` when no guest has finished, so the table simply has no Guests row rather than a
row of zeroes.

**"Unique players" counts distinct users plus distinct guest tokens.** Guests collapse into one
*row* in the table, but they are still separate players, and reporting them as one would understate
the audience. The totals card breaks the two apart underneath.

## R9.4 and R9.5

`is_self_reported` comes from `QuizMode::isSelfReported()`, written back in Phase 2. The casual
banner is specific about what it means: correctness *was* re-checked on the server (R6.4), but the
times are whatever each browser reported.

`QuizPolicy::viewStats` follows ownership alone and ignores status, so analytics survive
unpublishing — which is the entire reason unpublishing exists rather than deleting. A test
unpublishes a quiz with a recorded pass and asserts the stats page still serves all of it.

## Also done in this phase

The Studio list gained its **Stats** link (and a `can.view_stats` flag), which Phase 3 deliberately
left out because the route did not exist yet. It is hidden for drafts, where there is nothing to
see but the creator's own excluded test runs.

The three Studio pages from Phases 3 and 4 now use `AppLayout`, closing the gap noted at the end of
Phase 7 — they were rendering without the top bar.

## Phase 9 gate

```powershell
docker compose exec app php artisan test --filter=QuizAnalytics
```

Covered: totals with mixed users and guests; null pass rate with no runs; creator exclusion from
totals and from the player table; abandoned and in-progress exclusion with per-question agreement;
correct rates; the reached-by count on a late question; average time; most-picked wrong option
including the timeout exclusion and the all-correct case; the three-way failure split; authoring
order with untouched questions; per-player attempts/best/first-pass; the grouped guests row with
distinct-token count; the omitted guests row; a guest pass; the self-reported flag for both modes;
analytics after unpublishing; stats on a draft; non-creator 403; guest redirect; and no email in the
payload.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Queries/QuizAnalytics.php` | R9.1–R9.3 |
| `app/Http/Controllers/Studio/StatsController.php` | R9.1, R9.4, R9.5 |
| `routes/studio.php` (edited) | `studio.quizzes.stats` |
| `resources/js/pages/studio/Stats.vue` | R9.1–R9.4 |
| `app/Http/Controllers/Studio/QuizController.php` (edited) | `can.view_stats` |
| `resources/js/pages/studio/Index.vue` (edited) | Stats link + layout |
| `resources/js/pages/studio/Create.vue` (edited) | layout |
| `resources/js/pages/studio/Edit.vue` (edited) | layout |
| `tests/Feature/Studio/QuizAnalyticsTest.php` | R9.1–R9.5 |

**The Studio route group now matches the spec's route table (§10) in full.**

## Likely first-run fixes

- **`totals()` runs five separate counts.** Correct and readable, but it is five round trips where
  one `selectRaw` with CASE sums would do. Left as-is because the page is not hot; worth collapsing
  if it ever feels slow.
- **`guests()` runs five queries too**, for the same reason.
- **`min('finished_at')` returns a raw string**, not a Carbon instance, so the guests row's
  `first_passed_at` is whatever format the driver gives — and Postgres and SQLite format timestamps
  differently. If that date renders oddly, wrap it in `Carbon::parse(...)?->toDateString()`.
- **`avg(time_ms)`** comes back as a string on some drivers; it is cast with `(float)` before
  rounding, which should cover both.
