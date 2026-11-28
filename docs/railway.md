# Deploying to Railway

Complete guide, written 2026-11-27. The repo root **is** `quiz-app`, so `railway.json` and the
Dockerfile sit where Railway looks — no root-directory setting is needed.

---

## 0. Where things stand

| | |
|---|---|
| Code | pushed to `zuhriddin-dz/quiz-platform`, `main`, in sync |
| Backend tests | 313 passed, 0 failed |
| Frontend build | clean (`npm run build`) |
| Prod image | built and smoke-tested locally; `GET /up` → 200 |
| Google OAuth | **not configured** — this is the blocker |

Without OAuth nobody can sign in, and signing in is what unlocks Creator Studio. No sign-in means no
quizzes, which means nothing to play. Do step 1 first.

### The prod image was verified before the first push

Built with `docker build --target prod` and smoke-tested, because that stage had never run:
all five stages build, `start-container.sh` caches config/routes/views, FrankenPHP 1.14.0 / PHP
8.4.28 binds `$PORT`, and `GET /up` returns 200.

Two bugs were fixed to get there, both of which would have failed the Railway build:

1. **`php artisan key:generate` ran before `composer dump-autoload`.** `composer install` uses
   `--no-autoloader`, so `vendor/autoload.php` did not exist yet and any artisan call died.
2. **`routes/settings.php` was an orphaned kit file with a closure route.** Nothing required it, so
   the tests passed — but `start-container.sh` runs `route:cache`, and closure routes cannot be
   cached. Had anything included that file, every boot would have failed.

Also confirmed: `/etc/caddy/Caddyfile` is the right path (FrankenPHP's own default is
`/etc/frankenphp/Caddyfile`; we pass `--config` explicitly), and the Caddyfile passes
`frankenphp validate`.

---

## 1. Google OAuth client

