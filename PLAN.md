# Vocabulary Quiz Platform — MVP Build Plan

Source of truth: `quiz-prd-v2.pdf`. Target: `quiz-app/`.
Plan written 2026-11-25. Estimated **13–16 dev-days** across 11 phases.

> **Status, 2026-11-26: all 11 phases written (R0.1 → R10.5). Nothing has been run, built or
> tested.** The Laravel starter kit still has to be scaffolded in — see `docker.md` for the
> scaffold-then-merge sequence — and each phase's doc in `docs/` lists what to expect on first run.
> Start with Phase 0's gate and work forward; `QA.md` covers what the test suite cannot reach.

Each phase states its **requirements (R)** first, then the files that serve them, then the **gate**
that must pass before the next phase starts. No phase is "done" until its gate is green.

---

## Version check (done 2026-11-25, from npm + Packagist directly)

| Package | Latest stable | Note |
|---|---|---|
| `laravel/framework` | **v13.44.1** | requires PHP ^8.3 |
| `vue` | **3.5.46** | 3.6 is still `rc.16` → spec's "no pre-releases" rule keeps us on 3.5 |
| `@inertiajs/vue3` | **3.11.0** | Inertia 3 as the spec assumes |
| `tailwindcss` | **4.3.5** | |
| `laravel/socialite` | **v5.33.0** | |
| `pestphp/pest` | **v5.3.7** | |
| `laravel/wayfinder` | **v0.1.23** | still 0.x — pin exactly, treat as unstable API |

The spec's stack assumptions all hold. Only Wayfinder needs care: a 0.x minor can break generated
route types, so pin it and re-generate types deliberately.

---

## Phase 0 — Environment & scaffold (1 day)

**Requirements**
- R0.1 Docker Compose with exactly three services: `db`, `app`, `webserver`.
- R0.2 The `app` image carries **PHP and Node together**, because Wayfinder's Vite plugin shells out
  to `php artisan wayfinder:generate`. No separate vite service.
- R0.3 Laravel 13 + official Vue starter kit (Inertia 3, Vue 3 Composition API + TS, Tailwind 4,
  Wayfinder). If the kit ships Inertia 2, upgrade it.
- R0.4 PostgreSQL for dev; SQLite in-memory for tests; both configured and both working.
- R0.5 Write `docker.md` — the spec references it but it does not exist.
- R0.6 The same Dockerfile carries a **production target** for Railway (see Deployment), so dev and
  prod never drift apart. Build stage has PHP + Node; runtime stage is PHP only.

