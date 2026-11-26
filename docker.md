# Local development with Docker

Serves R0.5. Local dev only — production is Railway, see `PLAN.md` → Deployment.

Three services, no more (R0.1):

| Service | Image | Role |
|---|---|---|
| `db` | `postgres:17-alpine` | dev database, data in the `pgdata` volume |
| `app` | built from `docker/app/Dockerfile` (`dev` target) | php-fpm **and** Node |
| `webserver` | `nginx:1.27-alpine` | serves `public/`, proxies PHP to `app:9000` |

**Why PHP and Node share one container (R0.2):** Wayfinder's Vite plugin runs
`php artisan wayfinder:generate` to produce typed routes. That needs the PHP binary and the app's
`vendor/` in the same place the Vite dev server runs. A separate `vite` service cannot do it, so
there isn't one.

---

## First-time setup

The Laravel starter kit is installed by Composer, not committed by hand. The infrastructure files
in this repo were written first, so the scaffold has to be merged **without overwriting them**.

### 1. Scaffold the starter kit into a temporary folder

```powershell
docker run --rm -v "${PWD}:/out" -w /out composer:2 create-project laravel/vue-starter-kit _scaffold --no-interaction
```

If you have PHP and the Laravel installer on the host, `laravel new _scaffold --vue` does the same.

### 2. Merge it in, keeping the existing files

`/XC /XN /XO` makes robocopy copy only files that do **not** already exist, so
`docker-compose.yml`, `vite.config.ts`, `phpunit.xml`, `.env.example` and the `docker/` tree
survive untouched.

```powershell
robocopy _scaffold . /E /XC /XN /XO
```

```powershell
Remove-Item _scaffold -Recurse -Force
```

### 3. Check what the kit actually shipped (R0.3)

Open `composer.json` and `package.json` and confirm:

- `laravel/framework` is `^13.0`
- `@inertiajs/vue3` is `^3.0` — if the kit shipped Inertia 2, upgrade it
- `vue` is `^3.5` (3.6 is still a release candidate; the spec forbids pre-releases)
- `tailwindcss` is `^4.0`
- `laravel/wayfinder` is present and **pinned exactly** — it is still 0.x, so a minor can break
  generated route types

Add anything missing:

```powershell
docker compose run --rm app composer require laravel/socialite laravel/wayfinder
```

### 4. Build and start

```powershell
docker compose build
```

```powershell
docker compose up -d
```

The container entrypoint creates `.env` from `.env.example`, runs `composer install` and
`npm install` if they are missing, and generates `APP_KEY`.

### 5. Migrate

```powershell
docker compose exec app php artisan migrate
```

### 6. Run the Vite dev server

It has to run inside `app`, where PHP is:

```powershell
docker compose exec app npm run dev
```

Leave that running. The app is at **http://localhost:8000**, Vite at **http://localhost:5173**.

---

## Everyday commands

```powershell
docker compose exec app php artisan test
```

```powershell
docker compose exec app php artisan tinker
```

```powershell
docker compose exec app bash
```

```powershell
docker compose logs -f app webserver
```

```powershell
docker compose down
```

To wipe the database volume as well:

```powershell
docker compose down -v
```

---

## Tests (R0.4)

`phpunit.xml` pins tests to SQLite in-memory, so `php artisan test` never touches the Postgres
container and needs no database setup. The consequence is a standing constraint on every later
phase: **no Postgres-only column types or SQL functions** in migrations or queries. The suggestion
score in §8 of the spec is the one that will bite — `log10` and `abs` must work on SQLite, or that
term gets computed in PHP.

## Notes and known rough edges

- **File watching polls.** Windows bind mounts do not deliver inotify events into Linux containers,
  so `vite.config.ts` sets `usePolling: true`. It costs some CPU; it is the only thing that works.
- **Dev runs as root.** Deliberate, for Docker Desktop on Windows, where UID remapping buys nothing
  and causes bind-mount permission problems.
- **Bind mount covers everything,** including `vendor/` and `node_modules/`. Simple and predictable,
  but installs are slower than a named volume would be. Revisit only if it becomes annoying.
- **The FrankenPHP Caddyfile path** in the prod stage is `/etc/caddy/Caddyfile`. Some
  `dunglas/frankenphp` tags use `/etc/frankenphp/Caddyfile` instead — check on the first Railway
  build and move the `COPY` if the server starts with a default config.
- **`storage/` and `bootstrap/cache/`** must be writable. The dev container runs as root so this is
  moot locally; the prod stage chowns them to `www-data`.
