# Phase 3 — Creator Studio: authoring

Written code, nothing run.

## Two deviations from PLAN.md, both deliberate

**1. There is no `QuestionController`.** My plan named one, but the spec's route table (§10) has no
question endpoints — only `GET/PUT /studio/quizzes/{quiz}`. So the editor is a single form and one
PUT carries the quiz fields *and* the whole question list. `SyncQuestions` reconciles it:

| Payload row | Result |
|---|---|
| has an `id` that belongs to this quiz | updated |
| has no `id` | created |
| has an `id` belonging to someone else | treated as new on *this* quiz, never reaches theirs |
| stored row absent from the payload | deleted |

This matches the route table exactly and is simpler than four more endpoints. It also means
"question CRUD" is client-side until Save, which is what an editor wants.

**2. Delete is not implemented here.** R3.1 lists it among the row's actions, but the
implementation belongs with the rest of the lifecycle (R4.6). The policy ability exists now; the
route and the button arrive in Phase 4. Nothing in `Index.vue` links to a route that does not exist.

## The R3.4 judgment call

R3.4 says a draft validates "length limits only". Read literally that would let a draft have no
mode and no timer — but both columns are `NOT NULL`, and R3.2 lists them as draft fields. So:

- **Quiz fields** are validated with their limits *and* ranges: title required ≤80, description
  nullable ≤500, mode in the enum, timer 2–10.
- **Questions** are validated for length only. Every field is nullable: a blank prompt saves, two
  options save, no correct answer saves.

`title` is `required` because this is the only place it is ever checked — publish validation (R4.1)
does not re-check it. Flagging that as an interpretation, not something the spec states.

The editor shows what publishing will still need (`publishBlockers`), but Save always works. That
advisory check mirrors `Question::isComplete()` on the server; the server copy is the one that counts.

## Authorization (R3.5)

`QuizPolicy` is auto-discovered (`App\Models\Quiz` → `App\Policies\QuizPolicy`), so nothing is
registered in a provider. It reads its lifecycle rules from `QuizStatus` rather than re-spelling
them, so this policy and Phase 4's guards cannot disagree.

| Ability | Rule | Used by |
|---|---|---|
| `view` | published → anyone incl. guests; otherwise creator only | Phase 3, 5, 7 |
| `create` | any signed-in user | Phase 3 |
| `update` | creator **and** still a draft | Phase 3 |
| `delete` | creator **and** still a draft | Phase 4 |
| `publish` | creator **and** not currently published | Phase 4 |
| `unpublish` | creator **and** currently published | Phase 4 |
| `duplicate` | creator **and** no longer a draft | Phase 4 |
| `viewStats` | creator, whatever the status | Phase 9 |

Two failures that look alike but are not: a stranger gets 403 because it is not theirs; the creator
of a *published* quiz also gets 403, because published quizzes are immutable permanently — not
locked, not pending, permanently. Both are covered by test.

## Phase 3 gate

```powershell
docker compose exec app php artisan test --filter=Studio
```

Covered: draft CRUD round-trips, a second user gets 403 on every studio route, an incomplete draft
saves, over-length fields fail, positions are rewritten on save, and a foreign question id cannot be
used to reach another creator's quiz.

## Files written in this phase

| File | Serves |
|---|---|
| `app/Policies/QuizPolicy.php` | R3.5 (+ Phase 4/9 abilities) |
| `app/Http/Requests/Studio/StoreQuizRequest.php` | R3.2, R3.4 |
| `app/Http/Requests/Studio/UpdateQuizRequest.php` | R3.2, R3.3, R3.4 |
| `app/Actions/Quiz/SyncQuestions.php` | R3.3 |
| `app/Http/Controllers/Studio/QuizController.php` | R3.1, R3.2, R3.3 |
| `routes/studio.php` | R3.5 |
| `routes/web.php` (edited) | includes the studio group |
| `resources/js/pages/studio/Index.vue` | R3.1 |
| `resources/js/pages/studio/Create.vue` | R3.2 |
| `resources/js/pages/studio/Edit.vue` | R3.2, R3.3 |
| `tests/Feature/Studio/QuizDraftTest.php` | R3.1, R3.2, R3.4 |
| `tests/Feature/Studio/QuestionEditingTest.php` | R3.3, R3.4 |
| `tests/Feature/Studio/StudioAuthorizationTest.php` | R3.5 |

## Likely first-run fixes

- **Route-model binding.** `routes/studio.php` uses `{quiz}`, and `Quiz::getRouteKeyName()` returns
  `ulid`, so binding resolves by ULID everywhere. If a studio URL 404s with a valid ULID, that
  method is the first thing to check.
- **`form.errors` indexing in `Edit.vue`.** Laravel returns nested validation keys as
  `questions.0.prompt`. The TypeScript cast there is a workaround for Inertia's `errors` type; it
  may need a small helper instead.
- **`Rule::enum()`** needs `Illuminate\Validation\Rule` — present, but it rejects the *value*, so
  the form must send `"strict"` / `"casual"`, not a label.