**Files**
- `docker-compose.yml`, `docker/app/Dockerfile`, `docker/webserver/*.conf` — serve R0.1, R0.2
- `.env.example`, `phpunit.xml` (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — serve R0.4
- `vite.config.ts` — Wayfinder plugin, host `0.0.0.0` so HMR reaches the browser — serves R0.2
- `docker.md` — serves R0.5

**Gate** — `docker compose up` serves the starter page with Vite HMR working from inside `app`, and
`php artisan test` runs green against SQLite in-memory.

---

## Phase 1 — Auth: Google only (1 day)

**Requirements**
- R1.1 Google sign-in via Socialite is the only auth path.
- R1.2 All starter-kit password, registration, reset and verification routes/pages/tests are
  **removed**, not just hidden.
- R1.3 First sign-in creates the user from Google's `id`, name, email, avatar. Every later login
  refreshes name and avatar.
- R1.4 `email` is never serialised to the frontend. Name and avatar are public.
- R1.5 Guests get a random `guest_token` in a long-lived cookie, issued once and stable.

**Files**
- `app/Http/Controllers/Auth/GoogleController.php` — redirect + callback, upsert by `google_id` — R1.1, R1.3
- `app/Models/User.php` — hidden attributes cover email, remember_token, google_id — R1.4
- `app/Http/Middleware/EnsureGuestToken.php` — R1.5
- `routes/auth.php` — reduced to `/auth/google/redirect`, `/auth/google/callback`, `POST /logout` — R1.2

**Gate** — Pest: first callback creates a user; second callback with changed Google data updates name
and avatar and creates no second row; removed auth routes return 404; an Inertia page response for a
signed-in user contains no email; a guest's second request reuses the same token.

---

## Phase 2 — Schema & models (1.5 days)

Built **one table per step**, each with its role and its reasons, because later phases all read from
these shapes. Portability rule throughout: no Postgres-only column types or SQL functions, since
tests run on SQLite.

**Requirements**
- R2.1 Seven tables per §4: `users`, `quizzes`, `questions`, `quiz_votes`, `quiz_runs`,
  `run_answers`, `quiz_user_stats`.
- R2.2 Public URLs never expose autoincrement IDs: `quizzes.ulid` unique, `quiz_runs.id` is a ULID PK.
- R2.3 `quiz_runs` holds exactly one of `user_id` / `guest_token`; `guest_token` indexed.
- R2.4 Denormalised counters on `quizzes` (`upvotes_count`, `runs_count`, `passes_count`,
  `reached_sum`) so Explore, public stats and suggestions stay single-query with no background jobs.
- R2.5 Cascade deletes: `questions`, `quiz_runs`, `run_answers`, `quiz_user_stats` follow their parent.
- R2.6 Factories for every model, plus a dev seeder that never runs in tests.

**Table order and why**
1. `users` — everything else points at it.
2. `quizzes` — the only content type; carries mode, timer, status, visibility and the counters.
3. `questions` — `options` as a JSON array of exactly 4 strings; `position` is authoring order only,
   because play order is always shuffled.
4. `quiz_votes` — unique `(user_id, quiz_id)` is what makes the vote a toggle rather than a counter.
5. `quiz_runs` — the heart of gameplay: stores the shuffles (`question_order`, `option_orders`), the
   casual `salt`, and the strict cursor (`current_index`, `current_issued_at` with ms precision).
6. `run_answers` — append-only analytics; one row per answered question.
7. `quiz_user_stats` — unique `(user_id, quiz_id)` upsert target for attempts / best / passed.

**Gate** — `migrate:fresh` succeeds on Postgres **and** on SQLite in-memory; a factory smoke test
creates a quiz with 5 questions, a run and an answer row; deleting a draft cascades.

---

## Phase 3 — Creator Studio: authoring (2 days)

**Requirements**
- R3.1 `/studio` lists the signed-in user's quizzes with status and visibility.
- R3.2 Draft create and edit: title (≤80), description (≤500), mode, `seconds_per_question` 2–10.
- R3.3 Question CRUD inside a draft: prompt (≤200 plain text), 4 options (≤80 each), pick the correct one.
- R3.4 **Draft save validates length limits only** — a draft may be incomplete.
- R3.5 Studio routes are creator-only; drafts are visible and playable only by their creator.

**Files**
- `app/Http/Controllers/Studio/QuizController.php` — R3.1, R3.2
- `app/Http/Controllers/Studio/QuestionController.php` — R3.3
- `app/Http/Requests/Studio/StoreQuizRequest.php`, `StoreQuestionRequest.php` — R3.4
- `app/Policies/QuizPolicy.php` — `view`, `update`, `delete`, `publish` — R3.5
- `resources/js/pages/studio/{Index,Edit}.vue` — R3.1–R3.3

**Gate** — Pest: draft CRUD round-trips; a second user gets 403 on every studio route for someone
else's quiz; an incomplete draft saves; an over-length field fails.

---

## Phase 4 — Lifecycle: publish, unpublish, duplicate, delete (1 day)

**Requirements**
- R4.1 Publish validation: 5–15 questions; each with a non-empty prompt and exactly 4 non-empty
  options distinct after trimming and case-folding; `correct_option_index` set; timer 2–10; mode set.
- R4.2 Published quizzes are **permanently immutable** — questions, options, timer, mode. Enforced at
  the request layer *and* in the model, so no code path can mutate them.
- R4.3 The publish dialog chooses Listed or Unlisted, asked again on **every** publish including
  republishing. `published_at` records the first publish only.
- R4.4 Unpublish: non-creators see "This quiz is no longer available"; other players' `in_progress`
  runs close as `abandoned` on their next request; history shows "Unavailable" with stats intact and
  no "Play again"; creator analytics stay fully available.
- R4.5 Duplicate as draft — the only edit path for published content. The copy starts with no runs,
  analytics or votes.
- R4.6 Only drafts can be deleted: hard delete, cascading the creator's test runs. Published and
  unpublished quizzes can never be deleted.

**Files**
- `app/Actions/Quiz/{PublishQuiz,UnpublishQuiz,DuplicateQuiz}.php` — R4.1, R4.3, R4.4, R4.5
- `app/Http/Requests/Studio/PublishQuizRequest.php` — R4.1
- `app/Models/Quiz.php` — a `saving` guard rejecting edits when status is not draft — R4.2
- `app/Http/Middleware/CloseStaleRuns.php` — R4.4

**Gate** — the whole lifecycle group from §12: immutability holds against a direct model update,
draft-only delete, every publish-validation branch, and each unpublish effect.

---

## Phase 5 — Strict mode gameplay (2.5 days)

The hardest phase: server-authoritative timing plus the full play UI. Everything after this reuses
the run model and the end-of-run transaction, which is why strict comes before casual.

**Requirements**
- R5.1 `POST /q/{quiz}/runs` shuffles and stores `question_order` + `option_orders`, abandons any
  `in_progress` run by the same player on the same quiz, returns **only the first question** with no
  answer data, and sets `current_issued_at`.
- R5.2 `POST /runs/{run}/answers` takes `{ displayed_index }` or `{ reason: timeout | focus_lost }`,
  maps displayed → original index via `option_orders`, and writes a `run_answers` row.
- R5.3 **The server's clock decides.** If `received_at - current_issued_at > seconds_per_question +
  grace`, it is a timeout whatever was clicked. `grace` is config, ~1.5s, to absorb latency.
- R5.4 Correct and not last → verdict + next question + reset `current_issued_at`. Correct and last →
  pass. Otherwise fail, **with no correct answer revealed**.
- R5.5 Questions are never sent ahead, so players cannot read ahead.
- R5.6 Sudden death: wrong answer, timeout or focus loss ends the run immediately.
- R5.7 Focus loss fails the run only while a question is showing and its timer is running — not during
  "checking…", the countdown, or result screens.
- R5.8 Client timer is display-only; it pauses during the round trip behind a "checking…" animation,
  and sends `reason: timeout` when it hits 0.
- R5.9 Controls: desktop (`hover: hover` and `pointer: fine`) uses the WASD layout — W centred on top,
  A/S/D beneath, key hints drawn on the options, Enter to start/retry. Touch gets a 2×2 grid with no
  hotkeys and no hints. No other hotkeys at all.
- R5.10 Result screens: fail highlights the picked option red or shows "Time's up" / "Tab left", plus
  "Reached X/Y", "Attempt #N — Best: X/Y" for signed-in users, and Retry with a fresh shuffle. Win
  shows confetti and "Perfect! Y/Y".
- R5.11 End-of-run bookkeeping in **one transaction**: run `status`/`failure_reason`/`reached`/
  `finished_at`; if the runner is not the creator, increment `runs_count` and `reached_sum` and
  `passes_count` on a pass; for signed-in users upsert `quiz_user_stats`.
- R5.12 Run endpoints accept requests only from the run's owner (matching `user_id` or `guest_token`).

**Files**
- `app/Http/Controllers/RunController.php` — `store`, `answer` — R5.1, R5.2, R5.12
- `app/Actions/Run/{StartRun,AnswerQuestion,FinishRun}.php` — R5.1–R5.4, R5.11
- `config/quiz.php` — `grace_seconds` — R5.3
- `resources/js/pages/Play.vue`, `components/play/{Countdown,OptionGrid,Checking,ResultFail,ResultWin}.vue` — R5.8–R5.10
- `resources/js/composables/{useRunTimer,useFocusGuard,useHotkeys}.ts` — R5.7–R5.9
- A minimal quiz page with a Start button ships here so runs can be exercised; the full page is Phase 7.

**Gate** — strict timing tests (in time, past the limit, inside grace, beyond grace), sudden-death
outcomes for all three failure reasons, and a payload assertion proving **no answer data** reaches the
client. Plus by hand: WASD, touch, focus loss, countdown.

---

## Phase 6 — Casual mode (1 day)

**Requirements**
- R6.1 Start returns all questions (options in original order) plus
  `answer_hash = sha256(salt + ":" + question_id + ":" + correct_option_text)` per question.
- R6.2 The client shuffles questions and options itself, precomputes hashes on load via SubtleCrypto,
  and so has **no "checking…" state**. The client runs the timer.
- R6.3 `POST /runs/{run}/submit` takes the answer log
  `[{ question_id, chosen_option_index | null, reason?, time_ms }]`.
- R6.4 The server **re-checks correctness against the real answers** and derives pass/fail itself. It
  trusts the reported timings and the `timeout` / `focus_lost` reasons.
- R6.5 Casual results are labelled "self-reported" in creator analytics. This is anti-casual-tampering
  only — a determined user can read the answers.
- R6.6 A Strict / Casual badge shows on quiz cards and on the play screen.

**Files**
- `app/Actions/Run/{StartCasualRun,SubmitCasualRun}.php` — R6.1, R6.3, R6.4
- `app/Support/AnswerHash.php` — the one place the hash format lives — R6.1
- `resources/js/composables/useCasualChecker.ts` — R6.2
- `resources/js/components/ModeBadge.vue` — R6.6

**Gate** — Pest: a tampered answer log is rejected and recorded as a fail; the hash format is covered
by its own test; `time_ms` is stored as reported.

---

## Phase 7 — Public surfaces: Explore, quiz page, My plays (1.5 days)

**Requirements**
- R7.1 `GET /` shows published, **listed** quizzes. Sorts: Popular (`upvotes_count`, default), Most
  played (`runs_count`), Newest (`published_at`). Search over title and description. Mode filter
  All / Strict / Casual.
- R7.2 Quiz card: title, creator name and avatar, mode badge, question count, seconds per question,
  upvotes, plays.
- R7.3 `GET /q/{ulid}` is also the share link for unlisted quizzes. Shows title, description, creator,
  mode badge, counts, upvote button and count, plays, a big Start button and the 3-2-1 countdown.
- R7.4 General stats: pass rate and average reached, **excluding the creator's own runs**. Under 10
  counted runs it shows "Not enough plays yet". No difficulty label anywhere.
- R7.5 Your stats for signed-in users: attempts, best result, passed.
- R7.6 `GET /plays` lists every quiz the user attempted, most recent first, with status, attempts,
  best, first-passed date and "Play again". Unpublished quizzes show "Unavailable".
- R7.7 Layout: top bar with logo and search; right side shows Creator Studio and the avatar menu, or
  "Sign in with Google". Left sidebar shows a signed-in user's 5 most recent attempts with "Show all",
  or the "Sign in to track your progress" card for guests.
- R7.8 Guests can browse Explore, open quiz pages and play published quizzes, listed or unlisted.
  Clicking vote or Creator Studio prompts sign-in. Guest history is **not** carried over at signup.

**Files**
- `app/Http/Controllers/{ExploreController,QuizController,PlaysController}.php` — R7.1, R7.3, R7.6
- `resources/js/pages/{Explore,Quiz,Plays}.vue`, `components/QuizCard.vue` — R7.1–R7.6
- `resources/js/layouts/AppLayout.vue`, `components/Sidebar.vue` — R7.7

**Gate** — Pest: the creator's own runs are excluded from counted stats; the 10-run floor flips the
display; an unlisted quiz is absent from Explore but reachable by its ULID; a guest can play it.

---

## Phase 8 — Voting + suggestions (1 day)

**Requirements**
- R8.1 `POST /q/{quiz}/vote` toggles. Signed-in users only, published **and listed** quizzes only, never
  your own quiz, and having played is not required.
- R8.2 `upvotes_count` updates in the **same transaction** as the vote row.
- R8.3 One mixed list of up to 12 suggestions on the quiz page. Candidates: published, listed quizzes
  other than the current one **that the viewer has not passed**.
- R8.4 `score = w_author·(same creator) + w_stats·(1 - |pass_rate_a - pass_rate_b|) + w_votes·log10(1 + upvotes_count)`,
  defaults 3 / 2 / 1, **in config**.
- R8.5 If either quiz has fewer than 10 counted runs, the stats term uses a neutral `0.5`.
- R8.6 At most 3 suggestions from the same creator.
- R8.7 One SQL query over the cached columns on page load. No background jobs. **`log10` and `abs` must
  work on SQLite** or that term is computed in PHP — decide here and write the test both ways.

**Files**
- `app/Http/Controllers/VoteController.php` — R8.1, R8.2
- `config/quiz.php` — `suggestions.weights` — R8.4
- `app/Queries/SuggestedQuizzes.php` — R8.3–R8.7

**Gate** — Pest: toggle on/off, own-quiz rejection, unlisted rejection, guest rejection; suggestion
scoring matches hand-computed values, the per-creator cap of 3 holds, passed quizzes are excluded, and
the query runs on SQLite.

---

## Phase 9 — Creator analytics (1 day)

**Requirements**
- R9.1 `GET /studio/quizzes/{quiz}/stats` totals: runs, unique players, pass rate.
- R9.2 Per question: correct rate, most-picked wrong option, average answer time, and the
  timeout vs wrong vs focus-lost split.
- R9.3 Per player: name, attempts, best result, first pass date. **Guests are grouped into a single
  "Guests" row.**
- R9.4 Casual quizzes are labelled "self-reported".
- R9.5 Analytics stay fully available after unpublishing.

**Files**
- `app/Http/Controllers/Studio/StatsController.php` — R9.1
- `app/Queries/QuizAnalytics.php` — R9.1–R9.3
- `resources/js/pages/studio/Stats.vue` — R9.1–R9.4

**Gate** — Pest: aggregates match a hand-built fixture of runs and answers; guests collapse to one
row; an unpublished quiz still renders its stats.

---

## Phase 10 — Polish & hardening (1 day)

**Requirements**
- R10.1 Rate limits: starting runs per user, or per guest token **and** IP (e.g. 30/min); voting per
  user (e.g. 30/min).
- R10.2 Three sound slots — in-game loop, fail, win — wired to placeholder files at
  `resources/sounds/{game-loop,fail,win}.*`, to be swapped for real effects later.
- R10.3 A mute toggle remembered per device in `localStorage`, **wrapped in try/catch**.
- R10.4 Empty states, loading states and error states across Explore, Studio and My plays.
- R10.5 A written manual-QA checklist for everything the test suite cannot reach: WASD and touch
  layouts, focus-loss detection, countdown, confetti, sound.

**Files**
- `bootstrap/app.php` — named rate limiters — R10.1
- `resources/js/composables/useSound.ts` — R10.2, R10.3
- `QA.md` — R10.5

**Gate** — the full Pest suite green on SQLite in-memory, rate limits covered by tests, and the manual
checklist walked once end to end.

---

## Deployment — Railway (decided 2026-11-25)

One Railway project, **two services**: the Laravel app and Railway's managed Postgres. Inertia stays
a monolith, which is what it is designed to be — there is no separate frontend to host anywhere.

**Why this is the right shape for this app specifically**

- The app runs in a **long-lived container**, not a serverless function. Strict mode's `grace` is
  ~1.5s and exists to absorb *network latency only*. A cold start would eat the whole budget and fail
  honest players, so persistent compute is a correctness requirement here, not a preference.
- Timing state lives in `quiz_runs.current_issued_at`, not in memory, so horizontal replicas stay
  correct if you ever scale out.
- Nothing needs a persistent filesystem: sounds are build assets, avatars are Google URLs, and the
  spec has **no background jobs**, so no queue worker or scheduler service is needed.

**Setup**

| Step | What |
|---|---|
| 1 | Postgres service first. Set the app's `DB_URL` to the reference `${{Postgres.DATABASE_URL}}`, **not** the public URL, so traffic stays on Railway's private network. |
| 2 | App service builds from the Dockerfile's prod target (R0.6). FrankenPHP is the simplest runtime — one process, Caddy-based, no nginx + php-fpm + supervisor stack. |
| 3 | The start script must bind to Railway's injected **`$PORT`**. Hardcoding 8080 is the most common failure. |
| 4 | Migrations go in the **Pre-Deploy Command** (`php artisan migrate --force`) so a failed migration aborts the deploy instead of booting a broken app. Invoke a shell explicitly; bare `cd … &&` has been reported to fail there. Verify the DB reference resolves in the pre-deploy phase — community reports say it does not always. |
| 5 | `TRUSTED_PROXIES=*`. Railway terminates TLS at its edge, so without this Laravel generates `http://` URLs and the Google OAuth redirect and secure cookies break. This is the classic first-deploy bug. |
| 6 | Google OAuth: add the Railway domain's `/auth/google/callback` to the authorised redirect URIs. Keep a separate OAuth client for local dev. |
| 7 | Healthcheck path `/up` (Laravel ships it). Same region for both services. |
| 8 | Session and cache drivers stay `database` — no Redis service needed for MVP. **File sessions must not be used**, since they break across redeploys and replicas. |
| 9 | **Turn App Sleeping off.** Scale-to-zero reintroduces exactly the cold-start problem described above. |

**Env to set:** `APP_KEY`, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_URL`,
`TRUSTED_PROXIES`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`,
`SESSION_DRIVER=database`, `CACHE_STORE=database`.

**Unchanged by this decision:** local dev stays Docker Compose with `db` / `app` / `webserver`, and
tests stay SQLite in-memory. Only the prod target and the env list are new.

## Why this order

- Auth before schema, because every table's foreign keys and every policy depend on a real user.
- Authoring (3) before lifecycle (4) before play (5): you cannot publish what you cannot create, and
  you cannot play what you cannot publish.
- Strict (5) before casual (6): strict builds the run model, the shuffle storage and the end-of-run
  transaction that casual reuses. Casual is then a thin alternative entry and exit.
- Public surfaces (7) after gameplay, because Explore and the quiz page display counters that only
  real runs populate. During phases 3–6 you reach quizzes by URL; a minimal quiz page with a Start
  button ships in Phase 5 so runs are exercisable.
- Voting and suggestions (8) after Explore, since suggestions filter on listed + passed state.
- Analytics (9) last of the features, because it only reads what phases 5–6 write.

## Open questions to settle before Phase 0

1. ~~**Production deployment is undefined**~~ — **settled 2026-11-25: Railway**, app and Postgres as
   two services in one project. See the Deployment section. Phase 0 therefore builds dev + prod
   targets (R0.6).
2. **`docker.md` does not exist.** Phase 0 writes it; confirm there is no existing version to follow.
3. **Sound assets** are placeholders. Confirm the final format (mp3/ogg) so Phase 10 does not need a
   rewrite.
4. **`grace` default** — the spec says "about 1.5s". Confirm 1.5s as the committed config default.
5. **SQLite `log10`** — R8.7 forks the implementation. Worth deciding at Phase 2, not Phase 8.
