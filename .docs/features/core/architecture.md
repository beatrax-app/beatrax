# `Core` — architecture

The `Core` module owns the shared primitives every other module
consumes: the `User` model, the `BelongsToUser` trait that scopes every
domain row to its owner, the `CurrentUser` contract that gives the
authenticated identity to any service that asks, the `Clock` abstraction
that keeps tests deterministic, the system-alerts banner the layout
renders, the `/health` endpoint, the install + doctor + backup +
restore + failed-jobs CLI commands, and the helpers that resolve every
filesystem path the bundle reads or writes.

## What this module is for

A modular codebase needs a place for the genuinely-shared primitives
that have no natural home in any one bounded module: the user identity,
the clock, the loopback guard, the storage-path resolver, the
auto-update channel. Putting them anywhere else either creates a
circular dependency (every module needs `User`) or arbitrary placement
(why does the clock live in `Forecasting`?). `Core` is the answer.

The trade-off is sharp: `Core` becomes the floor of the dependency
graph. Every other module imports from it; it imports from nothing.
That asymmetry is enforced by the
[module-boundaries architecture topic](../../architecture/module-boundaries.md)
and the matching arch invariant suite.

What the module explicitly does NOT do:

- It never imports a domain module. The `BelongsToUser` trait does not
  know about transactions or chains; it knows about
  `users.id` and the active auth guard.
- It never owns business logic. The system-alerts banner shows alerts;
  it does not decide what counts as an alert. The clock returns time;
  it does not decide how often a job runs. The CLI commands run
  procedures; they do not decide policy.
- It never reaches a remote network on its own. The single outbound
  exception is `ElectronUpdateChannel`, which fetches the publisher
  update manifest from a fixed URL configured by the bundle (see
  [ADR 0004](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0004-local-only-hosting.md)). Even that fetch
  is gated by Ed25519 signature verification before any side effect
  fires.

## Module boundary

`Public/` is the foundation surface every other module depends on:

