---
phase: 12-multi-user-activation
plan: 02
subsystem: auth
tags: [auth, schema, migrations, models, encrypted-cast]
status: complete
requires:
  - users table (Modules/Core, v1.0)
provides:
  - users.username / users.is_developer / users.force_password_change_at_next_login
  - user_recovery_codes table + UserRecoveryCode model
  - oauth_secrets table + OAuthSecret model (encrypted casts)
  - Modules/Auth bounded module
affects:
  - Modules/Core/Models/User.php
  - tests/Contracts/UserIdColumnArchTest.php
tech-stack:
  added:
    - Modules/Auth bounded module (laravel-module)
  patterns:
    - Laravel encrypted Eloquent cast (AES-256-CBC via APP_KEY)
    - SQLite BEFORE INSERT/UPDATE enum trigger pair
    - drop unique index before dropping its column (SQLite)
key-files:
  created:
    - Modules/Auth/composer.json
    - Modules/Auth/Providers/AuthServiceProvider.php
    - Modules/Auth/Database/Migrations/2026_05_19_000001_drop_email_add_username_to_users_table.php
    - Modules/Auth/Database/Migrations/2026_05_19_000002_add_is_developer_to_users_table.php
    - Modules/Auth/Database/Migrations/2026_05_19_000003_add_force_password_change_to_users_table.php
    - Modules/Auth/Database/Migrations/2026_05_19_000004_create_user_recovery_codes_table.php
    - Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php
    - Modules/Auth/Models/UserRecoveryCode.php
    - Modules/Auth/Database/Factories/UserRecoveryCodeFactory.php
    - Modules/EmailScan/Models/OAuthSecret.php
    - Modules/EmailScan/Database/Factories/OAuthSecretFactory.php
    - Modules/Auth/tests/Unit/SchemaReshapeTest.php
    - Modules/Auth/tests/Unit/UserRecoveryCodeTest.php
    - Modules/EmailScan/tests/Unit/Models/OAuthSecretTest.php
    - Modules/Auth/tests/TestCase.php
    - Modules/Auth/tests/Pest.php
  modified:
    - Modules/Core/Models/User.php
    - tests/Contracts/UserIdColumnArchTest.php
    - bootstrap/providers.php
    - composer.json
    - tests/Pest.php
    - phpunit.xml
decisions:
  - "oauth_secrets.user_id made nullable to satisfy the UserIdColumnArchTest contract (plan spec said non-nullable)"
  - "Tests use User::query()->create() rather than User::factory() — no User factory exists in v1.0"
metrics:
  duration: ~40m
  completed: 2026-05-20
  tasks: 2
  files: 22
---

# Phase 12 Plan 02: Multi-User Activation Schema Reshape Summary

Reshaped the `users` schema to a username-based identity and added the
`user_recovery_codes` and `oauth_secrets` tables with their Eloquent
models, landing the SQLite provider enum guard and a proven
encrypted-cast roundtrip for OAuth secrets.

## What Was Built

### Task 1 — Migrations + Auth module scaffold

A new `Modules/Auth` bounded module was scaffolded (composer.json,
`AuthServiceProvider`, module test base, Pest wiring) and registered
into `bootstrap/providers.php`, the root `composer.json` autoload-dev
map, `tests/Pest.php`, and `phpunit.xml`.

Five anonymous-class migrations were created under
`Modules/Auth/Database/Migrations/`:

- `000001` — drops the `users.email` unique index, drops `email`, adds
  `username` (unique).
- `000002` — adds `is_developer` (boolean, default false).
- `000003` — adds `force_password_change_at_next_login` (boolean,
  default false).
- `000004` — creates `user_recovery_codes` (`id`, `user_id` nullable FK
  cascade, `code_hash`, `used_at` nullable, `created_at` via
  `useCurrent()`; index on `user_id`; **no `updated_at`**).
