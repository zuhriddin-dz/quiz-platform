# Phase 4 — Lifecycle: publish, unpublish, duplicate, delete

Written code, nothing run.

## Immutability is two layers (R4.2)

| Layer | What it catches | Failure |
|---|---|---|
| `QuizPolicy::update` (Phase 3) | HTTP requests | 403 |
| `Quiz::booted()` + `Question::booted()` | everything else — our own future code, tinker, a seeder | `ImmutableQuizException` |

The model guard checks the **original** status, not the new one, so the publish transition itself
is allowed while the content it publishes is not. `Quiz::IMMUTABLE_ATTRIBUTES` lists what freezes:
`title`, `description`, `mode`, `seconds_per_question`, `user_id`, `ulid`.

Everything *not* on that list stays writable on purpose, and has to:

- `status` and `is_listed` — that is how publish and unpublish work
- `published_at` — set by the first publish
- `upvotes_count`, `runs_count`, `passes_count`, `reached_sum` — every finished run moves these

A test covers each of those separately, because freezing a counter by accident would break play in
a way no immutability test would notice.

`ImmutableQuizException` is a `LogicException`, not a validation error: the request layer already
refuses these, so reaching it means a bug in our code. It should blow up loudly, not be caught.

**One known gap:** the `Question` guard hooks model events, so it does not fire for query-builder
mass writes. `SyncQuestions` uses `->update()` and `->delete()` on a builder for exactly that
reason (it only ever runs on drafts, which the policy guarantees). If a future phase needs mass
writes to questions on a published quiz to be impossible, that needs a different mechanism.

## Unpublish is lazy, on purpose (R4.4)

`UnpublishQuiz` flips the status and nothing else. It does **not** close other players' runs,
because the spec says they are "closed as abandoned on their next request" — and because closing
them eagerly would kill a run mid-question, before the player could submit the answer they were
already looking at.

`CloseStaleRuns` middleware does it instead, on two conditions that are both necessary:

1. the quiz is no longer published, **and**
2. the quiz is not the player's own — a creator test-playing their own draft must not have their
   run killed

Cost is one indexed lookup per request; `quiz_runs (quiz_id, user_id, status)` and
`(quiz_id, guest_token, status)` exist for it, and the UPDATE only runs when something is stale. It
sits after `EnsureGuestToken` in the web group, because it needs the resolved token to find a
guest's runs.

Everything else about an unpublished quiz is a read-side rule needing no writes:

| Effect | Where it lives |
|---|---|
| non-creators see "no longer available" | `QuizPolicy::view` → the quiz page, Phase 7 |
| history shows "Unavailable", no Play again | My plays, Phase 7 |
| creator analytics stay available | `QuizPolicy::viewStats`, Phase 9 |
| counters and stats rows survive | nothing touches them |

## Publish (R4.1, R4.3)

`is_listed` is **required**, never defaulted. The spec says the choice is made again on every
publish including republishing, and a default would silently relist a quiz the creator had
deliberately unlisted. The dialog opens with neither option selected.

`published_at` is set with `??=` — the first publish only. Republishing must not reset it, or
Explore's "Newest" sort would let a creator bump their own quiz back to the top.

`CheckPublishable` returns human-readable blockers, and three callers share it: the form request
rejects a publish, the Studio list disables the button and says why, and Phase 3's editor shows
guidance. Per-question rules come from `Question::isComplete()`, so "four distinct non-empty
options" is written exactly once. Blockers name question **positions**, since that is what the
creator sees.

## Duplicate (R4.5)

Copied: quiz fields, every question in order, positions renumbered from 1.

Not copied, and each matters: runs, run answers, votes, `upvotes_count`, the four counters (so the
10-run floor applies again), `published_at`, and `quiz_user_stats`. The copy belongs to the
original's creator — duplicating is not a transfer.

**The title is copied unchanged**, which is what the spec asks for; it does not mention renaming.
Two rows with the same name are told apart by their status in the Studio list. If that turns out to
be annoying in use, appending " (copy)" is a one-line change in `DuplicateQuiz` — but it would need
truncation, since title caps at 80.

## Phase 4 gate

```powershell
docker compose exec app php artisan test --filter=Studio
```

Covered: every publish-validation branch, both publish/republish visibility paths, `published_at`
stability, each unpublish effect, lazy run closure for users and guests, the creator's own draft run
surviving, duplicate's copy/not-copy split, draft-only delete, and immutability at the model layer
including what must stay writable.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Exceptions/ImmutableQuizException.php` | R4.2, R4.6 |
| `app/Actions/Quiz/CheckPublishable.php` | R4.1 |
| `app/Actions/Quiz/PublishQuiz.php` | R4.3 |
| `app/Actions/Quiz/UnpublishQuiz.php` | R4.4 |
| `app/Actions/Quiz/DuplicateQuiz.php` | R4.5 |
| `app/Http/Requests/Studio/PublishQuizRequest.php` | R4.1, R4.3 |
| `app/Http/Controllers/Studio/QuizLifecycleController.php` | R4.1–R4.6 |
| `app/Http/Middleware/CloseStaleRuns.php` | R4.4 |
| `app/Models/Quiz.php` (edited) | R4.2, R4.6 |
| `app/Models/Question.php` (edited) | R4.2 |
| `app/Http/Controllers/Studio/QuizController.php` (edited) | action flags + blockers |
| `bootstrap/app.php` (edited) | registers `CloseStaleRuns` |
| `routes/studio.php` (edited) | the four lifecycle routes |
| `resources/js/pages/studio/Index.vue` (rewritten) | R4.3 dialog + actions |
| `tests/Feature/Studio/PublishQuizTest.php` | R4.1, R4.3 |
| `tests/Feature/Studio/QuizLifecycleTest.php` | R4.4, R4.5, R4.6 |
| `tests/Feature/Studio/ImmutabilityTest.php` | R4.2 |

## Likely first-run fixes

- **`$quiz->increment()` and model events.** Eloquent's `increment` fires `updating`, so the guard
  sees it — it passes because no protected attribute is dirty. If a Laravel version changes that
  and the counter tests fail, `incrementQuietly` is the escape hatch.
- **`PublishQuizRequest::after()` with a resolved dependency.** Laravel resolves `after()`
  parameters from the container; if that is not supported in this version, move the check into
  `withValidator()`.
- **Confirm dialogs** in `Index.vue` use the browser's `confirm()`. Fine for now, worth replacing
  with a real dialog in Phase 10's polish.
