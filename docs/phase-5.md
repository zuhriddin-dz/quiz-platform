# Phase 5 — Strict mode gameplay

Written code, nothing run. The largest phase: the server-authoritative engine plus the whole play UI.

## The run endpoints return JSON, not Inertia

The quiz page is an Inertia page, but a run is a sequence of small exchanges inside it. Re-rendering
a page per question would make the timing worse and the code stranger, so `POST /q/{quiz}/runs` and
`POST /runs/{run}/answers` return JSON and the Vue component drives them with `fetch`.

They still live in the **web** middleware group, because a run's identity is the session (for users)
or the guest cookie (R5.12) — not a bearer token. That also means CSRF applies, which
`useStrictRun` handles by reading the `csrf-token` meta tag.

## The timing rule, in decision order (R5.3)

This order *is* the rule, so `AnswerQuestion` states it explicitly:

1. **The server's clock first.** If `received_at - current_issued_at > seconds_per_question + grace`,
   it is a timeout — "whatever was clicked". A correct answer that arrives late is still a loss.
2. **Then a client-reported reason** (`timeout` or `focus_lost`).
3. **Then the actual choice**, mapped from display position to stored option.

Any other order would let a slow client claim a late correct answer, which is the one thing strict
mode exists to prevent. There is a test for exactly that: a *correct* index sent 30s late fails as
a timeout.

`received_at` comes from **`REQUEST_TIME_FLOAT`**, not `now()`, so our own queueing and framework
boot are not charged to the player. `grace` is `config('quiz.grace_seconds')`, default 1.5s, and the
timing test pins all four boundary points for a 5s timer: 1.0s in, 6.0s inside grace, 7.0s past it,
30s far past.

## No answer can reach the browser (R5.5)

`app/Support/QuestionPayload.php` is the only place a question becomes JSON. It builds the payload
by **listing fields**, never by hiding them, so a new column on `questions` cannot appear in a
response by accident. Nothing in it reads `correct_option_index`.

The same class owns the reverse mapping (`toOriginalIndex`), so display order and its inverse are
written once. An index the run never displayed returns null, which is what a tampered request
produces — and null is simply not correct, so it fails as a wrong answer.

`resources/js/types/play.ts` is the client-side proof: nothing in those types carries a correct
answer or the original option order.

## The client state machine

```
idle -> countdown -> question -> checking -> question -> ... -> passed | failed
```

`useStrictRun` owns every transition, because the rules that make strict mode fair *are*
transitions:

| Rule | How |
|---|---|
| R5.8 the timer pauses for the round trip | `timer.pause()` on entering `checking` |
| R5.7 focus loss is fatal only on a live question | `useFocusGuard(() => phase === 'question' && timer.running)` |
| exactly one answer per question | an `answerSent` latch, not a disabled button — a click, a keypress, the timer and the focus guard can all fire in one frame |

**The run starts before the countdown**, so the first question is already in hand when it reaches
zero. The server issues its clock on the question it hands back, and the client timer only starts
when the question is actually shown — so the countdown is not charged to the player.

Two deliberate details in the composables:

- `useRunTimer` measures with `performance.now()` **deltas**, not interval tick counts. A
  background tab throttles timers to once a second or worse, and this timer is the thing that fires
  `timeout` — a tick count would drift badly.
- `useFocusGuard` listens to `visibilitychange` only, as the spec says. Window `blur` is ignored on
  purpose: clicking devtools, another window or the URL bar blurs without hiding, and failing a run
  for that would be indefensible.

A mid-answer network failure stops the run and says so rather than retrying. Re-sending could
answer the *next* question by accident, since the server may already have advanced.

## Controls (R5.9)

`usePointerLayout` tests `(hover: hover) and (pointer: fine)` — a question about the **input
device**, not the screen, which is why it is not a width breakpoint. A narrow desktop window still
has a mouse; a large tablet still does not. It re-checks on change, since a tablet can gain a
keyboard case mid-session.

Keys map to **display positions** (W = top, then A/S/D), so they mean the same thing visually every
run even though the options behind them are shuffled. Modified presses are ignored, so `cmd+W`
still closes the tab. Touch gets a 2×2 grid with no hotkeys and no hints. `Enter` starts or retries,
on the keyboard layout only.

