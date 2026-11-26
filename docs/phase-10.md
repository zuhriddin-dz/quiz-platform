# Phase 10 — Polish & hardening

Written code, nothing run. **Last phase: the MVP's requirements R0.1 to R10.5 are all written.**

## Rate limits (R10.1)

Two named limiters in a provider of their own — `RateLimitServiceProvider`, rather than lines in
`AppServiceProvider`, so the starter kit's own registrations are untouched. **It needs listing in
`bootstrap/providers.php`**, which this phase writes; if the kit registered providers beyond
`AppServiceProvider`, merge rather than let that file replace theirs.

| Limiter | Key | Default |
|---|---|---|
| `start-run` | user id, or **guest token + IP** | 30/min |
| `vote` | user id | 30/min |

The guest key combines token **and** IP, as the spec words it, and both halves matter: the token
alone is a cookie anyone can clear, while the IP alone would throttle a whole school or office
sharing one address — and guests are the majority of players here, so that failure mode costs more
than the bypass does. A test covers each direction.

**Answering is deliberately NOT throttled.** A strict run sends one request per question under a
2–10 second timer, so a limit tight enough to matter would fail honest players on a fast quiz, and
sudden death already bounds how many answers a run can produce. There is a test that walks a
15-question quiz to a pass while the start limit is set to 3/min, so the exemption is pinned down
rather than accidental.

Starting a run is the one worth limiting: it shuffles, writes a row and abandons the previous run,
so an unthrottled loop is both a write amplifier and a way to farm a quiz's `runs_count`. The limit
is per **player**, not per quiz — otherwise the cap multiplies by however many quizzes exist, and
there is a test for that too.

## Sound (R10.2, R10.3)

Three slots in `useSound`, with **module-scoped state**: the toggle lives in the top bar and
playback happens on the quiz page, so they have to share one mute flag and one set of audio
elements. Calling `useSound()` twice returns the same thing.

Playback is driven off the **run phase**, not off individual events, so one `watch` decides
everything that is audible: loop on `countdown`, win on `passed`, fail on `failed`, silence on a
return to `idle`. The loop starting at the countdown is always inside the user gesture that pressed
Start, which is what satisfies browser autoplay policy — nothing plays on page load.

Every `localStorage` access is wrapped (R10.3). Safari in private mode throws on `setItem`, and an
exception there would take the whole play screen down over a mute button.

### A deviation: `public/sounds/`, not `resources/sounds/`

The spec names `resources/sounds/{game-loop,fail,win}.*`. Files under `resources/` are bundled by
Vite, which **fails the build when an imported asset is missing** — so placeholder slots could not
exist as placeholders. Served from `public/`, a missing file is a silent 404 and the sound simply
does not play, which is the right behaviour until the real effects arrive.
`public/sounds/README.md` says what to drop in and how to move them back to `resources/` for
cache-busting once they are real.

The paths live in two places only: `config/quiz.php` and the `SOURCES` map in `useSound.ts`.

## Empty, loading and error states (R10.4)

**Empty:** one `EmptyState` component, used by Explore, My plays and the Studio list. It exists for
consistency rather than code saving — three hand-written dashed boxes drift in padding, tone and
text size, and an empty app is the first thing a new user sees. Explore gets **two** different ones,
because they need different answers: a filter that matched nothing is the user's doing, an empty
site is ours.

**Loading:** a thin indeterminate bar in `AppLayout`, driven by Inertia's `router.on('start'/'finish')`.
One place covers every navigation, Explore's filters and the Studio's actions. It is deliberately
slight — a full-page spinner on a 60 ms local visit is worse than nothing — and it respects
`prefers-reduced-motion`.

**Error:** a real gap until now. A 403 or 404 fell through to Laravel's own error page: a dead end
with no top bar, no sidebar and no way back. `bootstrap/app.php` now renders those statuses as an
Inertia `Error` page, with copy per status.

Two exclusions in that handler, both load-bearing:

1. **JSON requests pass straight through.** The play loop depends on real status codes — 403 for a
   run that is not yours, 409 for a finished one, 422 for a bad body, 429 for the throttle — and
   wrapping those in HTML would break every verdict.
2. **Local development passes through too**, because Laravel's stack trace is far more useful than
   a polite message while building.

## QA.md (R10.5)

13 sections covering what the suite cannot reach, since the spec rules out browser tests. It is
written to be *walked*, not skimmed: each item is a single observable thing, and the ones most
likely to regress are called out.

The two worth re-reading: **focus loss** (section 4) has eight cases, including the critical
negative one — clicking devtools or the URL bar must *not* fail a run, because the guard listens to
`visibilitychange` and not `blur`; and **touch layout** (section 2) warns against a desktop
browser's device emulator, which can report `pointer: fine` and so show the wrong layout.

## Phase 10 gate

```powershell
docker compose exec app php artisan test
```

The whole suite, not a filter — this is the last phase.

```powershell
docker compose exec app npm run build
```

Then walk `QA.md` once end to end.

## Files written in this phase

| File | Serves |
|---|---|
| `config/quiz.php` (edited) | R10.1, R10.2 |
| `app/Providers/RateLimitServiceProvider.php` | R10.1 |
| `bootstrap/providers.php` | registers it |
| `routes/web.php` (edited) | applies both throttles |
| `resources/js/composables/useSound.ts` | R10.2, R10.3 |
| `resources/js/components/SoundToggle.vue` | R10.3 |
| `public/sounds/README.md` | R10.2 placeholders |
| `resources/js/components/TopBar.vue` (edited) | mute toggle |
| `resources/js/pages/Quiz.vue` (edited) | sound wired to phases |
| `resources/js/components/EmptyState.vue` | R10.4 |
| `resources/js/pages/Error.vue` | R10.4 |
| `bootstrap/app.php` (rewritten) | R10.4 error rendering |
| `resources/js/layouts/AppLayout.vue` (edited) | R10.4 loading bar |
| `resources/js/pages/Explore.vue` (edited) | R10.4 empty states |
| `resources/js/pages/Plays.vue` (edited) | R10.4 empty state |
| `resources/js/pages/studio/Index.vue` (edited) | R10.4 empty state |
| `tests/Feature/RateLimitTest.php` | R10.1 |
| `QA.md` | R10.5 |

## Likely first-run fixes

- **`bootstrap/providers.php`** is the one file in this phase most likely to need merging rather
  than replacing. Check the scaffold's version first.
- **`$exceptions->respond()`** signature varies slightly across Laravel 11/12/13 point releases. If
  it errors, the shape is `fn (Response $response, Throwable $e, Request $request)`.
- **The rate-limit tests set config at runtime**, which works because the limiter closure reads
  `config()` on each request rather than at boot. If they fail with the default 30/min, the closure
  is being resolved once — move the `config()` read inside the returned `Limit`.
- **`router.on()` returns an unsubscribe function** in Inertia 2 and 3. If it returns void in this
  version, drop the `stopStart?.()` calls.
- **The 429 page needs a non-JSON request to be seen.** The play loop gets a JSON 429 instead, which
  `useStrictRun` surfaces as "Could not start the run" — acceptable, but a dedicated message for
  429 would be kinder.
