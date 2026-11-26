# Manual QA checklist

R10.5 — everything the Pest suite cannot reach. The spec rules out browser tests (§12), so this
list is the only coverage for client-side behaviour. **Walk it once end to end before each deploy.**

Run `php artisan test` first. If the suite is red, stop — this list assumes the server is correct.

---

## 1. Controls: desktop / WASD (R5.9)

On a laptop or desktop with a mouse, in a normal window:

- [ ] Options lay out as **W centred on top, A / S / D in a row beneath** — not a 2×2 grid.
- [ ] Each option shows its key hint (W, A, S, D).
- [ ] Pressing **W** answers the top option; **A**, **S**, **D** answer left to right beneath.
- [ ] Keys work in **upper and lower case** (caps lock on).
- [ ] **`cmd+W` / `ctrl+W` still closes the tab** — modified presses must not answer.
- [ ] **Enter** starts a run from the idle screen, and retries from a result screen.
- [ ] Enter does **nothing** during the countdown or a live question.
- [ ] No other keys answer: 1–4 do nothing, A–D labels are absent.

## 2. Controls: touch (R5.9)

On a real phone and a real tablet — not a desktop browser's device emulator, which can report
`pointer: fine`:

- [ ] Options lay out as a **2×2 grid**.
- [ ] **No key hints** are drawn.
- [ ] Tapping answers. No double-tap zoom on the option buttons.
- [ ] Hitting a target is comfortable one-handed.

### Device switching

- [ ] On a tablet with a detachable keyboard: attach it, reload — the layout switches to WASD.
      Detach, reload — back to the grid.

## 3. Countdown (shared rules)

- [ ] Press Start: a **3 → 2 → 1** countdown runs before the first question.
- [ ] Each number animates, and the animation restarts per number.
- [ ] With **Reduce Motion** on (OS setting), the numbers still change but do not leap.
- [ ] **Switching tabs during the countdown does NOT fail the run** (R5.7).
- [ ] The first question appears immediately when the countdown ends — no extra pause while the
      run starts.

## 4. Focus loss (R5.7)

The run must fail with "Tab left" **only** while a question is showing and its timer is running.

- [ ] Question showing → switch tab → run fails, "Tab left".
- [ ] Question showing → minimise the window → run fails.
- [ ] Question showing → switch to another **app** → run fails.
- [ ] **Clicking devtools, the URL bar, or another window WITHOUT hiding the page does NOT fail**
      the run. (This is the one most likely to regress: the guard listens to `visibilitychange`,
      not `blur`.)
- [ ] During **"checking…"** (strict, mid-round-trip) → switch tab → the run does **not** fail from
      focus; the verdict arrives as normal.
- [ ] During the **countdown** → switch tab → no failure.
- [ ] On the **result screen** → switch tab → nothing happens.
- [ ] On the idle quiz page → switch tab → nothing happens.

## 5. Timer (R5.8)

- [ ] The displayed timer counts down from the quiz's seconds-per-question.
- [ ] **Strict:** answering shows "checking…" and the bar **freezes** — it does not keep draining
      during the round trip.
- [ ] **Strict:** the next question's timer starts from full.
- [ ] **Casual:** there is **no "checking…" state** at all — the next question appears on the same
      frame as the click.
- [ ] Letting the timer run out fails the run with "Time's up".
- [ ] Throttle the network (devtools, Slow 3G) and answer near the end of the timer: it should be
      accepted, because `grace` absorbs the latency. Answering well past it should time out.
- [ ] Leave a question open in a **background tab** past the timer, come back: the run has failed
      (focus loss), not hung.

## 6. Result screens (R5.10)

- [ ] **Wrong answer:** the option you picked is highlighted **red**; the correct one is **not**
      revealed anywhere — check the DOM and the network response too.
- [ ] **Timeout:** "Time's up" banner, no option highlighted.
- [ ] **Focus loss:** "Tab left" banner.
- [ ] All three show "Reached X/Y".
- [ ] Signed in: "Attempt #N — Best: X/Y" appears, and the numbers are right across several runs.
- [ ] Signed out: that line is **absent** (guests have no stats row).
- [ ] **Retry** starts a new run — and the question order and option order are **different** from
      the previous run (play twice and compare).

