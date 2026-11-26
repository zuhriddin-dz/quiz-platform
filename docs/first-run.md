# First run — what the scaffold actually gave us, and the fix

Written 2026-11-26, after the first real scaffold attempt. This supersedes the setup section of
`docker.md` for anyone starting from a merged-but-unbuilt tree.

## What the kit turned out to be

`composer create-project laravel/vue-starter-kit` installs **v1.0.2**, which is the only tagged
version and is a generation behind the spec on every axis that matters:

| | Kit v1.0.2 | Spec / this codebase | Verified latest |
|---|---|---|---|
| Laravel | **12.74** | 13 | `laravel/framework` 13.44.1 |
| Inertia (PHP) | 2.0.28 | 3 | `inertiajs/inertia-laravel` 3.8.0 |
| Inertia (JS) | **2.0.0-beta.3** | 3 | `@inertiajs/vue3` 3.11.0 |
| Tailwind | **3.4** | 4 | `tailwindcss` 4.3.5 |
| Typed routes | **Ziggy** | Wayfinder | `laravel/wayfinder` 0.1.23 |
| Tests | **PHPUnit only** | Pest | `pestphp/pest` 5.3.7 |
| Socialite | absent | required | 5.33.0 |

The Inertia JS package being a **beta** settled it: §2 of the spec forbids pre-releases, and §2 also
says outright "If the kit ships an older Inertia major, upgrade it to v3." So the kit's
*scaffolding* is kept — `app.blade.php`, `app.ts`, `tsconfig`, the component library — and its
*dependency versions* are replaced.

## What was changed to make that work

| File | Change |
|---|---|
| `composer.json` | rewritten: PHP ^8.4, Laravel ^13, Inertia ^3.8, Socialite ^5.33, Wayfinder pinned at 0.1.23, Pest ^5.3 + pest-plugin-laravel ^5.0. Dropped `tightenco/ziggy` and the explicit PHPUnit pin (Pest brings its own). |
| `package.json` | rewritten: `@inertiajs/vue3` ^3.11, `tailwindcss` ^4.3 + `@tailwindcss/vite`, `laravel-vite-plugin` ^3.2, `@laravel/vite-plugin-wayfinder` ^0.1.11. Dropped `ziggy-js`, `@types/ziggy-js`, `autoprefixer`, `tailwindcss-animate`, `@tailwindcss/forms` — all Tailwind-3-era. The shadcn-vue deps (radix-vue, clsx, tailwind-merge, cva, lucide) are **kept**, so any leftover kit component still compiles. |
| `resources/views/app.blade.php` | added the **`csrf-token` meta tag** — without it every `fetch()` answer returns 419 and a run dies mid-question. Removed `@routes` (a Ziggy directive). |
| `resources/js/app.ts` | removed the `ZiggyVue` import and `.use(ZiggyVue)`. |
| `resources/css/app.css` | `@tailwind base/components/utilities` → `@import "tailwindcss";` (Tailwind 4 is one import). |
| `docker-compose.yml` | `COMPOSER_PROCESS_TIMEOUT: 0` on the `app` service. |
| `.env.example` | `FORWARD_DB_PORT=5433`. |

## The three environment traps

**1. Composer's 300-second process timeout.** Unzipping `laravel/framework` onto a Windows bind
mount takes longer than that, so the install dies at ~113/114 packages and leaves a `vendor/` with
no `autoload.php` — which then looks like a missing-dependency error rather than a timeout. Fixed
with `COMPOSER_PROCESS_TIMEOUT: 0`.

**2. Port 5432 was already taken** by another Postgres. Only the host-side forward clashes; nothing
inside the compose network cares. Default moved to 5433.

**3. `robocopy` and `Remove-Item` are PowerShell, not bash.** Running the setup half in WSL and
half in PowerShell is what made the first attempt confusing. **Pick one shell and stay in it** —
PowerShell is the one `docker.md` is written for.

## Two resolution failures, and their fixes

**`laravel/tinker` capped the whole PHP side at Laravel 12.** The kit pinned `^2.10`, and tinker 2.x
only declares `illuminate/support` up to `^12.0`. Composer's error blamed `laravel/framework`, which
is misleading — tinker was the constraint. **`laravel/tinker ^3.2`** (v3.2.0 declares `^12.0|^13.0`)
fixes it. A dry-run then resolved the whole set: Laravel 13.44.1, Inertia 3.8.0, Socialite 5.33.0,
Tinker 3.2.0, Wayfinder 0.1.23, Pest 5.3.7, pest-plugin-laravel 5.0.1.

