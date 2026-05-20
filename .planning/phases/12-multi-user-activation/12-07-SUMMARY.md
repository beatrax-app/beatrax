---
phase: 12-multi-user-activation
plan: 07
subsystem: emailscan
tags: [oauth, emailscan, secrets, per-user, encrypted-cast, repository-swap]
status: complete
requires:
  - oauth_secrets table + OAuthSecret model (Phase 12-02)
  - CurrentUser contract + CurrentUserService (Modules/Core, v1.0)
  - system_alerts table + SystemAlert model (v1.0 Phase 11)
provides:
  - per-user SQLite-backed OAuthSecretsRepository
  - legacy email-oauth.json rename migration
  - first-boot oauth.reauth_required system alert
affects:
  - Modules/EmailScan/Providers/EmailScanServiceProvider.php
tech-stack:
  patterns:
    - per-user repository scoped by CurrentUser, resolved fresh per call
    - encrypted JSON sub-document inside an `encrypted`-cast text column
    - filesystem-only one-way migration via Container-resolved Filesystem
key-files:
  created:
    - Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php
    - Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php
    - Modules/EmailScan/tests/Feature/OAuthSecretsCrossUserTest.php
    - Modules/EmailScan/tests/Feature/OAuthLegacyMigrationTest.php
  modified:
    - Modules/EmailScan/Public/Services/OAuthSecretsRepository.php
    - Modules/EmailScan/Providers/EmailScanServiceProvider.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsRepositoryTest.php
    - Modules/EmailScan/tests/Feature/OAuthClientWizardSecretsWriteFailedTest.php
  deleted:
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsAtomicRotationTest.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsDirModeTest.php
    - Modules/EmailScan/tests/Unit/Services/OAuthSecretsTempFileModeTest.php
decisions:
  - "OAuthSecretsRepository singleton binding stays — CurrentUserService reads the guard lazily on every id() call, so injecting CurrentUser into a singleton is safe; the repository also resolves currentUser->id() fresh per method"
  - "Per-inbox tokens stored as a JSON object keyed by inbox id inside the provider row's encrypted tokens_blob column; decode-merge strategy reads across all of the user's provider rows"
  - "saveInboxRefreshToken wraps the remove-then-write in a DB transaction so a re-provider'd inbox never momentarily exists under two providers"
  - "Three obsolete JSON file-mode tests deleted (their chmod-600 / tmp+fsync+rename premise no longer exists); rotation correctness folded into OAuthSecretsRepositoryTest"
metrics:
  duration: ~50m
  completed: 2026-05-20
  tasks: 2
  files: 11
---

# Phase 12 Plan 07: Per-User OAuth Secrets Store Summary

Delivered MULTI-05 — `OAuthSecretsRepository` is now a per-user
SQLite-backed store reading and writing the `oauth_secrets` table with
`client_secret` and inbox tokens encrypted at rest. The seven public
method signatures are unchanged, so every EmailScan / Receipts consumer
is transparent to the swap. The legacy JSON file is renamed to a
rollback artefact and the operator gets a one-time re-authorize warning.

## What Was Built

### Task 1 — Repository rewrite onto the per-user SQLite backend

`OAuthSecretsRepository` was rewritten. The constructor now takes
`DatabaseManager $db` + `CurrentUser $currentUser` (the `Filesystem`
dependency is gone). All provider reads/writes go through the
`OAuthSecret` Eloquent model so the `encrypted` casts apply:

- **Provider credentials** map to the `client_id` / `client_secret` /
  `redirect_uri` columns of the `(current_user_id, provider)` row.
- **Per-inbox tokens** are stored as a JSON object keyed by inbox id
  inside the encrypted `tokens_blob` column of the inbox's provider
  row. Reads (`loadInbox`, `rotateRefreshToken`, `removeInbox`) scan
  all of the current user's provider rows (decode-merge); writes target
  the row matching the inbox's provider, creating a bare provider row
  if the inbox is connected before its client credentials are set.

Every read filters `where('user_id', $this->currentUser->id())` and
every write stamps that id. `currentUser->id()` is called fresh inside
the private query helpers — never cached on a constructor property — so
a guard swap (impersonation, Plan 12-08) is honoured immediately.

