# `Core` — specs

The behavioural contract for the `Core` module.

## Behavioral contracts

- **`BelongsToUser` is the only sanctioned way for a domain model to
  scope to the user.** The `UserScope` global scope is the single
  read-side guard; every authenticated query inherits it. The arch
  invariant `everyUserScopedModelUsesBelongsToUser` enforces the trait
  composition. (See [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md).)
- **`CurrentUser::user()` never returns `null`.** If no guard user is
  bound, the service throws `NotAuthenticatedException`; downstream
  code can assume a real `User` instance after a successful resolve.
- **`UserDataPathService` is the SOLE sanctioned caller of
  `base_path()` in production code.** The arch invariant
  `noRawPathHelpersOutsidePathService` blocks every other call site,
  so a packaged build (where `NATIVEPHP_STORAGE_PATH` redirects the
  storage root) cannot land a stray path that escapes the redirect.
  (`tests/Feature/UserDataPathResolutionTest.php`)
- **`EnsureAppKey::run()` runs `key:generate --force` exactly once per
  install.** The sentinel file at
  `appPath('first-launch.app-key-generated')` is the idempotency
  signal; subsequent invocations short-circuit.
  (`tests/Feature/Bootstrap/AppKeyRegenerationTest.php`)
- **`/health` returns a deterministic four-key JSON object — `status`,
  `app_version`, `php_version`, `sqlite_version` — with no
  timestamp.** An external probe can equality-check the entire body
  without normalising volatile fields.
  (`tests/Feature/HealthEndpointTest.php`)
- **`/health` is auth-free.** A non-loopback request still hits
  `LoopbackOnly` and gets 404; an unauthenticated loopback request
  passes through.
- **Every non-loopback request raises 404.** `LoopbackOnly` middleware
  inspects `SERVER_ADDR`; if a non-loopback IP is set, it throws
  `NotFoundHttpException`. Requests with no `SERVER_ADDR` pass (CLI
  + Pest fixtures). The detection covers IPv4 127.0.0.0/8, IPv6
  `::1`, and the IPv4-mapped-IPv6 `::ffff:127.x.x.x` form on
  binary-form (`inet_pton`) comparison.
- **Every authenticated response carries `Cache-Control: no-store`.**
  `NoStoreFinancialData` is pushed onto the `auth` middleware group
  so the browser never caches a transaction list.
- **`ElectronUpdateChannel` verifies every manifest before raising any
  `system_alerts` row.** Ed25519 signature verification against the
  publisher public key is the gate; a manifest failing verification
  is logged at `warning` and no banner appears.
  (`tests/Feature/AutoUpdate/Ed25519ManifestVerificationTest.php`)
- **`ElectronUpdateChannel` verifies every downloaded binary before
  install handoff.** SHA-512 hash of the binary must equal the
  manifest's declared hash. Mismatch aborts the install path.
  (`tests/Feature/AutoUpdate/Sha512BinaryVerificationTest.php`)
- **A manifest more than 30 days stale raises an `update.stale`
  banner.** The banner does NOT auto-install — the user has to
  investigate first. (`tests/Feature/AutoUpdate/StaleVersionBannerTest.php`)
- **Skipping a version persists per-user.** The user's
  `user_preferences.skipped_update_versions` JSON column records the
  list; the banner does not re-surface those versions.
  (`tests/Feature/AutoUpdate/SkipVersionTest.php`)
- **`AcknowledgeSystemAlert` is the sole sanctioned writer of
  `system_alerts.acknowledged_at`.** Direct writes via Eloquent /
  query-builder are forbidden by the arch invariant
  `noUnsanctionedSystemAlertAcknowledgements`.
- **The SQLite WAL + busy_timeout + synchronous=NORMAL PRAGMAs apply
  to every connection.** `SqliteOptimizationsProvider` registers the
  listener; a connection that bypasses the listener (test harness
  shortcut) is detectable via `PRAGMA journal_mode`. (See
  [ADR 0005](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0005-sqlite-wal.md).)