At [console.cloud.google.com](https://console.cloud.google.com):

1. **Create or pick a project.**
2. **APIs & Services → OAuth consent screen.** Choose **External**. Fill in the app name, your
   support email and developer email. Save.
   - Leave it in **Testing**. While testing, only accounts you list under **Test users** can sign
     in — add your own Google address there. Publishing is only needed for strangers.
   - **Scopes: add nothing.** The app needs `openid`, `email` and `profile`, which Socialite's
     Google driver requests by default.
3. **Credentials → Create Credentials → OAuth client ID → Web application.**
4. **Authorised redirect URIs** — add both:
   ```
   http://localhost:8000/auth/google/callback
   https://<your-railway-domain>/auth/google/callback
   ```
   You will not know the Railway domain until step 4, so add localhost now and come back.
5. Copy the **Client ID** and **Client secret**.

Google matches redirect URIs **exactly** — scheme, host, path, no trailing slash. A mismatch gives
`Error 400: redirect_uri_mismatch`, and the error page shows the URI it received, which is the
fastest way to spot the difference.

Use **one client with two URIs**, or two separate clients if you prefer to keep local and production
credentials apart. The app reads whichever pair is in its environment.

---

## 2. Railway project and Postgres

1. **New Project** (or open the one you made).
2. **New → Database → PostgreSQL.** Nothing to configure.

> **Redis is not used.** Sessions and cache are both on `database`, and the spec has no background
> jobs (§2), so there is no queue worker either. A Redis service will sit idle — delete it unless you
> want it for something else. Moving the cache to Redis later needs `predis/predis` in
> `composer.json`, since the Dockerfile does not install the phpredis extension.

---

## 3. The app service

**New → GitHub Repo → `zuhriddin-dz/quiz-platform`.**

`railway.json` already declares everything:

```json
"build":  { "builder": "DOCKERFILE", "dockerfilePath": "docker/app/Dockerfile" }
"deploy": { "preDeployCommand": "sh -c \"php artisan migrate --force\"",
            "healthcheckPath": "/up",
            "restartPolicyType": "ON_FAILURE",
            "numReplicas": 1,
            "sleepApplication": false }
```

The Dockerfile's **last** stage is `prod`, which is what Railway builds.

**`sleepApplication: false` matters.** Scale-to-zero would reintroduce cold starts, and strict mode's
1.5-second `grace` exists to absorb *network latency only*. A cold start would eat the whole budget
and fail honest players mid-question. Do not turn sleeping on.

Put the app and Postgres in the **same region** (service → Settings → Region).

---

## 4. Environment variables

On the **app** service → Variables. Generate the key first:

```powershell
docker compose exec app php artisan key:generate --show
```

| Variable | Value |
|---|---|
| `APP_KEY` | the `base64:…` string from above |
| `PORT` | `8080` — must match the port the custom domain routes to |
| `APP_NAME` | `Quiz` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<your-domain>` — fill in after step 5 |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `info` |
| `GOOGLE_CLIENT_ID` | from step 1 |
| `GOOGLE_CLIENT_SECRET` | from step 1 |
| `GOOGLE_REDIRECT_URI` | `https://<your-domain>/auth/google/callback` |

Four of these are load-bearing in ways that are not obvious:

- **`APP_KEY`** — the build stage generates a throwaway key purely so `wayfinder:generate` can boot,
  then deletes it. Without a real key every request 500s on the first encrypted cookie, and the guest
  token *is* an encrypted cookie, so this breaks on the very first page.
- **`DB_URL` must be the reference `${{Postgres.DATABASE_URL}}`**, not a pasted URL. The reference
  resolves to the private network; a pasted public URL sends every query out over the internet and
  bills egress.
- **`LOG_CHANNEL=stderr`** — the default `stack` writes `storage/logs/laravel.log`, a file inside an
  ephemeral container that Railway's log viewer cannot see. With `stderr` the logs appear in the
  deployment view, which is the only way to debug a failed boot.
- **`SESSION_DRIVER=database`, never `file`** — file sessions live in the container filesystem and
  are wiped on every redeploy, logging everyone out, and they do not work at all across replicas.

**`TRUSTED_PROXIES` is deliberately absent.** `bootstrap/app.php` calls `trustProxies(at: '*')`
directly, because reading it from env would be blanked by the `config:cache` that
`start-container.sh` runs on every boot. Railway terminates TLS at its edge, so without trusted
proxies Laravel would generate `http://` URLs and both the OAuth redirect and secure cookies would
break.

`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` are **not** needed — `DB_URL`
supersedes them.

---

## 5. Domain

Target: **`quiz-app.esignsoft.uz`**, a subdomain of the domain you already own.

A custom domain is worth the extra step here. Railway's generated domain lives under
`up.railway.app`, which is on the public suffix list, and Google requires a redirect URI's domain to
be a valid *top private domain* — so the generated one can be rejected. Your own domain has no such
ambiguity, and the URL is one you can keep.

### 5a. Add it in Railway

Service → **Settings → Networking → Custom Domain** → `quiz-app.esignsoft.uz`.

Railway also asks **"Enter the port your app is listening on"** — answer **8080**, and set a
`PORT=8080` variable to match.

This pairing matters. The Caddyfile binds `:{$PORT:8080}`, so the app listens on whatever `PORT`
says and falls back to 8080. If Railway injects a different `PORT` than the port the domain routes
to, the domain reaches nothing and the healthcheck fails with no obvious cause. Setting both to 8080
removes the guesswork.

Railway responds with a CNAME target, something like `abc123.up.railway.app`.

### 5b. Point DNS

At whoever hosts DNS for `esignsoft.uz`, add:

| Type | Name | Value | TTL |
|---|---|---|---|
| CNAME | `quiz-app` | the target Railway gave you | lowest offered |

A CNAME works because this is a subdomain. (An apex domain would need ALIAS/ANAME instead, which not
every provider supports — another reason to use a subdomain.)

Railway provisions a Let's Encrypt certificate automatically once the record resolves. Propagation
is usually minutes; the dashboard shows the domain as unverified until it completes.

Check it from your machine:

```powershell
nslookup quiz-app.esignsoft.uz
```

### 5c. Then update three things

1. `APP_URL` = `https://quiz-app.esignsoft.uz`
2. `GOOGLE_REDIRECT_URI` = `https://quiz-app.esignsoft.uz/auth/google/callback`
3. In the Google OAuth client, add that same URI under **Authorised redirect URIs**

Redeploy after 1 and 2 — `APP_URL` is baked into the cached config at boot, so it must be correct
*before* the deploy you intend to use.

### If Google asks you to verify the domain

Adding a custom domain may require `esignsoft.uz` under **OAuth consent screen → Authorised
domains**, and Google may ask you to prove ownership through Search Console — normally a DNS TXT
record. You own the domain, so this is a formality, but it is a DNS round trip, so start it early if
it comes up.

### Keeping localhost working

Leave `http://localhost:8000/auth/google/callback` in the client's URI list. One client with both
URIs lets the same credentials serve local development and production; the app uses whichever
`GOOGLE_REDIRECT_URI` is in its environment.

---

## 6. What the first deploy does

| Stage | What should happen |
|---|---|
| Build | five Docker stages; the `build` stage runs `composer install --no-dev`, `npm ci`, `composer dump-autoload`, `key:generate`, `npm run build`, then deletes `node_modules` and the throwaway `.env` |
| Pre-deploy | `php artisan migrate --force` lists **nine** migrations |
| Boot | `start-container.sh` runs `config:cache`, `route:cache`, `view:cache`, then `exec frankenphp run` |
| Healthcheck | `GET /up` → 200 |

**Check the pre-deploy log explicitly.** There are community reports that pre-deploy containers do
not always resolve service reference variables, so `migrate` can run against no database and still
exit 0. Confirm it actually listed the nine migrations. If it did not, remove `preDeployCommand`
from `railway.json` and run migrations once from the service shell instead.

The build takes a while — the `base` stage compiles PHP extensions and the `toolchain` stage installs
Node. Railway caches layers between deploys, so later ones are much faster.

---

## 7. Verify it

In order, because each step depends on the last:

1. **`https://<domain>/up`** → 200. Proves the container boots and PHP runs.
2. **`https://<domain>/`** → Explore, with the empty state "No published quizzes yet". Proves the
   database is connected, migrations ran, and the Vite manifest was built.
3. **Sign in with Google.** Proves OAuth, the redirect URI, trusted proxies and session storage.
4. **Creator Studio → New quiz → five questions → Publish → Listed.** Proves the authoring and
   lifecycle paths, and gives Explore something to show.
5. **Play it.** Proves the run engine, and is the first time strict-mode timing runs over a real
   network.

> **`DatabaseSeeder` refuses to run** when `APP_ENV=production` — by design, R2.6. Create the first
> quiz through the UI.

Then walk [QA.md](../QA.md) against the real domain. Three sections matter most here because no
automated test covers them:

- **Section 4, focus loss** — eight cases, including the critical negative one (clicking devtools
  must *not* fail a run).
- **Sections 1–2, WASD versus touch** — test on a real phone, not a device emulator; emulators can
  report `pointer: fine` and show the wrong layout.
- **Section 5, timing** — `grace` is 1.5s and was tuned against localhost's near-zero latency. Real
  latency to Railway is not zero. If honest answers start failing as timeouts, raise
  `QUIZ_GRACE_SECONDS`.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Every request 500s, logs mention `MissingAppKeyException` | `APP_KEY` unset | set it (step 4) |
| Healthcheck fails, deploy rolls back | usually `APP_KEY`, or the app cannot reach Postgres | read the deploy log; check `DB_URL` is the reference form |
| `Error 400: redirect_uri_mismatch` | the URI in Google does not match exactly | compare against the URI shown on Google's error page |
| Signing in loops back to `/` | `APP_URL` wrong, or set after the deploy | fix `APP_URL`, redeploy |
| OAuth redirects to `http://` | trusted proxies not applied | should not happen — `bootstrap/app.php` hardcodes it; check it was not edited |
| Deploy log empty when a boot fails | `LOG_CHANNEL` still `stack` | set `stderr` |
| `Vite manifest not found` | the build stage did not produce `public/build` | check the build log for the `npm run build` step |
| Answers return 419 | the `csrf-token` meta tag is missing from `app.blade.php` | restore it; the play loop posts with `fetch()` and reads it |
| No tables / `relation does not exist` | pre-deploy migration did not actually run | see step 6 |
| Timeouts on legitimate answers | `grace` too tight for real latency | raise `QUIZ_GRACE_SECONDS` |
| Everyone behind one office IP gets 429 | should not happen — fixed by `GuestToken::fromRequest`'s cookie fallback | check that fallback is intact |

Useful knobs, all optional (`config/quiz.php`):

| Variable | Default | What it does |
|---|---|---|
| `QUIZ_GRACE_SECONDS` | `1.5` | strict-mode latency allowance |
| `QUIZ_LIMIT_START_RUN` | `30` | run starts per minute, per player |
| `QUIZ_LIMIT_VOTE` | `30` | votes per minute, per user |
| `QUIZ_SUGGEST_W_AUTHOR` / `_W_STATS` / `_W_VOTES` | `3` / `2` / `1` | suggestion score weights |

---

## 9. Deliberately not configured

Worth knowing before this carries anything you care about:

- **No database backups.** Railway's Postgres has no automatic backup on the lower plans. Published
  quizzes can never be deleted by design, so the data is append-mostly and worth protecting.
- **No error tracking.** A 500 appears in the deploy log and nowhere else.
- **No queue worker, no scheduler.** Nothing in the app needs one (§2), so neither is running.
- **No CDN for assets.** They are served by FrankenPHP out of the container.
- **No staging environment.** `main` deploys straight to the one service.
- **Consent screen in Testing mode** — only Google accounts listed as test users can sign in. That
  is the right setting for your own testing, and the thing to change when you want other people in.