**`laravel-vite-plugin@3.2.1` requires Vite 8 as a peer**, and the kit pinned Vite 6. Bumped to
`vite ^8.3.8`, `@vitejs/plugin-vue ^6.0.11` (peers `^5|^6|^7|^8`), `vue-tsc ^3.3.14`, and
`@types/node ^22.12.0` (Vite 8's engine floor). `@tailwindcss/vite` already accepts Vite 8, and the
Wayfinder plugin declares no peers.

## The Ziggy cleanup that is not optional

`resources/js/app.ts` resolves pages with `import.meta.glob('./pages/**/*.vue')` — a **glob**, so
every file under `pages/` is bundled whether or not anything links to it. The kit's leftover pages
(`Dashboard.vue`, `Welcome.vue`, `settings/*`) still call Ziggy's `route()`, and Ziggy is gone, so
the Vite build fails until they are deleted — along with the layouts and components they pull in.

Deleted: `pages/{Dashboard,Welcome,Home}.vue`, `pages/settings/`, `layouts/{AuthLayout.vue,app,auth,settings}`,
`types/{index,ziggy}.ts`, and the twelve `components/*` that import `@/types` or Ziggy.
`components/ui/` (shadcn primitives) is **kept** — nothing imports it, so it is never bundled.

`pages/Home.vue` went too: it was the Phase 1 placeholder, dead since Explore took over `/`.

## What the first real test run found

The suite went from ~40 failures to **313 passed / 0 failed**. Worth recording what was actually
broken, because the ratio is the useful part: **two production bugs against about a dozen errors in
my own test scaffolding.**

### Production bugs (2)

**1. Every guest shared one rate-limit bucket.** `ThrottleRequests` sits in Laravel's default
`$middlewarePriority` list, which hoists it ahead of anything appended to the web group — so it
resolved its key *before* `EnsureGuestToken` set the request attribute, and
`GuestToken::fromRequest()` returned null. All guests keyed to `run:guest:no-token|<ip>`, meaning one
person on a school or office connection could throttle everyone behind that IP. Exactly what the
token+IP key exists to prevent. Fixed by making `fromRequest()` fall back to reading the cookie
directly, which is safe because `EncryptCookies` is higher in the same priority list.

**2. Guests got a 500 instead of a sign-in prompt.** R1.2 deleted the kit's `login` route, but
Laravel's `auth` middleware redirects unauthenticated users to `route('login')` — so `/studio`,
`/plays` and `POST /vote` threw `RouteNotFoundException`. Fixed with
`redirectGuestsTo(route('auth.google.redirect'))`, which is also what R7.8 asks for.

Two smaller payload fixes: `correct_rate` and `pass_rate` are now cast to float (PHP's `/` returns
an **int** when the division is exact, so the JSON type changed with the data), and `passed` is cast
to bool (`firstOrNew` leaves the attribute unset on a row that never took the pass branch, so a
failing signed-in player got `null`).

### My test scaffolding (the rest)

| What | Why it broke |
|---|---|
| no Vite manifest | `app.blade.php` calls `@vite`, which throws without `public/build/manifest.json`. Fixed with `withoutVite()` in `tests/Pest.php` — the suite should not need built assets. |
| `withUnencryptedCookie` | the app encrypts the guest cookie, so the helper must too. 14 sites. |
| `getCookie($name, false)` | returns the *encrypted* value; the ULID check was validating ciphertext. |
| missing `withCredentials()` | `prepareCookiesForJsonRequest()` returns `[]` without it — **JSON test requests send no cookies at all.** 14 sites. |
| `Quiz::factory()->for($user)` | Laravel guesses the relation from the model class (`User` → `user()`); this model calls it `creator()`. ~80 sites, fixed once by overriding `for()` in the factory. |
| factory published before filling | `has()` children are created before `afterCreating`, so `published()->withQuestions()` added questions to an already-published quiz and the immutability guard refused. Status now flips in `afterCreating`. |
| `faker->unique()->words(4)` | `unique()` applies to the returned **array**, not the words in it, so a question could get duplicate options and fail publish validation. `withQuestions(15)` was invalid about half the time. |
| `??` swallowing explicit null | `$attributes['name'] ?? 'Amina Yusuf'` restored the default when a test passed `null`, so the name-fallback test fed its own default back in. |
| `latest('started_at')` | second-precision column, so rows created in the same second returned arbitrarily — six shuffled runs looked identical. Made the same mistake twice, in a test and in a debug probe. |
| `actingAs()` persistence | it applies for the rest of the test, so a "guest" request after one was still authenticated and legitimately allowed. |

Two things the guards got right, and should be left alone: `CheckPublishable` rejected genuinely
invalid quizzes that my factory produced, and `Question`'s immutability guard caught a real ordering
mistake the first time it ever executed.

## Still to do by hand

`docs/phase-1.md` lists the kit's auth scaffolding to delete (R1.2). It is all present and must go,
or `RemovedAuthRoutesTest` fails by design:

- `app/Http/Controllers/Auth/` — everything except `GoogleController.php`
- `resources/js/pages/auth/` — all six Vue pages
- the kit's `tests/Feature/Auth/*`
- `tailwind.config.js` — Tailwind 4 configures itself from CSS
- the kit's `create_users_table` migration, if the merge left a second one