- `000005` — creates `oauth_secrets` (`id`, `user_id` FK cascade,
  `provider`, `client_id`, `client_secret`, `redirect_uri`,
  `tokens_blob`, timestamps; unique `(user_id, provider)`; gmail/
  microsoft provider enum trigger pair).

`php artisan migrate:fresh` runs end-to-end; `migrate:rollback` reverses
all five migrations cleanly (verified by rolling fully back and
re-running fresh). No `Schema::` facade usage in any migration.

### Task 2 — Models + User reshape

- `Modules\Auth\Models\UserRecoveryCode` — `final`, `BelongsToUser`,
  `public $timestamps = false`, `immutable_datetime` casts on
  `used_at` + `created_at`.
- `Modules\EmailScan\Models\OAuthSecret` — `final`, `BelongsToUser`,
  `encrypted` casts on `client_secret` + `tokens_blob`.
- `Modules\Core\Models\User` — `email` removed from `$fillable` and
  PHPDoc; `username`, `is_developer`, `force_password_change_at_next_login`
  added to `$fillable`; `is_developer` + `force_password_change_at_next_login`
  cast to `boolean`.
- Test factories `UserRecoveryCodeFactory` and `OAuthSecretFactory`
  added for later-plan fixtures.
- `tests/Contracts/UserIdColumnArchTest.php` updated to recognise the
  two new tables.

## Schema Shape

`users`: `id, username (unique), is_developer, force_password_change_at_next_login,
password, period_start_day, …, remember_token, timestamps` — **no `email`**.

`user_recovery_codes`: `id, user_id (nullable FK cascade), code_hash,
used_at (nullable), created_at` — no `updated_at`; index on `user_id`.

`oauth_secrets`: `id, user_id (nullable FK cascade), provider (16),
client_id, client_secret, redirect_uri, tokens_blob (nullable),
created_at, updated_at` — unique `(user_id, provider)`; provider enum
trigger pair (`gmail`/`microsoft`).

## Encrypted-Cast Test Result

`OAuthSecretTest` proves the roundtrip: a value written through
`OAuthSecret::create()` reloads identically via Eloquent, while a raw
query-builder read of the same `client_secret` / `tokens_blob` column
returns ciphertext that does **not** contain the plaintext (nor the
`refresh_token` JSON key). 9 assertions, both tests green.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Drop the email unique index before dropping the column**
- **Found during:** Task 1
- **Issue:** `migrate:fresh` failed with `error in index users_email_unique
  after drop column: no such column: email` — SQLite rebuilds the table on
  `dropColumn` and the lingering unique index references the dropped column.
- **Fix:** Added a `dropUnique('users_email_unique')` callback before the
  `dropColumn('email')` callback in `000001`; the rollback path drops
  `users_username_unique` symmetrically before dropping `username`.
- **Files modified:** `Modules/Auth/Database/Migrations/2026_05_19_000001_drop_email_add_username_to_users_table.php`
- **Commit:** `dc69375`

**2. [Rule 3 — Blocking] oauth_secrets.user_id made nullable**
- **Found during:** Task 2
- **Issue:** The plan's migration action specified
  `$table->foreignId('user_id')->constrained()` (non-nullable) for
  `oauth_secrets`, but `UserIdColumnArchTest` — which the plan requires to
  stay green and recognise the new table — asserts every per-user
  `user_id` column is nullable.
- **Fix:** Added `->nullable()` to `oauth_secrets.user_id` so the table
  satisfies the arch contract. Consistent with `user_recovery_codes` and
  every other v1.0 domain table.
- **Files modified:** `Modules/Auth/Database/Migrations/2026_05_19_000005_create_oauth_secrets_table.php`
- **Commit:** `8f52194`

**3. [Rule 3 — Blocking] Created a worktree .env for the verification environment**
- **Found during:** Task 1
- **Issue:** The worktree shipped without a `.env`; the project `TestCase`
  reloads `.env` and emitted a `file_get_contents` warning on every test.