- **The `UserInstalled` event must be dispatched in two distinct
  contexts: signup ceremony and `beatrax:install` re-run.** Listeners
  MUST be idempotent — the install command's "re-seed reference data"
  guarantee depends on it.
- **Every column in `SecretsColumnRegistry::columns()` is enforced by
  the `noSecretsInLivewireSnapshot` arch invariant.** Adding a new
  secret column without registering it is an unguarded leak.

## Edge cases

- **Empty database** — the install command runs migrations first, then
  dispatches `UserInstalled`. The user lands on a working install.
- **Re-running `beatrax:install` on an existing install** — the
  command confirms the existing user, dispatches `UserInstalled` again
  (re-seeding reference data idempotently), prints the existing
  recovery codes only if requested explicitly.
- **`CurrentUser` called from a queued job** — the job arrives with a
  serialised user payload; the listener calls `Auth::onceUsingId($userId)`
  or otherwise binds the guard before resolving `CurrentUser`.
- **`UserScope` query inside an artisan command that has no
  authenticated user** — the scope detects the unbound auth and
  silently no-ops the filter (returns all rows). This is the
  intentional CLI escape hatch for the operator-only commands.
- **Backup file missing on restore** — `RestoreDatabaseCommand` aborts
  before touching the live DB; the operator gets a clear error.
- **Boot probe finds Node missing** — the probe returns level=warn
  (not fail) because the runtime path runs without Node; only the
  release pipeline needs it.
- **A manifest fetch succeeds but the host is down at binary
  download** — the channel logs a `warning`, the in-flight
  `update.available` banner stays visible without flipping to
  installed; the user retries.
- **`EnsureAppKey` sentinel exists but the `.env` `APP_KEY` is
  empty** — the action still short-circuits (sentinel is the
  authoritative signal). The operator restores from backup or runs
  `key:generate --force` manually; this is a corrupted-install path.

## Cross-module collaborators

- **Depends on** nothing inside `Modules/`. This is by design — Core
  is the floor of the dependency graph. The only external dependencies
  are framework primitives (Eloquent, Auth, HTTP), `Carbon`, the
  `sodium_*` extension for signature verification, and the NativePHP
  `PublisherManifestFetcher` contract (the binding lives in
  [`Desktop`](../desktop/specs.md)).
- **Depended on by** every other module. The cross-module surface this
  module provides:
  - `User` model — every domain model with a `user_id` FK.
  - `BelongsToUser` trait — every user-scoped Eloquent model.
  - `Clock` contract — every service that needs a timestamp.
  - `CurrentUser` contract — every Livewire page + every action that
    runs on the authenticated user.
  - `UserDataPathService` — every place a path is read.
  - `LockStore::forUniqueJobs()` — `Chains`, `EmailScan`,
    `DriftAlerts`, any other module using `ShouldBeUniqueUntilProcessing`.
  - `UserInstalled` event — `Auth` (raises), `Categorization`,
    `Community`, `Onboarding` (listen).
  - `system_alerts` infrastructure — every module that needs to
    raise a banner reads `SystemAlertQuery` and writes via
    `AcknowledgeSystemAlert`.

## Configuration + feature flags

- `NATIVEPHP_STORAGE_PATH` (env) — redirects the storage root for the
  packaged build. Absent → project-rooted `storage/`.
- `NATIVEPHP_APP_VERSION` (env) — populates `/health`'s `app_version`;
  absent → `'dev'`.
- `config('auto_update.publisher_public_key_hex')` — the Ed25519
  public key the channel verifies manifests against.
- `users.theme` / `users.close_behavior` / `users.period_start_day` /
  `users.default_currency_view` — per-user preference columns.
- `user_preferences.skipped_update_versions` (JSON) — per-user
  skipped-update-version list.
- `BEATRAX_RUNTIME=local` (env) — Dev-mode runtime distinguisher
  several modules respect (`DevMode` for surface gates, `Queue` for
  driver). See [ADR 0007](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0007-database-queue-driver.md).
