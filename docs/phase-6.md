# Phase 6 — Casual mode

Written code, nothing run. Small, because the run model and the end-of-run transaction already
existed — which is why strict came first.

## The hash format has exactly one home

`app/Support/AnswerHash.php`:

```
sha256(salt + ":" + question_id + ":" + correct_option_text)
```

That string is a contract between PHP and TypeScript: the server hashes the stored correct option,
the browser hashes whatever the player clicked, and they must agree byte for byte. Built in two
places they would eventually drift, and the symptom would be *every* casual answer reading as
wrong — a bug that looks like a logic error and is actually a whitespace change.

So the option text is hashed **exactly as stored** — no trimming, no case folding. The client
hashes exactly the string it was sent, and any normalisation here would break the comparison.
Publish validation already guarantees the four options are distinct after trimming and case
folding, so there is no collision to design around.

**The salt goes to the browser.** It has to: the client needs it to hash a click. That is also what
makes the answers readable to a determined user, who can hash all four options and see which
matches. The spec is explicit that this is anti-*casual*-tampering only, which is the whole reason
casual results carry the "self-reported" label (R6.5).

What the per-run salt does buy: hashes cannot be reused across runs or precomputed from a word
list. A test asserts two runs get different salts.

## What the server trusts (R6.4)

| | |
|---|---|
| **Trusted** | `time_ms`, and a reported `timeout` / `focus_lost` — the client ran the clock, so there is nothing to compare against |
| **Checked** | correctness, every time, against `correct_option_index` |
| **Rejected** | a log that is structurally impossible |

That third row is a judgment call worth stating. A log referring to a question outside the run, or
the same question twice, or one that stops without saying why, is not a "wrong answer" — it is a
client that cannot be interpreted. Those get **422** rather than a quietly recorded fail, so a
broken client shows up as a broken client.

**The incomplete-log rule is my interpretation, not the spec's.** Sudden death means a run ends for
a reason, so "I answered three of ten correctly and then stopped" is not a state the client is
allowed to report. If you would rather record that as a plain fail, it is one branch in
`SubmitCasualRun`.

Sudden death is applied *while reading* the log: processing stops at the first entry that is not
correct, and anything after it is ignored. A tampered log cannot smuggle extra correct answers in
behind a failure — there is a test for exactly that, asserting `reached` of 0 and one recorded
answer row from a log whose first entry lies and whose remaining four are honest.

## Two state machines, one screen

`useCasualRun` is separate from `useStrictRun` rather than a mode flag inside it. The two differ in
precisely the way that matters — strict waits for the server on every answer, casual never does —
and one composable trying to be both would bury that difference in conditionals.

The *screen* is shared, which is the point: a player should not have to learn two interfaces.
`Quiz.vue` instantiates only one of the two, because each registers a focus guard and a timer, and
two live guards would both fire on a hidden tab.

What casual does not have is a **`checking` phase** (R6.2). `useCasualChecker` hashes all four
options of every question up front, compares each against that question's `answer_hash`, and stores
a plain boolean per display position. By the time the player can click, the answer is a lookup — no
await, no spinner. The hashing runs *during* the 3-2-1 countdown (`Promise.all` of `prepare` and
`runCountdown`), so the first click never waits on crypto.

The client shuffles both questions and options itself and keeps `originalIndexes` — display position
to original index — because the submitted log carries **original** indexes. The server stores no
`option_orders` for a casual run, so it could not map a displayed index even if it wanted to.

`question_order` on a casual run holds the quiz's **authoring order**. For casual it is the
candidate *set*, not the play order; the play order is whatever the log turns out to be. It is still
what `questionCount()` and the membership check read.

## One route, two payloads

`POST /q/{quiz}/runs` branches on the **quiz's** mode, never the client's. A player cannot ask for
the casual payload on a strict quiz and walk away with the answers. Each verdict endpoint refuses
the other mode's runs with a 422, both covered by test.

## Phase 6 gate

```powershell
docker compose exec app php artisan test --filter=CasualRun
```

Covered: the payload shape and original option order, hash format checked against a literal
`hash('sha256', ...)` as well as against `AnswerHash`, no correct index anywhere in the response,
per-run salts, null `option_orders`, an honest pass, a tampered answer failing, smuggled answers
ignored, trusted timeout/focus-loss/timings, all four structurally-invalid rejections, both
wrong-endpoint refusals, double submit, ownership for users and guests, shared bookkeeping including
the creator exclusion, and the self-reported flag.

**By hand**: that casual play has no perceptible pause on a click, and that the countdown covers the
hashing.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Support/AnswerHash.php` | R6.1 |
| `app/Actions/Run/StartCasualRun.php` | R6.1 |
| `app/Actions/Run/SubmitCasualRun.php` | R6.3, R6.4 |
| `app/Http/Requests/SubmitCasualRunRequest.php` | R6.3 |
| `app/Http/Controllers/RunController.php` (edited) | mode branch + submit |
| `routes/web.php` (edited) | `runs.submit` |
| `resources/js/types/play.ts` (edited) | casual wire types |
| `resources/js/composables/useCasualChecker.ts` | R6.2 |
| `resources/js/composables/useCasualRun.ts` | casual state machine |
| `resources/js/components/ModeBadge.vue` | R6.6 |
| `resources/js/components/play/RunResult.vue` (edited) | shared verdict union |
| `resources/js/pages/Quiz.vue` (rewritten) | both modes |
| `tests/Feature/Play/CasualRunTest.php` | R6.1, R6.3–R6.5 |

## Likely first-run fixes

- **SubtleCrypto needs a secure context.** `https://` and `http://localhost` both qualify, so dev
  and Railway are fine — but the app served over plain http on a LAN address would silently have no
  `crypto.subtle`, and casual play would break with a null error. Worth a guard if that ever
  becomes a real setup.
- **`ModeBadge` is not on the Explore cards yet** (R6.6 says cards *and* play screen). Explore is
  Phase 7, which is where the cards get it; the Studio list still renders its mode as plain text and
  could use the badge too.
- **`Quiz.vue` uses non-null assertions** (`casual!`, `strict!`) around the one-of-two composables.
  Correct at runtime since `isCasual` is constant for the page's lifetime, but a discriminated
  wrapper would be cleaner if this grows.