- **Fix:** Copied `.env.example` to `.env` and ran `key:generate`. `.env`
  is gitignored and not committed.

### Adjustments

- Tests build users with `User::query()->create([...])` rather than
  `User::factory()`. The plan said "factory must already exist from v1.0
  — verify"; it does not. `User` carries the `HasFactory` trait but no
  `UserFactory` file or `newFactory()` method exists. The v1.0
  convention (e.g. `SystemAlertsMigrationTest`,
  `ApplyAutoCategoryStageTest`) is direct `User::create()`, which this
  plan follows. The new `UserRecoveryCodeFactory` /
  `OAuthSecretFactory` create their owning user inline.
- SQLite's `ALTER TABLE ADD COLUMN` ignores the `->after()` clause, so
  `username` lands as the last user column rather than after `id`. This
  is cosmetic and has no functional effect (verified in the generated
  schema).

## v1.0 Callsites Still Referencing `users.email`

These belong to **Plan 12-03** (SignupAction + Fortify wiring) to
update — they are out of scope for this data-layer plan:

Production code:
- `Modules/Core/Internal/Console/InstallCommand.php` — `--email` option,
  `email` in the create payload, `$user->email` in output.
- `Modules/Core/Internal/Providers/FortifyServiceProvider.php` —
  authenticates `where('email', …)`; rate limiter keyed on `email`.
- `Modules/Core/Internal/Http/Livewire/TopNav.php` — `userEmail` =>
  `$user->email`.
- `Modules/Core/Resources/views/auth/login-form.blade.php` — email input
  field.

Test fixtures: ~162 test files create users with an `'email'` key.
Running the `Unit` testsuite shows 163 v1.0 tests failing on
`NOT NULL constraint failed: users.username` / missing `email` column.
This is the expected, planned breakage that Plan 12-03 resolves when it
wires the username-based signup + login paths and updates fixtures.

## Threat Model Compliance

- T-12-02-01 (oauth_secrets disclosure) — mitigated: `encrypted` cast on
  `client_secret` + `tokens_blob`, proven by the roundtrip test.
- T-12-02-02 (provider tampering) — mitigated: BEFORE INSERT/UPDATE
  trigger pair rejects any provider outside `{gmail, microsoft}`.
- T-12-02-04 (User attribute disclosure) — `$hidden = ['password',
  'remember_token']` retained on the reshaped User model.
- T-12-02-05 (recovery-code audit chain) — `user_recovery_codes` carries
  `used_at` and no destructive path; `updated_at` deliberately absent.
- T-12-02-03 (username case folding) — application-layer normalization is
  Plan 12-03's responsibility, as the threat register notes.

## Test Coverage

- `Modules/Auth/tests/Unit/SchemaReshapeTest.php` — 8 tests, 28
  assertions; green.
- `Modules/Auth/tests/Unit/UserRecoveryCodeTest.php` — 3 tests; green.
- `Modules/EmailScan/tests/Unit/Models/OAuthSecretTest.php` — 2 tests, 9
  assertions; green.
- `tests/Contracts/UserIdColumnArchTest.php` — green (26 assertions),
  recognises both new tables.

Larastan level 10 strict + Pint clean on every new/modified file.

## TDD Gate Compliance

Both tasks followed RED → GREEN. Gate commits in order:
- `116d9db` `test(12-02)` — RED (SchemaReshapeTest + scaffold)
- `dc69375` `feat(12-02)` — GREEN (migrations)
- `8f52194` `test(12-02)` — RED (model tests)
- `d9efb7a` `feat(12-02)` — GREEN (models + User reshape)

## Self-Check: PASSED

All five migration files, both model files, both factory files, and the
three test files exist on disk. All four commits
(`116d9db`, `dc69375`, `8f52194`, `d9efb7a`) are present in
`git log`. `php artisan migrate:fresh` exits 0.
