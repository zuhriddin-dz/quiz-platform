# Phase 8 — Voting and suggestions

Written code, nothing run.

## The one real decision: where the score is computed

R8.7 says the suggestion list is "computed when the page loads with a single SQL query over the
cached columns", and §2 says "log10 and abs must work on SQLite too, **or the score term is
computed in PHP**". Those two sentences are in tension, and the spec hands you the escape hatch in
the second one.

**The score is computed in PHP.** One SQL query selects the candidates; the arithmetic happens in
`SuggestedQuizzes::score()`. Three reasons:

1. **`log10()` in SQLite is a compile-time option** (`SQLITE_ENABLE_MATH_FUNCTIONS`, 3.35+). PHP's
   bundled SQLite may or may not have it. A SQL score would pass on Postgres and fail in CI — or
   worse, behave differently in the two places without failing. `abs()` *is* core in both, but it
   was never the one that bites.
2. **R8.6's cap of 3 per creator needs a window function** (`ROW_NUMBER() OVER (PARTITION BY
   user_id ...)`) to express in SQL at all, so something had to happen after the rows came back
   regardless.
3. **The expensive part is the filter**, not the arithmetic — the anti-join against quizzes the
   viewer has passed — and that stays in SQL where it belongs.

So "one query" holds for the part that touches the database, and there are no background jobs, which
is what R8.7 is really protecting.

### The bound, and why it is not biased

The candidate fetch is capped at `candidate_limit` (500 by default, in config). A naive bound
ordered by `upvotes_count` would be biased: a same-creator quiz with zero upvotes carries a bonus of
3, which beats `log10(1 + 500)` — so it could be cut off by the limit despite deserving first place.

The fix is in the ORDER BY:

```sql
order by (case when user_id = :current_creator then 0 else 1 end), upvotes_count desc, id desc
```

Same-creator quizzes come first, so the author bonus can never be truncated away. Beyond 500
published listed quizzes, a *stranger's* quiz with few upvotes may be missed — which is the correct
thing to lose, since it would have scored lowest anyway.

**If the catalogue outgrows this**, the upgrade is a SQL score with `log10` registered as a custom
SQLite function (`Pdo\Sqlite::createFunction` on PHP 8.4, `PDO::sqliteCreateFunction` before it),
plus a window function for the cap. That is more moving parts for no benefit at today's scale, and
the PHP version is the one the spec sanctions.

## Scoring details worth knowing

```
score = w_author * (same creator ? 1 : 0)
      + w_stats  * (1 - |pass_rate_a - pass_rate_b|)
      + w_votes  * log10(1 + upvotes_count)
```

- Weights live in `config/quiz.php` (3 / 2 / 1), as R8.4 requires, and a test flips `w_author` to 0
  to prove they are actually read.
- **R8.5's neutral is the TERM, not the pass rate.** When either quiz has fewer than 10 counted
  runs, the stats term becomes `0.5` — the quiz is not *assumed* to be 50% passable. Two tests pin
  this down from both sides (current quiz thin, candidate thin) and a third proves the real term
  takes over once both clear the floor.
- `log10(1 + x)` is why zero upvotes contributes exactly zero rather than `-INF`.
- **Ties break on upvotes, then id**, so the list is deterministic. Without that, two equally scored
  quizzes could swap places on every reload, which looks like a bug and makes the page untestable.
- **The cap is applied after sorting**, so a creator keeps their three *best* quizzes rather than
  the three the database happened to return first. There is a test that seeds six quizzes by one
  creator — three low-upvote, three high — and asserts the high three survive.

## The vote count is derived, not incremented

`ToggleVote` deletes or creates the row, then sets `upvotes_count` from `$quiz->votes()->count()`
inside the same transaction. R8.2 asks for the row and the count to move together; deriving it makes
them literally unable to disagree.

Why not `increment`/`decrement`:

- A lost decrement leaves the count permanently above the row count.
- Clamping at zero in SQL is not portable — Postgres spells it `greatest`, SQLite `max` — so
  `max(upvotes_count - 1, 0)` was not available.
- `COUNT` over `quiz_votes (quiz_id)` is indexed and cheap.

A side effect worth having: a drifted column **heals itself** on the next vote, and there is a test
that sets `upvotes_count` to 99 with no rows and watches one vote bring it to 1.

The unique `(user_id, quiz_id)` index is still what makes the toggle safe under two simultaneous
clicks — the derived count just cannot be the thing that breaks.

## Voting rules (R8.1)

`QuizPolicy::vote` — signed in, published **and listed**, not your own. Having played is explicitly
*not* required, which follows from what the vote means: it marks a quiz worth finding, and that is
judgeable from its page.

Unlisted quizzes are excluded because they are shared by link and have no public ranking to
influence. A guest clicking the button gets the sign-in prompt from Phase 7 (R7.8), not a 403.

The endpoint returns `back()` rather than JSON: a vote changes a number the page already shows, so
re-rendering keeps `has_voted`, the count and the suggestions consistent without a second source of
truth.

## One UI judgment call

**Suggestions are hidden while a run is in progress.** A grid of links next to a live timer is an
invitation to lose the run by clicking one. They reappear on the result screen, which is the moment
the spec's "try next" actually makes sense.

## Phase 8 gate

```powershell
docker compose exec app php artisan test --filter="Vote|Suggestions"
```

Covered: toggle on and off, one vote per user, no play required, guest redirect, own-quiz /
unlisted / draft / unpublished refusals, count-equals-rows after mixed voting, drift healing, no
negative count, the voted state in the props, and one user's vote surviving another's.

For suggestions: candidate filtering (self, drafts, unpublished, unlisted, passed-by-viewer,
attempted-but-not-passed, guests), the 12 limit, the documented score checked against hand-computed
values, zero-upvote behaviour, author preference, upvote preference, config-driven weights, the
neutral term from both sides and the real term once both clear the floor, the cap at 3, best-three
retention, the cap not starving other creators, config-driven cap, SQLite without `log10`, a query
count, the payload shape, determinism across reloads, and no suggestions on the unavailable page.

## Files written in this phase

| File | Serves |
|---|---|
| `config/quiz.php` (rewritten) | R8.4, R8.5 |
| `app/Policies/QuizPolicy.php` (edited) | R8.1 |
| `app/Actions/Quiz/ToggleVote.php` | R8.1, R8.2 |
| `app/Http/Controllers/VoteController.php` | R8.1 |
| `app/Queries/SuggestedQuizzes.php` | R8.3–R8.7 |
| `app/Http/Controllers/QuizPageController.php` (edited) | R8.3 |
| `routes/web.php` (edited) | `quiz.vote` |
| `resources/js/pages/Quiz.vue` (edited) | vote button + suggestions |
| `tests/Feature/VoteTest.php` | R8.1, R8.2 |
| `tests/Feature/SuggestionsTest.php` | R8.3–R8.7 |

## Likely first-run fixes

- **The query-count test is approximate.** It allows up to 3 queries (candidates + two eager
  loads). If Laravel's `withCount` plus `with('creator')` costs a different number, adjust the
  bound rather than the code.
- **`sortByDesc` with an array key** relies on PHP's array comparison for the tiebreak chain. It is
  correct, but if the ordering ever looks wrong, that line is where to look first.
- **`QuizUserStat` is imported in `SuggestedQuizzes` but unused** — the anti-join is written as a
  raw subquery against the table name. Harmless; remove it when running a linter.
- **Suggestions are computed on every quiz-page load**, including during a run's `back()` redirect
  after a vote. That is what the spec asks for, but it is the first thing to cache if the page ever
  feels slow.
