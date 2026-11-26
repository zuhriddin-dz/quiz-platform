# Phase 1 — Google-only auth

Written code, nothing run. This file covers the parts of Phase 1 that are **deletions and dashboard
setup**, which cannot be committed as files.

## 1. Install Socialite

```powershell
docker compose exec app composer require laravel/socialite
```

## 2. Delete the starter kit's auth scaffolding (R1.2)

The kit ships password, registration, reset and verification flows. Removing the routes is not
enough — delete the controllers, requests and pages too, so nothing can be re-linked by accident.

**Routes** — `routes/auth.php` in this repo already replaces the kit's version. If the kit also
wrote `routes/settings.php` with a password-update route, delete that route.

**Controllers** — delete from `app/Http/Controllers/Auth/` everything except `GoogleController.php`:

- `AuthenticatedSessionController.php`
- `RegisteredUserController.php`
- `PasswordResetLinkController.php`
- `NewPasswordController.php`
- `ConfirmablePasswordController.php`
- `EmailVerificationNotificationController.php`
- `EmailVerificationPromptController.php`
- `VerifyEmailController.php`
- `PasswordController.php` (often under `Controllers/Settings/`)

**Requests** — delete `app/Http/Requests/Auth/LoginRequest.php`.

**Vue pages** — delete from `resources/js/pages/auth/`:

- `Login.vue`
- `Register.vue`
- `ForgotPassword.vue`
- `ResetPassword.vue`
- `ConfirmPassword.vue`
- `VerifyEmail.vue`

Also delete `resources/js/pages/settings/Password.vue` and drop its link from the settings nav.

**Tests** — delete the kit's `tests/Feature/Auth/*` files. `GoogleAuthTest.php`,
`RemovedAuthRoutesTest.php` and `GuestTokenTest.php` in this repo replace them.

**Migrations** — the kit's `create_users_table` migration is already replaced here (no `password`,
no `email_verified_at`, no `password_reset_tokens`). Delete the kit's copy if the scaffold-merge
left a second file with a different timestamp.

**Model** — `app/Models/User.php` here replaces the kit's: no `MustVerifyEmail`, no
`hashed` password cast.

### Check it

```powershell
docker compose exec app php artisan route:list
```

Only `/`, `/up`, the two Google routes and `logout` should appear.

## 3. Google Cloud setup

Two OAuth clients, because the redirect URI must match exactly:

| Environment | Authorised redirect URI |
|---|---|
| local | `http://localhost:8000/auth/google/callback` |
| Railway | `https://<your-app>.up.railway.app/auth/google/callback` |

Scopes: the default `openid email profile` is all this app needs. Fill `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` per environment.

## 4. What Phase 1 deliberately does not include

- **No login page.** With Google as the only provider there is nothing to put on one — the top bar
  links straight to `/auth/google/redirect`. The top bar itself is Phase 7 (R7.7).
- **No guest-history migration.** Spec §3: guest history is not carried over at signup. The
  guest cookie is left untouched on login on purpose, so runs already recorded against the token
  keep their owner.
- **Only the `users` table.** The remaining six tables are Phase 2. `users` had to come early
  because auth cannot be exercised without it.

## 5. Files written in this phase

| File | Serves |
|---|---|
| `app/Models/User.php` | R1.3, R1.4 |
| `app/Actions/Auth/UpsertGoogleUser.php` | R1.3 |
| `app/Http/Controllers/Auth/GoogleController.php` | R1.1 |
| `app/Http/Middleware/EnsureGuestToken.php` | R1.5 |
| `app/Http/Middleware/HandleInertiaRequests.php` | R1.4 |
| `app/Support/GuestToken.php` | R1.5 |
| `bootstrap/app.php` | middleware wiring, trusted proxies, `/up` |
| `config/services.php` | R1.1 |
| `routes/auth.php` | R1.1, R1.2 |
| `routes/web.php` | temporary home route |
| `database/migrations/0001_01_01_000000_create_users_table.php` | R1.1, R1.2 |
| `database/factories/UserFactory.php` | test support |
| `resources/js/pages/Home.vue` | temporary, replaced in Phase 7 |
| `tests/Pest.php` | RefreshDatabase on the Feature suite |
| `tests/Feature/Auth/GoogleAuthTest.php` | R1.1, R1.3, R1.4 |
| `tests/Feature/Auth/RemovedAuthRoutesTest.php` | R1.2 |
| `tests/Feature/GuestTokenTest.php` | R1.5 |
