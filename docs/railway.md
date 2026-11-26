# Deploying to Railway from GitHub

The repo root **is** `quiz-app`, so `railway.json` and the Dockerfile sit where Railway expects
them — no root-directory setting needed.

## Redis is not used

Nothing in this app touches Redis. Sessions and cache are both on `database`, and the spec has no
background jobs (§2), so there is no queue worker either. A Redis service will sit idle.

Switching the cache to Redis would be reasonable later — the rate limiter is the only hot cache user
— but it needs `predis/predis` in `composer.json`, since the Dockerfile does not install the
phpredis extension. Not worth doing before the app is proven.

## 1. Service

Railway → the project → **New → GitHub Repo → `zuhriddin-dz/quiz-platform`**.

`railway.json` already declares everything that matters:

```json
"build":  { "builder": "DOCKERFILE", "dockerfilePath": "docker/app/Dockerfile" }
"deploy": { "preDeployCommand": "sh -c \"php artisan migrate --force\"",
            "healthcheckPath": "/up", "sleepApplication": false }
```

The Dockerfile's **last** stage is `prod`, which is what Railway builds. `sleepApplication: false`
matters: scale-to-zero would reintroduce cold starts, and strict mode's 1.5s grace is meant to
absorb network latency only.

## 2. Variables

Set these on the **app** service. `DB_URL` must be the reference, not a pasted URL, so traffic stays
on the private network.

| Variable | Value |
|---|---|
| `APP_KEY` | generate it — see below |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_NAME` | `Quiz` |
| `APP_URL` | `https://<your-domain>` — fill in after step 3 |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | **`stderr`** |
| `GOOGLE_CLIENT_ID` | from Google Cloud |
| `GOOGLE_CLIENT_SECRET` | from Google Cloud |
| `GOOGLE_REDIRECT_URI` | `https://<your-domain>/auth/google/callback` |

**`LOG_CHANNEL=stderr` is not optional.** The default `stack` writes to
`storage/logs/laravel.log` — a file inside an ephemeral container that Railway's log viewer cannot
see. With `stderr` the logs appear in the deployment view, which is the only way to debug a failed
boot.

**`APP_KEY` is not optional either.** The build stage generates a throwaway key purely so
`wayfinder:generate` can boot, then deletes it. Without a real key at runtime every request 500s on
the first encrypted cookie. Generate one and paste it into Railway:

```powershell
docker compose exec app php artisan key:generate --show
```

`TRUSTED_PROXIES` is **not** needed: `bootstrap/app.php` calls `trustProxies(at: '*')` directly,
because reading it from env would break under `config:cache`, which `start-container.sh` runs on
every boot.

## 3. Domain, then fix two variables

Settings → Networking → **Generate Domain**. Then go back and set `APP_URL` and
`GOOGLE_REDIRECT_URI` to that domain, and add the same callback URL to the Google Cloud OAuth
client's authorised redirect URIs. Redeploy.

Google is strict about exact matches — scheme, host and path, no trailing slash.

## The prod image is verified locally (2026-11-26)

Built with `docker build --target prod` and smoke-tested before the first push, because none of this
stage had ever run:

- all five stages build
- `start-container.sh` caches config, routes and views without error
- FrankenPHP 1.14.0 / PHP 8.4.28 starts and binds `$PORT`
- `GET /up` returns **200**

Two bugs were fixed to get there, both of which would have failed the Railway build:

1. **`php artisan key:generate` ran before `composer dump-autoload`.** `composer install` used
   `--no-autoloader`, so `vendor/autoload.php` did not exist yet and any artisan call died. Reordered.
2. **`routes/settings.php` was an orphaned kit file with a closure route.** Nothing required it, so
   tests passed — but `start-container.sh` runs `route:cache`, and closure routes cannot be cached.
   Had anything ever included that file, every production boot would have failed. Deleted.

Also confirmed: `/etc/caddy/Caddyfile` is the right place (FrankenPHP's own default lives at
`/etc/frankenphp/Caddyfile`, and we pass `--config` explicitly), and the Caddyfile passes
`frankenphp validate`.

## 4. What to watch in the first deploy

| Stage | What should happen | If it does not |
|---|---|---|
| Build | five stages; `npm run build` emits `public/build` | the build log names the failing `RUN` |
| Pre-deploy | `php artisan migrate --force` lists nine migrations | see the known issue below |
| Boot | `start-container.sh` caches config/routes/views, then FrankenPHP binds `$PORT` | a Caddyfile-path problem shows as the server starting with a default config |
| Healthcheck | `/up` returns 200 | usually a missing `APP_KEY` |

**Known issue with the pre-deploy command:** there are community reports that pre-deploy containers
do not always resolve service reference variables, so `migrate` can run against no database or
against the wrong one. Check the pre-deploy log explicitly and confirm it listed the migrations. If
it did not, run them once from the service shell instead and drop `preDeployCommand` from
`railway.json`.

## 5. After it is up

Seed data to have something to look at — safe, because `DatabaseSeeder` refuses `production`:

> It throws on `APP_ENV=production`. To seed the live environment deliberately you have to remove
> that guard, which is the point: the seeder exists for local development.

So on Railway, create a quiz through the UI instead. Sign in with Google, open Creator Studio, and
publish a five-question draft.

Then walk `QA.md` against the real domain — especially section 4 (focus loss) and sections 1–2
(WASD versus touch), which no automated test covers. Strict-mode timing is worth re-checking here
too: `grace` is 1.5s and real network latency to Railway is no longer the ~0ms of localhost.