## Result screens (R5.10)

The options stay on screen behind the fail screen so the picked one can be highlighted red; a
timeout or focus loss gets a banner instead ("Time's up" / "Tab left"), because nothing was picked.
The correct answer is never shown.

"Attempt #N — Best: X/Y" renders only when the server sent numbers, which it does only for
signed-in players — a guest has no stats row.

**Confetti is a ~30-line canvas**, not a dependency. One more package for one moment of
celebration is not worth the install, and it honours `prefers-reduced-motion`.

## End-of-run bookkeeping (R5.11)

`FinishRun` does three writes in one transaction: the run's own fields, the quiz counters, the
`quiz_user_stats` upsert. Casual mode reuses it unchanged in Phase 6 — the bookkeeping does not care
how correctness was decided.

The subtle rule is the **creator exclusion**: a creator test-playing their own quiz would otherwise
poison the public pass rate, and they are the one person guaranteed to know the answers. Their run
and their own stats row are still recorded — excluded from public counters, not erased.

## Phase 5 gate

```powershell
docker compose exec app php artisan test --filter=Play
```

Covered: four timing points, server-side measurement, all three failure reasons, pass only on a
clean sweep, previous-run abandonment (and *not* other players' or other quizzes'), one question at
a time, no answer data in start or verdict payloads, request-shape rejection (both fields, neither,
`wrong_answer`), ownership for users and guests, guest play on listed and unlisted quizzes, creator
test-play of a draft, and the counter/stats arithmetic.

**By hand** (no browser tests, spec §12): WASD and touch layouts, focus-loss detection, the
countdown, confetti.

## Files written in this phase

| File | Serves |
|---|---|
| `config/quiz.php` | R5.3 |
| `app/Support/QuestionPayload.php` | R5.2, R5.5 |
| `app/Actions/Run/StartRun.php` | R5.1 |
| `app/Actions/Run/AnswerQuestion.php` | R5.2, R5.3, R5.4, R5.6 |
| `app/Actions/Run/FinishRun.php` | R5.11 |
| `app/Http/Requests/AnswerRunRequest.php` | R5.2 |
| `app/Http/Controllers/RunController.php` | R5.1, R5.2, R5.12 |
| `app/Http/Controllers/QuizPageController.php` | minimal quiz page |
| `routes/web.php` (edited) | play routes |
| `resources/js/types/play.ts` | R5.5 |
| `resources/js/composables/usePointerLayout.ts` | R5.9 |
| `resources/js/composables/useRunTimer.ts` | R5.8 |
| `resources/js/composables/useFocusGuard.ts` | R5.7 |
| `resources/js/composables/useStrictRun.ts` | the state machine |
| `resources/js/components/play/Countdown.vue` | 3-2-1 |
| `resources/js/components/play/OptionGrid.vue` | R5.9 |
| `resources/js/components/play/RunResult.vue` | R5.10 |
| `resources/js/pages/Quiz.vue` | hosts the loop |
| `tests/Feature/Play/StrictTimingTest.php` | R5.3 |
| `tests/Feature/Play/StrictRunTest.php` | R5.1, R5.4–R5.6, R5.12 |
| `tests/Feature/Play/RunBookkeepingTest.php` | R5.11 |

## Likely first-run fixes

- **`csrf-token` meta tag.** `useStrictRun` reads one. The starter kit's `app.blade.php` may not
  include it; if answers 419, add `<meta name="csrf-token" content="{{ csrf_token() }}">`.
- **`option_orders` key types.** JSON object keys are strings, so `QuestionPayload` looks up both
  `(string) $id` and `$id`. If a lookup ever misses, the permutation falls back to identity order —
  which would silently make the shuffle a no-op rather than erroring, so that fallback is worth a
  second look once this runs.
- **The timing tests are wall-clock sensitive.** `issuedSecondsAgo(6.0)` sits 0.5s inside the grace
  boundary; a very slow CI box could tip it. Widening the gap to 5.5s/7.5s is the fix if it flakes.
- **`$run->reached++` then `isOnLastQuestion()`** reads `current_index`, which has not advanced yet
  — correct, but it is the line to re-read if a pass fires one question early.