The dead JSON machinery (`PATH_RELATIVE`, `DIR_MODE`, `FILE_MODE`,
`writeAtomic`, `readAll`, `absolutePath`, `performRename`, the
umask/flock sequence) was removed. `ALLOWED_PROVIDERS` + `assertProvider`
were kept. The `SecretsWriteFailed` type is retained for signature
compatibility — it is now thrown only if an Eloquent `save()` throws.
The class docblock was replaced entirely with a present-state
description of the per-user SQLite-backed store.

### Task 2 — Legacy rename migration + first-boot re-authorize alert

`2026_05_20_000002_rename_legacy_email_oauth_json.php` is an
anonymous-class migration that resolves `Filesystem` via
`Container::getInstance()`. `up()` renames a present
`storage/app/secrets/email-oauth.json` to
`email-oauth.json.pre-phase-12.bak` (mode 0600) and writes a
`storage/app/secrets/README.md` documenting the rollback path. It runs
only when the legacy file exists and the `.bak` target does not, so a
second run is a no-op. `down()` is a no-op (one-way). It touches no DB
table.

`EmitOAuthReauthRequiredAlert` writes a single
`oauth.reauth_required` / `warning` row to `system_alerts` when the
current user has zero `oauth_secrets` rows and the `.bak` rollback file
exists. It de-dups against an existing un-acknowledged row of the same
kind and short-circuits when the `.bak` file is absent.

## Inbox-Token Storage Strategy

The plan offered two strategies; the **decode-merge** alternative was
chosen as the simpler correct option. Each provider row's encrypted
`tokens_blob` holds a JSON object `{ "<inbox_id>": { id, provider,
email, refresh_token, scope, expires_at, access_token? }, ... }`. An
inbox's tokens live in its provider's row. Cross-provider reads merge
by scanning all of the user's provider rows; `saveInboxRefreshToken`
first removes any stale copy under a different provider, then writes
to the correct provider row — the remove-then-write pair runs inside a
`DatabaseManager` transaction so the inbox can never exist under two
providers or vanish mid-write.

## Singleton Binding Decision

`EmailScanServiceProvider` line ~77 keeps
`$this->app->singleton(OAuthSecretsRepository::class)` — **unchanged**.
`CurrentUserService` injects `AuthFactory` (not a snapshotted user) and
calls `$this->auth->guard()->user()` lazily inside `resolveUser()` on
every `id()` / `user()` call. A singleton repository holding a
`CurrentUser` reference therefore always reads the live guard. The
repository additionally never caches the id in a constructor property,
calling `currentUser->id()` fresh in each query helper — so the
impersonation guard swap in Plan 12-08 is honoured with no binding
change. `bind()` was not needed.

## Obsolete Tests — Deleted vs Rewritten

- **Deleted** `OAuthSecretsAtomicRotationTest.php`,
  `OAuthSecretsDirModeTest.php`, `OAuthSecretsTempFileModeTest.php` —
  every assertion (failed-rename intact-prior-file, directory mode
  0700, temp-file born-narrow 0600, umask restore) targets the JSON
  atomic-write machinery that no longer exists. Token-rotation
  correctness — the only surviving relevant concern — is covered by
  the rewritten `OAuthSecretsRepositoryTest`.
- **Rewritten** `OAuthSecretsRepositoryTest.php` — now drives the
  DB-backed store with a real authenticated user (`actingAs` +
  `uses(RefreshDatabase::class)`, mirroring `OAuthSecretTest`). Covers
  provider round-trip, encrypted-cast ciphertext proof, inbox save /
  load / rotate / remove, and unknown-provider rejection.

## Re-authorize-Alert Trigger Mechanism

The listener runs from the existing `core::livewire.top-nav` View
Factory composer in `EmailScanServiceProvider` — the same per-request
hook the Inboxes badge already uses. The composer fires at most once
per authenticated request that renders the top nav. The listener's own
cheap `.bak`-file existence pre-check and the un-acknowledged-row
de-dup guard make it a no-op on every request after the first, so it
does not fire repeatedly.

## Consumer Suites — Pass Unchanged

- `Modules/EmailScan/tests/` — 229 passed. The 17 remaining failures
  are a pre-existing, unrelated `Vite manifest not found` error
  (`public/build/manifest.json` absent in the test environment) in four
  test files this plan does not touch.
- `Modules/Receipts/tests/` — 91 passed, 1 skipped. Receipts consumers
  unaffected.
- `OAuthClientWizardSecretsWriteFailedTest` — its two anonymous
  repository subclasses were updated for the new
  `DatabaseManager + CurrentUser` constructor; both tests pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree environment setup**
