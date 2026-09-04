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
    `SystemClock`; tests freeze time either by binding a PHPUnit stub of
    this interface or with `CarbonImmutable::setTestNow()`. There is no
    `FrozenClock` class.
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
    Subscriptions, Imports, Tax). Recurring counts ACTIVE series
    (`approved` + `cadence_changed`); Drift counts open alerts plus
    snoozed ones whose deadline has passed, matching the list `/drift`
    itself renders. The badges a module owns end-to-end — Anomaly,
    Notifications, Chains, Forecast, Inboxes — come from that module's
    own sidebar composer instead. The sidebar renders on every authenticated
    page, so the whole set is computed once and CACHED per user (short
    TTL) rather than running a COUNT per item per render. Invalidation is
    NOT the writing module's job — that contract was written down and then
    honoured by one module out of eight, so seven badges sat five minutes
    behind the reader's own action. `Internal/Listeners/
    ForgetNavCountsOnWrite` watches `QueryExecuted` instead, and bumps a
    generation counter folded into every cache key whenever an insert,
    update or delete names one of the tables in `NavCountsService::TABLES`.
    Core may not import the eight modules that own those tables, and a
    statement is the one thing all of them produce whether they write
    through Eloquent or the query builder. `forget()` survives as a
    per-user drop for a caller that wants one. Counts
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
  banner expects. A system-wide row is additionally filtered against
  `system_alert_acknowledgements` for the reader being asked about;
  `active(null)` skips that filter, because a background probe asks
  whether the fault is still open and nobody's dismissal answers that.
- **Actions/**
  - `AcknowledgeSystemAlert` — single sanctioned write path for
    acknowledgement, and the two shapes it takes. An OWNED row is stamped
    on `system_alerts.acknowledged_at`, because one person is all it was
    ever addressed to. A SYSTEM-WIDE row (`user_id IS NULL`) is never
    stamped: it is one row every member of the household sees, so a row
    stamp let either member take a WAL-mode or PRAGMA-drift warning off
    the other's screen permanently. Those get a per-reader row in
    `system_alert_acknowledgements` instead, which also leaves the shared
    row un-acknowledged for the probes' own "is this fault already
    raised" check. Nothing about a system-wide dismissal is put on the op
    log — the peer never received the alert.
- **Bootstrap/**
  - `EnsureAppKey` — first-launch APP_KEY regeneration with
    sentinel-driven idempotency. Bound into the NativePHP first-launch
    chain by [`Desktop`](../desktop/architecture.md).
- **Controllers/**
  - `HealthController` — the auth-free `/health` endpoint. Returns
    `{status, app_version, php_version, sqlite_version}` — a flat
    JSON object with deterministic key order, no timestamp. The reading
    itself is `Internal/Support/RuntimeHealthSnapshot`, because the
    version probe opens a connection and a controller does not
    ([a controller hands the work to an action](../../conventions/a-controller-hands-the-work-to-an-action.md)).
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
  - `QueryFailure::isUniqueViolation()` — the shared "was this write
    refused because the row is already there?" seam, read by eight
    actions across six modules that answer it by carrying on. It cannot
    be decided from the SQLSTATE: SQLite answers UNIQUE, FOREIGN KEY,
    NOT NULL and CHECK alike with 23000 and driver code 19, so treating
    23000 as a duplicate reported a write that did NOT happen as an
    idempotent no-op. It reads the DRIVER's own sentence
    (`PDOException::$errorInfo[2]`) — not the exception message, to which
    Laravel appends the statement and its bindings, where a stored value
    quoting the driver would classify its own failed write. Postgres is
    the one driver whose SQLSTATE already distinguishes them (23505).
  - `SafeTrace` — assembles a trace from `Throwable::getTrace()` frame by
    frame, rewriting every absolute filesystem path to a project-root-
    relative one (raw traces leak the developer's home-directory layout
    to any surface that can be screen-shared, e.g. the in-app
    `/dev/logs` viewer — ASVS V7 Errors & Logging), and truncating to a
    configurable line count so a deep recursion cannot flood the log.
    It does **not** render `getTraceAsString()`, which is where the
    frames' arguments live: with `zend.exception_ignore_args` Off — the
    interpreter's default, and the value every bundled runtime shipped —
    that string carries the first 15 characters of every string
    argument, so a parse frame put a row of the reader's bank statement
    into the 0644 daily log, on the same line as the
    `SafeExceptionContext` that exists to keep it out. The directive is
    now also set in `NativeAppServiceProvider::phpIni()`, in the two
    mobile shells' generated `php.ini`, and in `tools/test-php-ini`; the
    code holds without any of them, because desktop, mobile and CI do
    not share one `php.ini`.
- **Events/**
  - `UserInstalled` — dispatched by `Modules\Auth\Public\Actions\SignupAction`
    after a successful install AND by `beatrax:install` on every re-run,
    AND by `MobilePairingScan::abandonImport()` when a phone gives up on
    joining another device's account. Listeners (default-category-tree
    seeder, community-corpus seeder, wizard first-step priming, the Tax
    deduction-corpus seeder for the country already stored) MUST be
    idempotent. `seedsStarterData: false` marks an install that is
    JOINING an existing account and will receive that data from a peer.
- **Exceptions/**
  - `NotAuthenticatedException` — thrown by `CurrentUserService::resolveUser()`
    when no guard is bound. Maps to a 401 / redirect at the request
    boundary.
- **Dto/**
  - `UpdateManifestDto` — typed shape of the verified update manifest.
- **Navigation/**
  - `Destination` — the one enum naming every screen a user can be sent to,
    with the route name and params behind each. It sits in the kernel rather
    than beside the sidebar that renders it because every module already
    depends on `Core`, so a feature module linking to another module's screen
    gains no new edge; the chrome around a destination stays in
    [`Shell`](../shell/architecture.md). See
    [Navigation destinations](../../architecture/navigation-destinations.md).

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
- `Clock::now()` — the read-everywhere wall clock. Production binds
  `SystemClock`; a test that needs a fixed instant binds a stub of the
  interface or calls `CarbonImmutable::setTestNow()`.
- `UserCountry` — the only reader and writer of `users.country_code`.
  `current(int $userId): string` returns `''` for an unset preference —
  a real answer meaning "every region", not a missing one — and
  `store()` gates the code through the `Country` enum before writing,
  so an injected value is dropped rather than persisted. `options()`
  returns the label map all four pickers render — signup, the phone's
  import screen, Settings and the onboarding step — sorted through
  `LocaleCollator` rather than by
  ISO code. They render it through one `x-core::country-options`, so the
  empty option is named once; only whether it stays choosable differs,
  and that follows whether the surface accepts an empty submission.
- `Country` — the allow-list, and the one place a country code is
  modelled. Every seam that accepts one narrows through
  `Country::tryFrom` before use, including the corpus file path.
- `UserCountryChanged` — raised by `UserCountry::store()` so a module
  can react without the writer knowing it exists. Tax listens for it
  and seeds that country's deduction categories; nothing else in Core
  knows Tax is there. This is what lets signup, Settings and the
  onboarding `CountryStep` all set a country through one seam. It
  carries `seedsCountryData`, defaulting to `true` and false only for a
  device joining another device's account: `store()` writes the
  preference either way, but the country-scoped reference data behind it
  is that peer's to send. `SignupAction` passes its own
  `seedsStarterData` straight through, so the two halves of one decision
  cannot drift apart.
- `LocaleCollator::compare()` — the ordering seam for every list the
  reader scans by name. An ICU `Collator` for the active locale,
  memoised per locale because a sort asks for one n·log n times, and an
  accent-folded byte compare on the mobile build, whose ext-intl
  carries English-only ICU data. Byte comparison files every accented
  name after Z and knows no alphabet but ASCII's, so the country
  picker, both category pickers, the budget list and the cash-book
  picker all route through this rather than `strcasecmp`.
- `SafeDate::parseOrNull()` / `parseDayOrNull()` — the only sanctioned
  parse of a reader-typed date. `CarbonImmutable::parse('')` answers
  NOW rather than throwing, so a blank field books itself today;
  `parseDayOrNull()` adds the trim and `startOfDay()` a date-only form
  field wants, which two Livewire pages each held privately.
- `LocaleNegotiator` — the one seam that owns what language the reader
  gets, in three methods:
  - `resolve()` — the precedence order: the user's stored override, a
    guest's `session('locale')`, the browser's Accept-Language best
    match, then English. Every candidate is filtered through
    `Locale::isSupported()`, so a code this release no longer ships
    reads as "no answer" rather than as a language.
  - `apply()` — retargets the **application**, never the translator
    alone. `Application::setLocale()` is what Livewire replays from its
    snapshot on hydrate (after the middleware has already run), and it
    is the only call Carbon hears: `Carbon\Laravel\ServiceProvider`
    listens for `LocaleUpdated` and moves `Carbon`, `CarbonImmutable`,
    `CarbonPeriod` and `CarbonInterval` with it. Retarget the
    translator by hand and every `translatedFormat` / `isoFormat` /
    `diffForHumans` date stays in the language before the switch —
    which is why `NotificationCopyRenderer`, the one place that
    deliberately swaps the translator alone, moves Carbon by hand too.
    `Modules/Core/tests/Feature/DatesFollowTheLanguageSwitchTest.php`
    pins it at all three switchers.
  - `rememberChoice()` — writes a guest's choice to `session('locale')`,
    where `SetLocale` looks for it. The POST route and the signup
    page's Livewire control both go through it.

  `SYSTEM` is the value both switchers use to *name* the absence of an
  override — Settings stores NULL for it, `rememberChoice()` clears the
  session key — because the translator only ever reports a concrete
  locale and cannot distinguish "English chosen" from "nothing chosen".

The country is captured beside the language on both screens that create
an account, not inside Tax: `SignupPage::$country` on `/signup`, and
`MobileImportBootstrap::$country` on `/mobile/import`, the screen a
phone uses to join another device's account. Each passes it to
`SignupAction`, which stores it through the same `UserCountry::store()`
the Settings and wizard pickers use, so a fresh install starts
correctly classified. The import screen has to ask in its own right:
`users` is not a synced table, so a country skipped there is never
supplied by the device being joined. Skipping it is a real answer and
leaves it unset.

The import screen stores the country and nothing else. It signs up with
`seedsStarterData: false`, and `SignupAction` hands that same value to
`UserCountry::store()` as `seedsCountryData`, because the reference data
a country implies lands on tables that DO sync — and a row this device
seeds is one the peer's own row can no longer land beside. See
`.docs/features/tax/architecture.md`.

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
    inside the sandbox with no dedicated mobile branch. The private
    `platformSignal()` (`NATIVEPHP_PLATFORM` via `$_SERVER`, `$_ENV`,
    `getenv()`) is the primary on-device signal — the public
    `platform()` narrows it to a `MobilePlatform` case for callers that
    branch on *which* shell, while `isMobileRuntime()` keeps reading the
    raw signal so an unmodelled shell still counts as mobile — but it
    is NOT reliably visible at per-request config-load in
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
      → RuntimeHealthSnapshot
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

### The Argon2id parameters a backup header may ask for

Those two header fields are attacker-controlled: they are read out of an
unauthenticated header, before any key exists to authenticate it with, and
handed straight to a key derivation that allocates whatever they name. The
allocation is libsodium's, outside PHP's `memory_limit`, so nothing else in
the process bounds it.

The range check used to admit anything libsodium itself would run —
`opslimit` up to 10 and `memlimit` up to `SENSITIVE`. Measured, that ceiling
is **12.3 s and 1 GiB** for a single header. A phone's whole PHP budget is
128 MB and the desktop serves one request at a time, so a file asking for it
is a minute-scale outage on one machine and an out-of-memory kill on the
other — before a single byte of the passphrase has been checked.

The bound is now what this application can *write*, not what libsodium can
run: `encrypt()` uses `MODERATE` (0.75 s, 256 MiB) and `encryptWithKey()` uses
`(1, 8 KiB)`, and `encryptWithParams()` is private, so those two are the only
headers Beatrax has ever produced. A header past `MODERATE` was not written
here, and is refused as a format error rather than derived.

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
`protected` (not `private`) so `InstallLaunchdCommandTest` can
subclass - override it to capture the intended `launchctl bootstrap` target
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
check (mirrors `HealthCheckListener::recordDriftAlert` — 100
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
applies the retention sweep (`BackupRetentionPolicy`: 7 newest
dailies - 4 most-recent Sundays). Each successful run writes a `.meta.json`
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

`BackupSidecar` is its sibling for the `.meta.json` file itself:
`write()` does the umask + tmp + rename + chmod dance with every return
checked, and `recordsDigest()` answers the smart-skip question by
reading the newest sidecar in the directory. It is one class rather
than three command methods because a missing, unparseable or
unreadable sidecar has to mean "write another backup" on every one of
those paths — a wrong skip writes no backup at all — and that rule is
easier to hold in one place than to re-derive at three call sites.

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
  Each chunk's writes leave as ONE statement per batch, not one per row:
  `PreMigrationSnapshot::writeRowsById()` folds the chunk into a
  `CASE id WHEN … ELSE <column> END` update, so a column a row does not
  carry falls through untouched. `upsert()` is not usable here — SQLite
  and Postgres both check `NOT NULL` on the proposed row before the
  conflict resolves, so every non-defaulted column would have to be
  rewritten to update one. Progress is likewise reported once per chunk
  rather than once per row: the cache store is a file or a DB table on
  every driver this ships with, and the percentage is only ever read by
  a poll.
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
  open PDO connection mid-request would be unsafe. The staged payload is
  NDJSON — one `[table, row]` line appended as each `chunkById` page is
  read — so the writer never holds the whole ledger and a JSON copy of it
  at the same time, which is the shape that exhausts a phone.
  `restoreFromSnapshot()` reads it back a line at a time in lockstep,
  buffering a bounded run per table before each batched write, and keeps
  only the columns `PROJECTION_COLUMNS` (or, for the op log, `value` and
  `gdk_epoch`) names for that table — a payload that decrypts to
  something else cannot reach an unrelated column.

Toasts (`x-core::toast-host`, `DispatchesToast`):

One host, mounted by the app layout and by the dev shell, listens for the
window `toast` event. `DispatchesToast::toast()` raises a plain notice and
`toastWithUndo()` raises one with an action behind it. The undo carries
three things: the method name, its payload, and **the dispatching
component's own id** — the browser event says nothing about where it came
from, and the host is mounted by the layout rather than by the component
that raised it, so `window.Livewire.find(id)` is the only route back. The
key is `componentId`, never `component`: Livewire's own Event object uses
that name for `->to()` targeting and answers with a ComponentNotFound. The
host used to read `detail.message` and nothing else, which made every Undo
in the app a word in a sentence with a live server-side method behind it
that nothing could ever call. A module raising one must go through
`toastWithUndo()`: a call site inventing its own param names is invisible
to the host in exactly the same way.

`x-core::install-hint` has exactly two arms: a `beforeinstallprompt` capture,
which is Chromium-only, and an always-on desktop hint gated on a >=1024px
media query. An iPhone matches neither, so the card never renders there. The
component's own docblock, and a comment on the dashboard, both used to promise
an iOS Safari "Tap Share, then Add to Home Screen" branch; no such branch and
no copy for one has ever existed. Adding it is a copy job first: both strings
the card renders are written in the desktop voice ("… on your phone"), so an
iOS arm needs its own headline and instruction in all 26 locales.

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

Middleware (`LoopbackOnly`, `TrustedHostGuard`, `NoStoreFinancialData`):

`LoopbackOnly` refuses any request whose `SERVER_ADDR` is not a
loopback address, throwing a 404 `NotFoundHttpException` so the app
never advertises its existence to non-loopback callers. A request with
no `SERVER_ADDR` at all is decided by the SAPI, not by the console
check alone. The console context (CLI, queue worker, Pest fixtures)
passes, and so does a SAPI that serves without publishing a bind
address — `PhpSapi` names the two. `embed` is the mobile shell calling
into PHP in-process: no listening socket exists, nothing off the device
can reach it, and it passes unconditionally. `cli-server` publishes no
address either but does bind a real socket, and `--host=0.0.0.0` binds
every interface, so it has to answer for the peer it is talking to and
passes only when `REMOTE_ADDR` is loopback. A real HTTP SAPI (php-fpm,
mod_php) arriving without one still fails closed, so a production
listener MUST set `SERVER_ADDR` (most web servers do by default; a
custom php-fpm dispatcher may not, and there it 404s until it does).
The SAPI is a constructor parameter rather than `PHP_SAPI` read inline,
because `PHP_SAPI` is a compile-time constant and a gate no test can
drive off its own SAPI is how this one shipped able to 404 an entire
platform: on device the embed SAPI publishes no `SERVER_ADDR`, and
`runningInConsole()` had already memoised its answer at boot.
Loopback detection accepts the IPv4 range 127.0.0.0/8, the IPv6
`::1`, and the IPv4-mapped-IPv6 form `::ffff:127.x.x.x` (common on
dual-stack Linux listeners and Docker bridges); comparison runs on the
binary form returned by `inet_pton` so textual representation variants
normalise correctly. `TrustedHostGuard` is the second half of that
boundary: it validates the client-supplied `Host` against an allow-list
of the loopback names the bundled shells use plus the host baked into
`APP_URL`, 404ing anything else. LoopbackOnly alone cannot see a DNS
rebinding attack — a website that rebinds its own domain to 127.0.0.1
reaches a genuinely loopback socket — so the Host check is what refuses
the attacker-controlled domain the browser sends in that case.
`NoStoreFinancialData` adds strict no-store
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
  presentation: which amount a row prints, never which rows it lists.
  `eur_only` (labelled "Settled amount") draws the settled pair;
  `original` (labelled "Original amount") draws the native pair with
  the settled amount on a secondary line. The stored value keeps its
  `eur_only` spelling — it is what `users.default_currency_view` holds
  and what `?currency=` carries, so renaming it would reset every
  reader's choice. Settings and the transactions list write this one
  preference and take their labels from one wording per locale
  (`ledger::list.currency_eur` / `currency_original`), so the two
  surfaces cannot describe it differently. The per-page `?currency=`
  toggle overrides this default, which lives on the `users` row so it
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
- `autoImportFromDropFolder` — when on, `receipts:scan-drop-folder`
  dispatches `ScanInboxDropFolderJob` every 5 minutes on the desktop,
  importing `.eml`/`.mbox` files from `storage/app/inbox-drop/{userId}/`
  through the same matcher pipeline as the wizard upload path:
  `RecordReceipt` for the audit row, then `ReceiptLedgerBridge` for the
  canonical write, which is the half the scan used to discard. On a
  device the background runner clamps that to its fifteen-minute floor
  and the OS decides when a registered task gets a turn, so
  `AutoImportSettingsSection` renders a phone-shaped help line naming no
  interval. Off by default so the wizard remains the documented primary
  entrypoint.
- `theme` — one of `light`/`dark`/`system` governing the `<html>`
  dark-mode class; instant-apply via `setTheme()`.
- `locale` — the display language, or NULL for `LocaleNegotiator::SYSTEM`;
  instant-apply via `setLocale()`, which hands the choice to
  `LocaleNegotiator::apply()` so the words, the numbers and the dates all
  move in the same request before the page is re-requested.
- `country` — the reader's country, written through `UserCountry`;
  instant-apply via `setCountry()`. The placeholder option is `disabled`,
  because nothing in the app can put the preference back to unset.
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

Every instant-apply toggle (`setTheme`, `setLocale`, `setCountry`,
`setDevMode`, `toggleAutoImport`, `toggleFxOnline`) writes via the raw query builder
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
persists and is the only thing that can decrypt the file. On a shell
whose WebView drops what it is sent, the encrypted file goes to the OS
share sheet instead and the screen says which of the three things
happened to it — see
[a download the shell drops](../mobile/a-download-the-shell-drops.md). The
plaintext snapshot stages inside a private 0700 directory under app
storage (never `sys_get_temp_dir()`, which is world-traversable) and
is chmod 0600'd as defense-in-depth on top of the directory permission;
the plaintext is unlinked unconditionally in a `finally` block.
The archive also carries the GDK keyring, because a database whose
columns are sealed is ciphertext without it and the keys live in a file
beside the database rather than inside it —
[a backup of the database alone is a backup of ciphertext](../sync/sensitive-columns-at-rest.md#a-backup-of-the-database-alone-is-a-backup-of-ciphertext).
SQLite-only — hidden on a server (Postgres/MySQL) build.
`EncryptedBackupRestore` mirrors it in reverse: the destructive swap
sits behind uploading the file, entering the passphrase, AND typing
the literal confirmation phrase; `RestoreEncryptedBackup` only swaps
the live DB after the upload decrypts and passes an integrity check,
taking a pre-restore snapshot first, and installs the carried keyring
before the swap so a failure there leaves the live DB untouched. What the
reader is told when it refuses comes from `RestoreRefusal::forThrowable()`, one
`core::backup.errors.restore_*` line per distinct piece of advice, and never
from the exception's own message — those name phases and absolute paths, in one
language ([why](../../conventions/invariants-from-shipped-failures.md#an-exception-message-rendered-as-if-it-were-copy)).
`BackupContentsUnreadableException` exists for that mapping: a payload that
decrypts and will not open as a database wants an earlier backup, where a
`BackupFormatException` wants a different file.

Both restore paths — `RestoreEncryptedBackup` and `db:restore` — write
through `Modules\Core\Internal\Backup\LiveDatabaseTransplant`, which
drops every connection naming the live file and then copies the source's
pages INTO it via SQLite's backup API rather than over its file. A copy
landed beside a surviving `-wal` is recovered away by the next reader,
which is a restore that reports success and restores nothing.

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
tie-break inside each tier — locked by `SystemAlertQuery::active`, whose
ordering CASE binds the spellings from `SystemAlertSeverity` rather than
repeating them as SQL text).
Suppression rule for the auto-update kinds: an `update.available` row
whose `metadata.latestVersion` is already in the user's
`user_preferences.skipped_update_versions` array is filtered out at
render time; `update.stale` and `update.critical` rows deliberately
bypass the skip list, since their threat models (long-unsupported
version, security-fix critical update) override an earlier dismissal.
The component holds zero properties so it stays stateless across
re-renders — Livewire re-runs `render()` after every action, so the
post-acknowledge view automatically drops the dismissed row.