- **Concerns/**
  - `BelongsToUser` trait — adds a `user_id` column to `$fillable`,
    registers the `UserScope` global scope, and exposes a
    `user()` belongsTo relation. The arch invariant
    [`BelongsToUser` everywhere](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md)
    requires every user-scoped Eloquent model to use this trait.
- **Contracts/**
  - `Clock` — returns the current time as `CarbonImmutable`. Bound to
    `SystemClock`; tests bind a `FrozenClock`.
  - `CurrentUser` — exposes `id()`, `user()`, `periodStartDay()`,
    `isAuthenticated()`. Bound to `CurrentUserService`.
  - `PublisherManifestFetcher` — the contract the auto-update path
    uses to fetch the publisher manifest; concrete impl lives in
    [`Desktop`](../desktop/architecture.md).
- **Scopes/**
  - `UserScope` — the global query scope `BelongsToUser` registers.
- **Services/**
  - `SystemClock` (impl. `Clock`).
  - `CurrentUserService` (impl. `CurrentUser`).
  - `UserDataPathService` — single source of truth for every path the
    app reads or writes; the SOLE sanctioned caller of `base_path()`
    in production code.
  - `ElectronUpdateChannel` — the manifest-fetch + signature-verify +
    binary-hash-verify pipeline behind the `update.available` /
    `update.stale` system-alerts banner kinds.
  - `SecretsColumnRegistry` — enumerates the columns the
    `noSecretsInLivewireSnapshot` arch invariant treats as secret.
  - `NavCountsService` — per-user item counts for the sidebar nav badges
    (Transactions, Recurring, Counterparties, Drift alerts, Budgets,
    Subscriptions, Imports). The sidebar renders on every authenticated
    page, so the whole set is computed once and CACHED per user (short
    TTL) rather than running a COUNT per item per render; writes that
    materially change a count call `forget()` to drop the cache. Counts
    read straight from the canonical tables (user-scoped) rather than
    fanning out to each module's query service. A missing table (a
    module whose migrations aren't present in this build) counts as 0,
    not an error.
  - `SystemAlertQuery` — read-side query for the banner. `active(?User)`
  returns the user's own un-acknowledged rows AND the system-wide
  (`user_id IS NULL`) un-acknowledged rows in one Collection, ordered
  critical → warning → info with chronological tie-break inside each
  tier — the banner must surface SQLite-PRAGMA drifts (system-wide)
  alongside a user's own corrupt-backup alerts without leaking another
  user's private rows. Implementation runs through the raw
  `DatabaseManager::table()` Query Builder (not `SystemAlert::query()`)
  because larastan-strict-rules rejects chained Eloquent\Builder calls
  after `Model::query()`; the row set is hydrated back into `SystemAlert`
  models via `SystemAlert::hydrate()` to recover the model surface the
  banner expects.
- **Actions/**
  - `AcknowledgeSystemAlert` — single sanctioned write path for
    `system_alerts.acknowledged_at`.
- **Bootstrap/**
  - `EnsureAppKey` — first-launch APP_KEY regeneration with
    sentinel-driven idempotency. Bound into the NativePHP first-launch
    chain by [`Desktop`](../desktop/architecture.md).
- **Controllers/**
  - `HealthController` — the auth-free `/health` endpoint. Returns
    `{status, app_version, php_version, sqlite_version}` — a flat
    JSON object with deterministic key order, no timestamp.
- **Services/** (continued)
  - `RestoreEncryptedBackup` — mirrors `db:restore`'s safety rails for the
    in-app flow. Ordering is the safety contract: the upload is decrypted
    and integrity-checked to a temp file FIRST — a wrong passphrase or a
    corrupt backup throws before the live database is ever touched. Only
    once the source is proven good does it take a pre-restore snapshot of
    the current database (so the old state is always recoverable) and
    then atomically swap the file in. SQLite-only; the caller owns the
    destructive-action confirmation gate and reloading the app afterward.
- **Support/**
  - `LockStore` — the single sanctioned resolver of the cache store
    backing queue-uniqueness locks for `ShouldBeUniqueUntilProcessing`
    jobs; the only module file using the `Cache` facade + `config()`
    helper. The DI-only rule cannot apply here: Laravel invokes
    `ShouldBeUniqueUntilProcessing::uniqueVia()` at queue-push time,
    before the job's constructor DI has run, so an injected
    `Repository` is unreachable from a `uniqueVia()` body. The store
    name comes from `config('cache.locks_store')` — `database` in
    shipped builds, `redis` on a developer's local box.
  - `SafeTrace` — rewrites every absolute filesystem path inside a
    `Throwable::getTraceAsString()` output to a project-root-relative
    path (raw traces leak the developer's home-directory layout to any
    surface that can be screen-shared, e.g. the in-app `/dev/logs`
    viewer — ASVS V7 Errors & Logging), and truncates the trace to a
    configurable line count so a deep recursion cannot flood the log.
- **Events/**
  - `UserInstalled` — dispatched by `Modules\Auth\Public\Actions\SignupAction`
    after a successful install AND by `beatrax:install` on every re-run.
    Listeners (default-category-tree seeder, community-corpus seeder,
    wizard first-step priming) MUST be idempotent.
- **Exceptions/**
  - `NotAuthenticatedException` — thrown by `CurrentUserService::resolveUser()`
    when no guard is bound. Maps to a 401 / redirect at the request
    boundary.
- **Dto/**
  - `UpdateManifestDto` — typed shape of the verified update manifest.

`Internal/` houses the implementation owners: the SQLite optimisations
provider (WAL + busy_timeout + synchronous=NORMAL), the health-check
service provider that wires the boot probes, the install / doctor /
backup / restore / failed-jobs CLI commands and their probes, the
loopback-only middleware that hides every non-loopback request behind
a 404, the no-store middleware that prevents browser caching of
financial data, the system-alerts-banner Livewire SFC, the dashboard
Livewire SFC, the help-data-locations Livewire SFC, the app-sidebar
Livewire SFC, the settings page Livewire SFC, and the health-check
listener that watches the boot probes during the install ceremony.

## Key services + events

- `BelongsToUser` — the trait every user-scoped model uses. It
  resolves the `UserScope` through `Container::getInstance()->make()`
  — the SOLE sanctioned container touch-point in the codebase (Eloquent
  boot hooks run static and cannot accept constructor DI).
- `CurrentUser::user()` — the read-everywhere identity. Returns a real
  `User` or throws `NotAuthenticatedException`; never null.
- `Clock::now()` — the read-everywhere wall clock. Tests substitute a
  `FrozenClock`; production substitutes `SystemClock`.
- `UserDataPathService` — every read of `database_path()`,
  `storage_path()`, `base_path()` outside this class is forbidden by
  the arch invariant `noRawPathHelpersOutsidePathService`. The
  `NATIVEPHP_STORAGE_PATH` env var redirects the storage root for the
  packaged build. `getenv()` is used (not Laravel's `env()` helper)
  because it is unconditional at every boot stage, which is what makes
  the static accessors safe to call from `config/*.php` files evaluated
  before the container exists.
  - **Mobile runtime detection**: NativePHP mobile does NOT set
    `NATIVEPHP_STORAGE_PATH` — it retargets `base_path()` itself into
    the app-sandbox container, so every accessor already resolves
    inside the sandbox with no dedicated mobile branch. `platform()`
    (`getenv('NATIVEPHP_PLATFORM')`) is the primary on-device signal,
    but it is NOT reliably visible at per-request config-load in
    NativePHP's persistent runtime — the env is present when the
    `->booted()` hook fires yet reads back null when `config/*.php` is
    re-evaluated per request. Since `config/database.php` resolves
    `databaseFile()` per request, a platform-only check would silently
    fall back to the app-BUNDLE database path on-device — re-shipping
    the dev `database.sqlite` (a data leak) and defeating the
    fresh-install onboarding gate. `isMobileRuntime()` therefore
    detects the runtime STRUCTURALLY as a fallback: NativePHP mobile
    relocates `base_path()` into `<app_storage>/laravel` and
    provisions a sibling `persisted_data` store (created by the native
    layer before the PHP runtime serves a request), so the sibling
    directory existing is a request-load-stable mobile signal that
    never matches on desktop/host. `databaseFile()` targets that
    sibling persisted store on mobile — empty on a genuine fresh
    install, retained across app updates.
- `EnsureAppKey::run()` — first-launch APP_KEY mint, guarded by a
  0-byte sentinel under the user-data app directory. Subsequent
  invocations short-circuit, so the action is safe to wire into the
  chained-bootstrap path that runs on every launch.
- `ElectronUpdateChannel` — fetches the publisher manifest, verifies
  the Ed25519 signature, hashes the downloaded binary against the
  manifest's SHA512, and dispatches a `system_alerts` row only after
  every verification succeeds. The 30-day staleness threshold raises
  an `update.stale` banner without offering an install (the user has
  to investigate manually before the install fires). Trust posture:
  with no OS-level code signing on any shipped platform, Ed25519
  manifest signing + SHA512 binary verification are the SOLE
  binary-integrity signals between a tampered GitHub Releases asset
  and a malicious binary running on a user's machine — every fetched
  manifest is verified against the publisher public key BEFORE any
  `system_alerts` row is raised, and every downloaded binary is hashed
  against the (now-trusted) manifest's declared value BEFORE the
  install handoff fires. Key generation runs once via
  `sodium_crypto_sign_keypair()`; the SECRET half lives in the
  repository secret `ED25519_PRIVATE_KEY` and never leaves the
  release-pipeline runtime, while the PUBLIC half is committed as the
  default for `auto_update.publisher_public_key_hex`.
- `UserInstalled` event — the cross-module "a user just appeared"
  signal. Subscribed to by `Categorization`, `Community`, `Onboarding`.
- `SecretShield` — wraps a persisted secret (biometric wrap blob, OAuth
  token blob) in the OS keychain on bundles that have one, layered
  UNDER whatever at-rest encryption already protects the column. No
  handle/forget lifecycle: `protect()` returns bytes to persist,
  `reveal()` turns them back into plaintext. The default
  `PassthroughSecretShield` (web/mobile) is identity — the value is
  stored exactly as the caller's own column cast already encrypts it.
  The desktop bundle's `SafeStorageSecretShield` runs `protect()`
  through Electron `safeStorage` (OS keychain / DPAPI / Keychain
  Services) via the same `DesktopKeyCustodian` used for the session
  key, so the persisted blob is machine-bound ciphertext — combined
  with the existing APP_KEY column encryption this is defense-in-depth:
  a stolen SQLite file plus a stolen `.env` still cannot reveal the
  secret without the machine keychain. When safeStorage is unavailable
  (headless CI, early-boot race, or a value written before shielding
  was enabled), `reveal()` returns its input unchanged so legacy /
  unshielded rows keep working.

## Data flow

The auth + global-scope chain that every domain model rides:

```
HTTP request
  → Authenticate middleware (Auth module pushes it onto the auth group)
  → CurrentUserService picks up auth()->guard()->user()
  → any Eloquent query via a BelongsToUser-using model
       → UserScope::apply
            → query->where('user_id', CurrentUser::id())
  → cross-user row never visible
```

The first-launch boot chain:

```
NativePHP boots
  → FirstLaunchBootstrap (Modules/Desktop)
      → EnsureAppKey::run                (Core)
           → if sentinel absent:
                php artisan key:generate --force
                write sentinel
      → … other first-launch actions
  → request routing begins
```

The `/health` endpoint:

```
GET /health
  → HealthController::__invoke
      → resolve app_version from NATIVEPHP_APP_VERSION env (fallback 'dev')
      → SELECT sqlite_version() via injected DatabaseManager
      → return {status: ok, app_version, php_version, sqlite_version}
```

Backup encryption (`BackupEncryptor`):

Passphrase-based encryption for database backups, so a backup that
leaves the machine (external drive, cloud, email) is unreadable
without the passphrase. Format (`beatrax-encrypted-backup-1`): the
file is a self-describing header followed by a libsodium secretstream
(XChaCha20-Poly1305) — an authenticated, chunked AEAD stream, so the
whole file never has to sit in memory and any tampering or truncation
is detected at decrypt time:

```
MAGIC(8) | salt(16) | opslimit(uint32 LE) | memlimit(uint64 LE) | stream-header(24) | AEAD chunks…
```

The 32-byte stream key is derived from the passphrase with Argon2id
(`sodium_crypto_pwhash`); the random salt and the exact Argon2id
ops/mem limits are stored in the header so a future, stronger default
can still decrypt today's backups.

Quantum safety: this scheme is post-quantum secure by construction
because it is purely symmetric — there is no public-key/asymmetric
step anywhere, so Shor's algorithm (which breaks RSA/ECC/DH key
exchange) has nothing to attack. XChaCha20-Poly1305 uses a full
256-bit key; Grover's algorithm gives only a quadratic speed-up, so the
effective security is 128 bits post-quantum (NIST's top category, the
same footing AES256 is considered quantum-safe on). Argon2id is a
memory-hard KDF, so quantum brute-forcing of the passphrase is bounded
by RAM, not just compute. A post-quantum KEM (ML-KEM / Kyber) is
intentionally NOT used since there is no recipient public key to
encrypt to — the key derives from the user's passphrase, so passphrase
entropy is the real floor, not the cipher.

A wrong passphrase, a flipped byte, or a truncated file all surface as
a thrown exception rather than silent garbage — the caller must treat
any exception as "do not trust this restore".

Install CLI (`beatrax:install`):

Idempotent first-run setup for the local-only single-user app: (1)
refuses to run when the SQLite database path is inside a cloud-sync
folder (iCloud Drive / OneDrive / Dropbox / Mobile Documents /
`Library/CloudStorage` and other vendor folders — `CLOUD_SYNC_TOKENS`);
(2) runs pending migrations; (3) creates User id=1 if absent —
re-running with the same username does NOT update the password (a
dedicated reset-password command is future work); (4) always
dispatches `UserInstalled` for the resolved user (new or pre-existing).
Listeners (`SeedDefaultCategoryTree`, other module-local install
hooks) MUST be idempotent, so re-running install heals seeded
reference data that went missing between the user's original install
and a fresh listener wire-up.

`--launchd` is a separate code path: installs three macOS LaunchAgent
plists from `deploy/launchd/*.plist` to `~/Library/LaunchAgents/`,
substituting `{{ABS_PHP_BINARY}}` / `{{ABS_PROJECT_ROOT}}` at install
time. The Redis plist is optional (`--without-redis`, for when Docker
Desktop auto-starts the container on login). `bootstrapPlist()` is
`protected` (not `private`) so `InstallLaunchdCommandTest` can subclass
+ override it to capture the intended `launchctl bootstrap` target
without mutating the developer's real launchd; a non-zero
`launchctl bootstrap` exit is a warning, not a hard failure, since it
also fires for "already loaded" on re-install.

Failed-jobs CLI (`beatrax:failed-jobs`):

Maintenance operations on the Laravel-managed `failed_jobs` table. The
`action` argument selects the subcommand; only `prune` is wired for
v1 (future verbs like `view`/`retry-all`/`clear` can extend without
renaming the surface). The duration token grammar (`30d`, `12h`, `2w`)
is parsed by `DurationParser`; the default cutoff is 30 days. `--dry-run`
lists the would-be-deleted rows (capped at 50) without writing.

`DurationParser` parses a short token like `30d`/`12h`/`2w` into a
`CarbonImmutable` offset (grammar `/^(\d+)([dhw])$/i`). The `m` token
is intentionally NOT accepted — across the SI-style grammars that look
similar, `m` could mean either "minutes" or "months", and the
disambiguation cost is higher than the value (callers wanting sub-day
cutoffs pass `1h`/`12h`; month-scale callers pass `30d`/`4w`). Zero
amounts (`0d`/`0h`/`0w`) are rejected: they resolve to `now - 0 = now`,
which paired with the prune predicate `WHERE failed_at < $cutoff` would
delete every `failed_jobs` row regardless of age — a typo of `0d` for
`30d` would be catastrophic, so the grammar itself refuses the
load-bearing footgun rather than relying on the `--dry-run` default
alone to catch it.

Doctor CLI (`beatrax:doctor`):

A homogeneous iteration over every registered `Probe` (tool-version
checks + SQLite-substrate health + backup freshness). Each probe
contributes one line to the output table and one severity bucket to
the exit-code aggregator: `PhpVersionProbe` is a BLOCKER if below the
composer.json/CI-matrix minimum; `ComposerVersionProbe` /
`SqliteCliVersionProbe` / `NodeVersionProbe` warn if missing (none are
runtime-fatal for the dashboard — they matter for dev workflows);
`WalModeProbe` / `SynchronousModeProbe` / `BackupFreshnessProbe` are
the SQLite-substrate probes. `BackupFreshnessProbe` reads the newest
`*.meta.json` sidecar under the backups directory and compares its
`completed_at` to the clock; if none exists or the newest is older
than 48h it returns `warning` AND writes a system-wide
`system_alerts(kind=backup_overdue)` row, gated by a 1-hour recency
check (mirrors `HealthCheckServiceProvider::recordDriftAlert` — 100
`beatrax:doctor` runs in an hour produce at most one banner card, not
100). The recency check uses the raw Query Builder (not Eloquent)
since larastan-strict-rules rejects chained `Eloquent\Builder` calls
after `Model::query()`; the cutoff is normalised to UTC because
SQLite's `CURRENT_TIMESTAMP` default writes in UTC, not app-local. The
whole alert-write path is wrapped in try/catch so a missing
`system_alerts` table (e.g. probe invoked pre-migration) cannot make
the probe throw — the `Probe` contract forbids that; the alert write
is best-effort, and the `warning` `ProbeResult` is the operator-visible
signal regardless. `ext-imap` is reported separately
(info-only) because the project uses the pure-PHP `webklex/php-imap`;
the extension's presence is neither required nor forbidden and folds
awkwardly into the severity bucket model. Exit codes: `0` every probe
`ok` (or `info` for ext-imap), `1` one or more `warning` probes, `2`
at least one `critical` probe.

The `Probe` contract: each probe is a small testable unit reading one
operational signal and returning a `ProbeResult` at severity
`ok`/`warning`/`critical`. Probes MUST NOT throw — every IO/SQL
touchpoint is wrapped in try/catch internally, with failures bubbling
up as a `critical` result carrying the exception message. This keeps
`DoctorCommand`'s flow trivial (iterate, print, aggregate exit code)
and the boot-time `HealthCheckServiceProvider` listener structurally
crash-free. `ExternalToolVersionProbe` is the shared base for
`ComposerVersionProbe`/`SqliteCliVersionProbe`/`NodeVersionProbe`: runs
an external CLI tool with `--version`, surfaces stdout as `ok`;
failure modes (not on PATH, non-zero exit, empty stdout) surface as
`warning` (not `critical`) since missing external tooling is
operationally relevant but not runtime-load-bearing for the dashboard.
`PhpVersionProbe` compares `phpversion()` (not the `PHP_VERSION`
constant, so opcache can't carry a stale value) against
`composer.json`'s minimum by major.minor only, so alpha/beta/RC builds
of the minimum version still pass. `WalModeProbe` / `SynchronousModeProbe`
mirror each other line-for-line (journal_mode == 'wal' /
synchronous == 1 == NORMAL); `HealthCheckServiceProvider` owns the
matching boot-time `system_alerts` write so probe + listener cooperate
without double-counting.

Backup & restore CLI (`db:backup` / `db:restore`):

`BackupDatabaseCommand` produces a consistent SQLite backup via
`VACUUM INTO`, validates the output with `PRAGMA integrity_check`, and
applies the retention sweep (`BackupRetentionPolicy`: 7 newest dailies
+ 4 most-recent Sundays). Each successful run writes a `.meta.json`
sidecar at chmod 0600 capturing the source `PRAGMA data_version`, so a
follow-up call without `--force` can smart-skip when no commits
happened since the last backup. Mechanics worth calling out:

- `PRAGMA data_version` is connection-local with a per-connection
  cache, so the smart-skip path opens a FRESH PDO against the source
  file rather than the Laravel-managed pool — a stale cached value
  cannot mask a real write.
- `VACUUM INTO` must NOT run inside a transaction (SQLite refuses) and
  writes the destination via SQLite's `open(2)`, bypassing PHP's
  umask — the command immediately calls `Filesystem::chmod(0o600)` on
  the output to recover the secret-file convention.
- The post-VACUUM integrity check uses a SECOND fresh PDO against the
  destination file so the result is not muddied by the Laravel-pool
  connection cache.
- When `VACUUM INTO` itself throws (corrupt source), the catch arm
  bridges to the same corrupt-path failure surface the integrity-check
  branch uses: write a critical `system_alerts(backup_corrupt)` row,
  leave any partial output under `.suspect`, return `FAILURE`.

`RestoreDatabaseCommand` restores the live SQLite database from a
backup file with three load-bearing safety rails:

1. **Maintenance mode**: refuses to run unless the app is already down
   OR `--force-maintenance` is passed — maintenance mode short-circuits
   HTTP traffic AND pauses Horizon workers, both required so the swap
   happens against a quiet system.
2. **Explicit `--confirm` flag**: non-TTY callers (CI, scripts, agents)
   never proceed without it; interactive TTY sessions get a y/N prompt
   defaulting to "no".
3. **Pre-swap `PRAGMA integrity_check`** on the source file via a fresh
   PDO — catching a corrupt source BEFORE the swap is the load-bearing
   safety property; a post-swap discovery would leave the user in a
   half-broken state.

Before the file copy, the command automatically writes a `VACUUM
INTO`-driven snapshot of the CURRENT live DB to a
`pre-restore-YYYY-MM-DD-HHMMSS.sqlite` file at chmod 0600 — exempt from
the retention pruner (`BackupRetentionPolicy` passes the `pre-restore-*`
prefix through unchanged). After the swap, `PRAGMA integrity_check`
runs once more via the framework's `DatabaseManager::connection()` (NOT
a fresh PDO) so `SqliteOptimizationsProvider`'s `ConnectionEstablished`
listener fires against the swapped-in file and re-applies
`journal_mode=WAL` + `synchronous=NORMAL`. A failure here records a
critical `system_alerts(backup_corrupt)` row, keeps maintenance mode
ON, leaves the pre-restore snapshot on disk, and returns `FAILURE` so
the operator can inspect and restore from the snapshot if needed.

Both commands are fully constructor-DI'd (no Laravel facade) and share
`UserDataPathService` for the backups directory, so the contract is
uniform across the pair.

`BackupRetentionPolicy` is the pure-logic policy for the retention
sweep that runs at the end of a successful `db:backup`: keep every
basename that does NOT match the `beatrax-YYYY-MM-DD-HHMMSS.sqlite`
shape (`.suspect`, `pre-restore-*`, `.meta.json`, and any
operator-dropped artifact pass through verbatim); from the matching
basenames, keep the 7 most-recent by parsed date+time AND the 4
most-recent whose parsed date falls on a Sunday; everything else is
omitted from the return value for the caller to delete. The class is
intentionally pure (no `Filesystem`, no I/O, no globbing) — the
consuming `BackupDatabaseCommand` reads the directory listing, hands
the basenames in, then deletes the omitted entries, keeping the policy
fully unit-testable and the command's I/O surface narrow.

At-rest encryption migration (`EncryptionMigrationService`):

The one-time, backup-first, atomic, rollback-on-failure migration that
turns EXISTING plaintext history into GDK ciphertext the moment
encryption is first enabled for a user (sync-enable OR single-device
opt-in). The at-rest guarantee must hold for history retained forever,
not just new writes going forward (already covered by the op-log write
hook and the direct-write hook) — this service closes the gap for rows
written BEFORE encryption was ever turned on.

- **Module boundary**: `GdkKeyringService`, `OpLogFieldCrypto`, and
  `SensitiveFieldRegistry` (all `Modules\Sync\Internal\Crypto\*`) are
  this service's real contract, but the PHPStan boundary rule forbids
  Core from importing anything outside `Modules\Sync\Public\*`. The
  minimal Public wrapper `EncryptionMigrationSupport` closes the gap:
  every raw GDK key byte and the `GdkEpoch` DTO stay fully inside Sync;
  this service only ever sees plain integers (epoch ids) and
  ciphertext strings.
- **Atomicity**: the WHOLE encrypt pass (epoch generation + every
  op-log/projection row write) runs inside ONE outer
  `DatabaseManager::transaction()` — on ANY throw, Laravel rolls back
  every DB row this pass touched (including `current_epoch`/
  `migration_in_progress`), automatically and atomically. Rows still
  process in bounded `CHUNK_SIZE` batches (via `chunkById`) so memory
  stays bounded even though the SQL transaction spans the whole pass.
- **The file-vs-DB atomicity window**: the DB rollback covers every
  ROW, but the epoch-1 GDK keyring is also a FILESYSTEM write — a side
  effect a SQL transaction cannot roll back. Epoch generation therefore
  splits into `stageFirstEpoch()` (writes `current_epoch` inside the
  transaction, encrypts the keyring to a `.tmp` sibling but does NOT
  rename it) and `finalizeStagedEpoch()` (the rename-into-place),
  called only AFTER the transaction returns successfully. On failure,
  `discardStagedEpoch()` deletes the orphaned `.tmp` — best-effort
  hygiene, since the DB rollback already nulls `current_epoch`.
- **Backup-first**: a pre-migration `BackupEncryptor` snapshot is taken
  BEFORE the transaction even opens — before the GDK epoch is even
  generated, the most conservative reading of "no row is ever touched
  before the safety net exists". On a forced failure, the snapshot is
  decrypted and its captured pre-migration values are written back —
  defense-in-depth on top of (not instead of) the transaction rollback.
- **Row-level idempotency**: `op_log_entries` rows are skipped once
  `gdk_epoch` is non-null. Projection columns have no such column, so
  each candidate value is first tried against
  `alreadyEncryptedProjectionValue()` — a real AEAD verification under
  the current epoch, not a base64-pattern guess — so a value that
  already verifies is left untouched.
- **App-lock-locked handling**: `migrate()` requires the app-lock KEK.
  When unavailable, NO row is touched — not a "forced failure requiring
  rollback" (nothing was started) — so `migrate()` clears any stale
  `migration_in_progress` flag and returns quietly rather than throwing.
- Intentionally NOT `final` — `EncryptionMigrationRollbackTest`'s
  forced-failure proof subclasses this service to override
  `afterChunkProcessed()`, mirroring
  `Modules\Auth\Public\Services\AppLockKeyService`'s own precedent for
  a thin, invariant-safe subclassing seam.
- `PROJECTION_COLUMNS` scopes to the tables this migration's
  projection pass covers (transactions/counterparties/
  tax_transaction_tags/transaction_splits) — the op-log pass, which
  must cover every sensitive-field table, uses
  `EncryptionMigrationSupport::isSensitive()` (the registry passthrough)
  as its own source of truth instead. `tax_transaction_tags.note` /
  `transaction_splits.note` are covered here because their write-side
  encrypt hooks (`TagTransaction`, `SaveTransactionSplit`) only protect
  rows written after those hooks landed — this sweeps the pre-existing
  plaintext rows too.
- `takeSnapshot()` mirrors `GdkKeyringService`'s own encrypted-file
  idiom (raw KEK bytes as the "passphrase", staged plaintext locked to
  0600 before it is ever encrypted, atomic encrypt-to-real-path) — a
  targeted sensitive-column snapshot, not a whole-file SQLite copy,
  since swapping the live database file out from under the app's own
  open PDO connection mid-request would be unsafe.

Chrome & navigation (`AppSidebar`, `Dashboard`):

`AppSidebar` renders the persistent left sidebar from
`layouts/app.blade.php` within an `@auth` guard, so unauthenticated
pages never see it. Section labels group nav items into THIS MONTH /
MONEY / INGESTION / SETTINGS. The Dev block at the foot is gated
server-side on `users.is_developer` — non-developers never receive the
dashed container in the rendered HTML. Its live "Queue {N} · Worker
{N}s ago" row is driven by a `wire:poll.5s` sub-tree; the queue count
is `jobs.count()` (pending only, not `jobs + failed_jobs`) since failed
jobs already surface via the dashboard toast and `/dev/queue/failed`
tab — "Queue {N}" reads as "work waiting to be done", a distinct signal
from "work that failed". The account caption reads "developer · local"
for developers and "local" otherwise — the only place in the chrome
that reveals the caller's developer status to themselves. Active-link
highlighting reads the current path from the injected `Request` (not
the `request()` helper) to stay clean under the DI-only invariant.

`Dashboard` is the `/` landing page rendering "this period at a
glance": three KPI tiles (In/Out/Net), top-spending categories, and
recent transactions. Period navigation (`previousPeriod()`/
`nextPeriod()`/`today()`) steps by one calendar period; the Blade
exposes an Alpine.js keyboard listener (←, →, t). First-run handling
happens at the route layer (the `/` handler redirects to
`/imports/new` before this component mounts) so it's a single HTTP hop,
not a Livewire round-trip. `$periodStartStr` is a client-controlled
string, always resolved through `resolvePeriod()`, which validates the
`Y-m-d` shape and round-trips the parsed date (refusing e.g.
"2026-02-30", which Carbon happily accepts as "2026-03-02") — a
malformed value can never reach `CarbonImmutable::parse` and 500 the
page. The failed-chain-resolution toast reads `chain_resolution_runs`
filtered by exact `user_id` match (never a substring `LIKE` against
`failed_jobs.payload`, which would leak across users with id prefixes
like 1 vs 11) and is gated on `$isDeveloper` — non-developers see
nothing there; their channel is `SystemAlertsBanner`. The reauth toast
mirrors a session-scoped dismiss flag (`reauth_toast_dismissed_at`) so
a refresh in the same tab keeps it hidden, but a fresh login resets it.

Middleware (`LoopbackOnly`, `NoStoreFinancialData`):

`LoopbackOnly` refuses any request whose `SERVER_ADDR` is not a
loopback address, throwing a 404 `NotFoundHttpException` so the app
never advertises its existence to non-loopback callers. Requests
without `SERVER_ADDR` pass through — this is what keeps CLI
invocations and Pest fixtures running, but it also means a production
listener MUST always set `SERVER_ADDR` for the guard to be effective
(most web servers do this by default; a custom php-fpm dispatcher may
not). Loopback detection accepts the IPv4 range 127.0.0.0/8, the IPv6
`::1`, and the IPv4-mapped-IPv6 form `::ffff:127.x.x.x` (common on
dual-stack Linux listeners and Docker bridges); comparison runs on the
binary form returned by `inet_pton` so textual representation variants
normalise correctly. `NoStoreFinancialData` adds strict no-store
cache-control headers to every response, preventing the browser from
caching authenticated finance pages where the back button could
otherwise reveal sensitive balances after sign-out.

SQLite substrate + boot listeners (`SqliteOptimizationsProvider`,
`HealthCheckListener`):

`SqliteOptimizationsProvider` applies the WAL + `synchronous=NORMAL` +
`busy_timeout` + foreign-key pragmas to every newly opened SQLite
connection via Laravel's `ConnectionEstablished` event (skips
non-sqlite connections). `HealthCheckListener` is the boot-time PRAGMA
drift detector: on the FIRST `sqlite` `ConnectionEstablished` event in
the process, it reads `PRAGMA journal_mode`/`PRAGMA synchronous`, and
if either drifts from the documented defaults (WAL + synchronous=1),
writes a system-wide warning `system_alerts` row AND emits a
structured log warning. Two-layer de-duplication: `BootProbeState` (a
container singleton) gates re-firing within the same process, and a
1-hour recency window against existing unacknowledged rows suppresses
cross-process duplicates, so booting the app 100x in an hour produces
at most one row per kind. The listener NEVER throws — every IO/SQL
touchpoint sits inside try/catch, since a misconfigured PRAGMA must not
lock the user out of the app (the persistent banner is the recovery
path). It lives as a dedicated invokable class (constructor-DI'd, not a
closure) so `HealthCheckServiceProvider::boot()` shrinks to a single
`$events->listen()` call and the listener is testable in isolation.
The `system_alerts` write's recency check uses the raw Query Builder
(not Eloquent) since larastan-strict-rules rejects chained
`Eloquent\Builder` calls after `Model::query()`; the write itself uses
Eloquent so timestamp casts + fillable filtering apply, and every step
is wrapped in try/catch so a write failure logs and continues.

Models (`SystemAlert`, `User`, `UserPreference`) and
`AcknowledgeSystemAlert`:

`SystemAlert` models one operational-failure event surfaced via the
persistent dashboard banner. Severity (`info`/`warning`/`critical`) is
schema-trigger-enforced, so the Eloquent cast map is purely
informational. Rows are never deleted; acknowledging stamps
`acknowledged_at` so the audit trail accumulates forever.
`user_id` is nullable — NULL means a system-wide alert visible to
every authenticated user (e.g. a SQLite PRAGMA drift); the
`BelongsToUser` per-user global scope is widened at the read-service
layer with `orWhereNull('user_id')` for the system-wide carve-out.
`$timestamps` is disabled since rows write their own `created_at` via
a SQLite `useCurrent()` default and never update once acknowledged
(a separate `acknowledged_at` column tracks that transition).
`AcknowledgeSystemAlert` is the sole write path: lookup is scoped to
the caller's own rows OR system-wide rows, so a user can dismiss a
PRAGMA-drift banner but not silence another user's private
corrupt-backup alert — a cross-user attempt raises
`NotFoundHttpException` to avoid disclosing row existence. It is
idempotent (an already-acknowledged row returns unchanged, so a
double-click or stale batched request never re-stamps) and wraps the
write in a transaction for row-level atomicity, even though SQLite's
single-writer model already serialises writers.

`User` is the authentication entity; the login identity is `username`.
`is_developer` gates the in-app developer console; when
`force_password_change_at_next_login` is set, a request middleware
redirects to the change-password page on every authenticated route
until the password is replaced.

`UserPreference` models the full set of per-user preferences in one
row per user (`(user_id)` UNIQUE at the DB boundary makes the
one-row-per-user invariant impossible to violate from any call site).
The `$fillable` list grows as domain modules ship additive
column-add migrations against this table — each consuming module's
column lands in `$fillable` here so the Eloquent mass-assignable
surface stays the single canonical write path. Current columns beyond
`user_id`: `counterparty_index_view`/`reports_index_view` (index view
mode, `cards`/`list`, default materialises at the DB boundary),
`skipped_update_versions` (JSON list of dismissed release versions
`SystemAlertsBanner` reads for its suppression filter),
`calendar_entries_accounts`/`calendar_balance_accounts` (JSON account-id
arrays for the `/calendar` grid, null = a documented spendable default,
resolved at `CalendarQuery` read time).

Settings page (`SettingsPage`):

Surfaces the user preferences that govern global dashboard behaviour.
Every property maps to a `users` column and validates via a Livewire
`#[Validate]` attribute:

- `defaultCurrencyView` — default `/transactions`/dashboard
  presentation (EUR-only settled pair vs. Original currency native
  pair + settled secondary line); the per-page `?currency=` toggle
  overrides this default, which lives on the `users` row so it
  survives across sessions and devices.
- `periodStartDay` — day of month the "this period" window rolls
  over, numbered 1..28 so every calendar month including February has
  a valid value (salary-aligned users typically pick 25).
- `recurringDetectionWindowMonths` — months of history the
  recurring-series detector scans per sweep; lower bound of 2 keeps
  monthly detection viable (the detector's `MIN_OCCURRENCES` gate is
  2), upper bound of 60 caps sweep cost.
- `recurringIncomeMinAmountMinor` — incomes below this signed BIGINT
  minor-unit threshold are not auto-clustered into income series (0
  disables it); upper bound caps unrealistic inputs at €1,000,000.00.
- `driftAlertThresholdPercent` — global default; `DriftEvaluator`
  resolves a per-series override first, falling back to this value,
  then to the hard 5% default. Allowed values mirror the six popover
  options used elsewhere in the UI.
- `baseCurrency` — ISO 4217 reporting currency for all roll-ups,
  validated against the seeded currencies table so arbitrary codes can
  never reach the DB.
- `autoImportFromDropFolder` — when on, `ScanInboxDropFolderJob` runs
  every 5 minutes importing `.eml`/`.mbox` files from
  `storage/app/inbox-drop/{userId}/` through the same matcher pipeline
  as the wizard upload path; off by default so the wizard remains the
  documented primary entrypoint.
- `theme` — one of `light`/`dark`/`system` governing the `<html>`
  dark-mode class; instant-apply via `setTheme()`.
- `isDeveloper` — gates the in-app Developer Console; instant-apply
  via `setDevMode()`, writing `users.is_developer` directly through the
  Eloquent model. Per-user; partner accounts cannot toggle each other's
  flag because the writer always scopes to `CurrentUser`.
- `fxOnlineEnabled` — opt-in online exchange-rate fetch, off by default
  since outbound fetch requires explicit user consent; instant-apply
  via `toggleFxOnline()`.
- `refreshFxRates()` — dispatches `FetchFxRatesJob` on demand through
  `DispatchFxRatesRefresh` (the FX module's Public action), so this
  Core component never reaches into FX's Internal namespace.

Every instant-apply toggle (`setTheme`, `setDevMode`,
`toggleAutoImport`, `toggleFxOnline`) writes via the raw query builder
or Eloquent model directly (single round-trip, no Save button),
mirroring each other's "no Save button" posture; `save()` batches the
remaining Save-button-gated fields. Service collaborators arrive as
`render()`/action method parameters throughout, since the Livewire
strict-rules ruleset forbids constructor DI on Component subclasses;
the authenticated user is read exclusively from `CurrentUser`, never a
request-supplied `user_id`, so cross-user writes are structurally
impossible. `messages()` returns one calm sentence per field regardless
of which validation sub-rule (`required`/`integer`/`min`/`max`) fired.

Data & backup UI (`EncryptedBackupDownload`, `EncryptedBackupRestore`,
`HelpDataLocations`, `NetWorthCard`, `SpendingTrendCard`):

`EncryptedBackupDownload` (Settings → Data & backup) snapshots the
live SQLite DB via `VACUUM INTO`, encrypts it in place with
`BackupEncryptor`, and streams it as a download; the passphrase never
persists and is the only thing that can decrypt the file. The
plaintext snapshot stages inside a private 0700 directory under app
storage (never `sys_get_temp_dir()`, which is world-traversable) and
is chmod 0600'd as defense-in-depth on top of the directory permission;
the plaintext is unlinked unconditionally in a `finally` block.
SQLite-only — hidden on a server (Postgres/MySQL) build.
`EncryptedBackupRestore` mirrors it in reverse: the destructive swap
sits behind uploading the file, entering the passphrase, AND typing
the literal confirmation phrase; `RestoreEncryptedBackup` only swaps
the live DB after the upload decrypts and passes an integrity check,
taking a pre-restore snapshot first.

`HelpDataLocations` (`/help/data-locations`) makes the local-only
privacy promise tangible by surfacing the three load-bearing on-disk
paths (SQLite database, OAuth secrets, framework caches) via
`UserDataPathService`. Section 2 ("Export everything") branches on
`is_developer`: developers see the Dev Mode export CTA; everyone else
reads instructions for enabling Dev Mode or copying folders manually.

`NetWorthCard` and `SpendingTrendCard` are simple read-only dashboard
tiles (net worth breakdown; month-over-month spend comparison) that
render nothing until there is data to show. All Livewire components in
this module take service collaborators as `render()` method parameters
rather than constructor injection, since phpstan-strict-rules bans
constructor DI on Livewire `Component` subclasses.

The system-alerts banner:

```
Layout renders
  → SystemAlertsBanner Livewire SFC
      → SystemAlertQuery::activeFor($currentUser)
      → render kind-specific message + acknowledge button
      → on click → AcknowledgeSystemAlert($alertId, $user)
            → write acknowledged_at (sole sanctioned writer)
```

`SystemAlertsBanner` sits at the top of every authenticated page via
the `@livewire('core.system-alerts-banner')` slot in `layouts/app.blade.php`,
stacking severity-first (critical → warning → info, chronological
tie-break inside each tier — locked by `SystemAlertQuery::active`).
Suppression rule for the auto-update kinds: an `update.available` row
whose `metadata.latestVersion` is already in the user's
`user_preferences.skipped_update_versions` array is filtered out at
render time; `update.stale` and `update.critical` rows deliberately
bypass the skip list, since their threat models (long-unsupported
version, security-fix critical update) override an earlier dismissal.
The component holds zero properties so it stays stateless across
re-renders — Livewire re-runs `render()` after every action, so the
post-acknowledge view automatically drops the dismissed row.