- **Found during:** Task 1 verification.
- **Issue:** The worktree shipped without `vendor/`, `.env`, or a
  SQLite database file, so no test or static-analysis command could
  run.
- **Fix:** Ran `composer install`, copied `.env.example` to `.env` +
  `php artisan key:generate`, created `database/database.sqlite` and
  ran `php artisan migrate`. None of these files are committed (all
  gitignored).

**2. [Rule 1 — Bug] OAuthClientWizardSecretsWriteFailedTest broke on the new constructor**
- **Found during:** Task 1 (EmailScan consumer-suite run).
- **Issue:** The test subclasses `OAuthSecretsRepository` via
  `new ... extends` passing a `Filesystem` to the parent constructor —
  a `TypeError` after the constructor changed to
  `DatabaseManager + CurrentUser`.
- **Fix:** Updated both anonymous subclasses to resolve and pass the
  new dependencies from the container. The test's purpose (the wizard
  catches `SecretsWriteFailed`) is preserved; both tests pass.
- **Commit:** `0a6bbe6`

**3. [Rule 3 — Blocking] Larastan staticMethod.dynamicCall on the listener's exists() checks**
- **Found during:** Task 2.
- **Issue:** The project's larastan-strict-rules profile rejects
  chaining `exists()` / `whereNull()` on `Model::query()`'s Eloquent
  Builder (`staticMethod.dynamicCall`).
- **Fix:** Switched the listener's two existence checks to the raw
  `DatabaseManager` Query Builder (`->connection()->table(...)`), the
  same pattern documented in `TransactionDetail` and `SystemAlertQuery`.
- **Commit:** `3daf771`

## Threat Model Compliance

- **T-12-07-01** (cross-user OAuth credential leak) — mitigated: every
  repository read filters `where('user_id', currentUser->id())`; the
  `OAuthSecret` model also carries the `BelongsToUser` `UserScope`.
  Proven by `OAuthSecretsCrossUserTest`.
- **T-12-07-02** (client_secret / refresh tokens readable in SQLite) —
  mitigated: `client_secret` + `tokens_blob` use the `encrypted` cast;
  a raw column read returns ciphertext. Proven by two repository tests.
- **T-12-07-03** (legacy JSON on disk) — accepted per D-19: the file is
  renamed to `.pre-phase-12.bak` (mode 0600) as a rollback artefact;
  the app never reads `.bak`; the README documents removal.
- **T-12-07-04** (stale CurrentUser cached in the singleton) —
  mitigated: the repository calls `currentUser->id()` fresh per method.
- **T-12-07-05** (re-authorize alert fired forever) — mitigated: the
  listener de-dups and only runs when the `.bak` file exists.
- **T-12-07-06** (invalid provider into oauth_secrets) — mitigated:
  `assertProvider` rejects anything outside gmail/microsoft; the
  table's enum trigger pair is the schema-level backstop.
- **T-12-07-07** (silent data loss if the swap is premature) —
  mitigated: the JSON file is renamed, not deleted; the README
  documents recovery.

## TDD Gate Compliance

Task 1 followed RED → GREEN with separate gate commits:
- `b475b13` `test(12-07)` — RED (rewritten repository tests, 18 failing
  against the JSON backend; obsolete file-mode tests deleted).
- `0a6bbe6` `feat(12-07)` — GREEN (repository rewrite; all 18 pass).

Task 2 (`autonomous`, not `tdd`) was committed once with its tests:
- `3daf771` `feat(12-07)` — migration + listener + 6-test feature suite.

## Test Coverage

- `OAuthSecretsRepositoryTest.php` — 16 tests, green.
- `OAuthSecretsCrossUserTest.php` — 3 tests, green.
- `OAuthLegacyMigrationTest.php` — 6 tests, green.
- `OAuthClientWizardSecretsWriteFailedTest.php` — 2 tests, green.
- `tests/Contracts/BoundaryArchTest.php` — 43 invariants, green.
- `composer analyse` (Larastan L10 strict, 502 files) — 0 errors.
- `composer format:check` (Pint) — passed.
- `php artisan migrate:fresh` — exits 0.

## Self-Check: PASSED

All four created files and four modified files exist on disk; the three
obsolete test files are deleted. All three commits (`b475b13`,
`0a6bbe6`, `3daf771`) are present in `git log`.
