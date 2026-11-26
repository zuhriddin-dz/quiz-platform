# Phase 7 — Public surfaces: Explore, quiz page, My plays

Written code, nothing run. This is the phase where the app becomes navigable: everything before it
was reachable only by typing URLs.

## Two portability traps avoided

**`ILIKE` is Postgres-only.** The obvious way to write a case-insensitive search is
`where('title', 'ilike', "%$term%")`, and it would pass in production and fail on SQLite — or
rather, it fails in CI, which is the good outcome; the bad version is a query that silently behaves
differently in the two places. `ExploreQuizzes` uses `lower(...) like ?` instead, which both drivers
implement, and a test asserts case-insensitivity explicitly.

**`coalesce(description, '')`** in the description search: a null description would otherwise drop
the row from an `orWhere`, so a quiz without a description could never match a title search either.
There is a test for that too.

## Three additions beyond the spec

1. **Pagination.** The spec does not mention it. Explore without it loads every published quiz on
   the site into one response. 12 per page, `withQueryString()` so filters survive paging, and `id`
   as a tiebreaker on every sort — without that, two quizzes with equal upvotes can swap places
   between page 1 and page 2 and one of them is never seen.
2. **Filters echoed back** in the props, so the controls stay in sync with the URL. That is what
   makes any view of Explore a shareable link, which is also why each control does a GET rather than
   holding local state.
3. **The sidebar is hidden below `lg`.** The spec describes a desktop layout and says nothing about
   small screens. On a phone the recent-plays list would push the quiz grid off the fold, and the
   same links live in the avatar menu.

## The unavailable state is a page, not a 403 (R4.4)

`QuizPageController` has three outcomes:

| Who | What |
|---|---|
| published | everyone, guests included |
| unpublished, not the creator | the page renders "This quiz is no longer available" |
| draft, not the creator | **403** |

The distinction matters: a player may well have the unpublished quiz in their history and deserves
an explanation, whereas a draft was never shared with anyone. Only the **title** goes out in the
unavailable payload — no more than My plays already shows for the same quiz — and a test asserts
the quiz prop has exactly that one key.

## The route is still named `home`

`/` is now Explore, but the route keeps the name `home`, because the spec itself calls it "Home page
for everyone" and because sign-in and all the Phase 4 lifecycle redirects already point at
`route('home')`. Renaming it would have meant touching `GoogleController` and three test files for
no behavioural gain.

**`resources/js/pages/Home.vue` is now dead** — it was the Phase 1 placeholder. Delete it.

## Where each number comes from

R7.4's pass rate and average reached read the **cached counters**, which already exclude the
creator's own runs because `FinishRun` never increments them for the creator (R5.11). So the
exclusion is not re-implemented here; there is a test that plays ten real runs and ten creator runs
and asserts only the ten count.

Below 10 counted runs both values are `null` and the page says "Not enough plays yet". There is no
difficulty label anywhere, and tests assert its absence on both the card and the stats payload.

R7.5's your-stats reads `quiz_user_stats` directly; null for a guest, null for a user who has not
played.

## The sidebar is a shared prop

`HandleInertiaRequests` shares `recent_plays`: one indexed lookup against
`quiz_user_stats (user_id, last_played_at)`, which exists for exactly this. It is `null` for guests,
so they cost no query — and null is what makes the sidebar render the sign-in card rather than an
empty list. A guest's runs *are* recorded against their cookie; they simply produce no history rows,
which is the same fact that makes migrating guest history at signup impossible.

## Voting is rendered but not wired

R7.3 asks for "the upvote button and count" on the quiz page, but voting itself is R8.1/R8.2. So the
count is real, the button is rendered, and:

- a guest's button is a **sign-in link** (R7.8 — clicking vote prompts sign-in), which is a Phase 7
  requirement in its own right
- a signed-in non-creator gets the button **disabled**, with `can_vote: true` already in the props
- the creator gets a line saying they cannot upvote their own quiz

Phase 8 replaces the disabled button with the POST and the toggle. `can_vote` and `has_voted` are
already computed and tested, so that is a template change.

## Phase 7 gate

```powershell
docker compose exec app php artisan test --filter="Explore|QuizPage|Plays"
```

Covered: only published+listed on Explore; unlisted absent from Explore but reachable and playable
by link; the full card payload with no email, pass rate or difficulty; all three sorts; search over
title and description, case-insensitive, null-description-safe; mode filter; combined
search+filter+sort; pagination; the quiz page for guests and users; no questions or answers in the
page payload; the 10-run floor both ways; creator-run exclusion proven through real runs; your-stats
for three audiences; the vote affordance for four audiences; the unavailable state for guests, other
users and the creator; draft 403; My plays ordering, isolation, numbers and the unavailable row; and
the shared sidebar for users and guests.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Support/QuizCardPayload.php` | R7.2 |
| `app/Queries/ExploreQuizzes.php` | R7.1 |
| `app/Http/Controllers/ExploreController.php` | R7.1 |
| `app/Http/Controllers/QuizPageController.php` (rewritten) | R7.3–R7.5, R4.4 |
| `app/Http/Controllers/PlaysController.php` | R7.6 |
| `app/Http/Middleware/HandleInertiaRequests.php` (edited) | R7.7 |
| `routes/web.php` (rewritten) | Explore + plays |
| `resources/js/layouts/AppLayout.vue` | R7.7 |
| `resources/js/components/TopBar.vue` | R7.7, R7.8 |
| `resources/js/components/Sidebar.vue` | R7.7 |
| `resources/js/components/QuizCard.vue` | R7.2, R6.6 |
| `resources/js/pages/Explore.vue` | R7.1 |
| `resources/js/pages/Plays.vue` | R7.6 |
| `resources/js/pages/Quiz.vue` (rewritten) | R7.3–R7.5 |
| `tests/Feature/ExploreTest.php` | R7.1, R7.2, R7.8 |
| `tests/Feature/QuizPageTest.php` | R7.3–R7.5, R4.4 |
| `tests/Feature/PlaysTest.php` | R7.6, R7.7 |

**R6.6 is now complete**: the mode badge is on Explore cards, My plays rows and the play screen. The
Studio list still renders its mode as plain text and could adopt `ModeBadge` too.

## Likely first-run fixes

- **`PlaysController` counts questions per row** (`$quiz->questions()->count()`), which is an N+1
  across the history list. `withCount` through the `quiz` relation is the fix; left as-is because it
  needs a nested `with(['quiz' => fn ($q) => $q->withCount('questions')])` that is easy to get
  subtly wrong unverified.
- **`defineOptions({ layout: AppLayout })`** is per page. The Studio pages from Phases 3 and 4 do
  not have it, so they will render without the top bar until they do.
- **`v-html` on the paginator labels** is Laravel's own `&laquo;` markup, not user input — but it is
  the one `v-html` in the codebase and worth replacing with explicit Previous/Next buttons.
- **Pagination links are absolute URLs** from Laravel. If the app ever runs behind a path prefix,
  `TRUSTED_PROXIES` and `APP_URL` are what make them correct.