## 7. Confetti (R5.10)

- [ ] Passing a quiz shows confetti.
- [ ] It stops after a few seconds and leaves nothing on screen.
- [ ] It does not block clicks on the Retry button underneath.
- [ ] With **Reduce Motion** on, no confetti — the "Perfect!" message still appears.

## 8. Sound (R10.2, R10.3)

With real files in `public/sounds/`:

- [ ] The in-game loop starts at the countdown and loops.
- [ ] Failing plays the fail sound and **stops the loop**.
- [ ] Passing plays the win sound and stops the loop.
- [ ] Nothing plays on page load (browsers block it, and we do not try).
- [ ] The mute toggle in the top bar silences everything **immediately**, mid-run included.
- [ ] Mute **persists across a reload** and across a new tab.
- [ ] In Safari **private browsing** (where `localStorage.setItem` throws), the toggle still works
      for the session and nothing crashes.
- [ ] With the sound files **missing**, play is silent and nothing errors in the console.

## 9. Casual mode specifics (R6.2)

- [ ] Clicks resolve instantly — no perceptible pause, no spinner.
- [ ] The countdown covers the hashing: the first question is ready when it ends.
- [ ] Over plain `http://` on a LAN address (not localhost), casual play degrades visibly rather
      than silently breaking — `crypto.subtle` is unavailable outside a secure context.

## 10. Empty, loading and error states (R10.4)

- [ ] **Explore, no quizzes:** empty state with a "Create a quiz" action.
- [ ] **Explore, filters match nothing:** different empty state with "Clear filters", and clearing
      works.
- [ ] **My plays, nothing played:** empty state with "Find a quiz".
- [ ] **Studio, no quizzes:** empty state with "Create your first quiz".
- [ ] Changing an Explore filter shows the thin loading bar at the top.
- [ ] Opening someone else's draft → the **403 page inside the app shell**, with a way back.
- [ ] A mistyped `/q/{ulid}` → the 404 page.
- [ ] Starting runs very fast → the 429 page ("Slow down a moment").
- [ ] The play loop's own failures still behave: kill the network mid-answer and the run stops with
      "Lost connection mid-answer", not an error page.

## 11. Layout and navigation (R7.7)

- [ ] Top bar: logo and search on the left; Creator Studio, mute and avatar menu on the right.
- [ ] Guest: "Sign in with Google" and a greyed Creator Studio that prompts sign-in.
- [ ] Sidebar, signed in: the 5 most recent plays, newest first, with "Show all".
- [ ] Sidebar, guest: the "Sign in to track your progress" card.
- [ ] An unpublished quiz in the sidebar is shown as unavailable and is not a link.
- [ ] The sidebar is hidden below ~1024px, and the avatar menu still reaches My plays.
- [ ] Search from any page lands on Explore with the term applied, and the box keeps it.

## 12. Lifecycle, by hand (Phase 4)

- [ ] Publish dialog: **neither** Listed nor Unlisted preselected; Publish disabled until a choice.
- [ ] Publishing with fewer than 5 complete questions: the button is disabled and the reasons are
      listed.
- [ ] Unlisted quiz: absent from Explore, reachable and playable by its link.
- [ ] Unpublish, then open the link as another user: "This quiz is no longer available".
- [ ] That quiz in the other user's My plays: "Unavailable", numbers intact, no "Play again".
- [ ] Creator's stats for it: still fully available.
- [ ] Duplicate a published quiz: lands in the editor, no plays, votes or analytics on the copy.
- [ ] Delete is offered on drafts only.

## 13. Voting (R8.1)

- [ ] Signed in, someone else's listed quiz: the button toggles, and the count moves by one.
- [ ] The pressed state survives a reload.
- [ ] Your own quiz: no button, and a line saying why.
- [ ] Guest: the button is a sign-in prompt.
- [ ] Unlisted quiz: no vote button.

---

## Before deploying

- [ ] `php artisan test` green.
- [ ] `npm run build` succeeds.
- [ ] Section 4 (focus loss) walked in full — it is the rule most easily broken by an unrelated
      change, and no test covers it.
- [ ] Sections 1 and 2 walked on one real desktop and one real phone.
