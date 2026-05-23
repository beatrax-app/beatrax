# Phase 16: Developer Mode UI - Context

**Gathered:** 2026-05-24
**Status:** Ready for planning

<domain>
## Phase Boundary

Ship the in-app Developer Console at `/dev/*`, gated by an
`EnsureDeveloperMode` middleware that returns 404 (not 403) to non-developers.
A new `Modules/DevMode/` module hosts every page: a `/dev` overview with live
tiles, plus sub-pages for whitelisted artisan execution, dev-mode audit log,
log tailer with secret redaction, queue inspector (replaces Horizon dashboard
in shipped builds), doctor panel, env + effective-config snapshot, and a
SELECT-only SQL panel + schema viewer. A ⌘K / Ctrl+K command palette opens
from any page with fuzzy search across registered views + Dev Console
commands + named app actions. The embedded Horizon iframe remains reachable
only when both `config('app.dev_mode') === true` AND Horizon is installed
(naturally true on the developer's Herd box, naturally false in the shipped
`--no-dev` bundle per Phase 14 D-03).

Phase 16 also lands two cross-cutting concerns folded in during discussion:

1. **App-wide sidebar restructure** (16-01) — the main top-nav is replaced
   with a Linear/Notion-style sidebar across the whole app, providing the
   layout primitives the Dev Console reuses inside `/dev/*`.
2. **Full rename `diederik` → `beatrax`** (16-02) — composer package, every
   `diederik:*` artisan command, `DIEDERIK_DEV_MODE` env var, macOS bundle
   id, Herd hostname, every literal in source / tests / `.planning/` /
   READMEs / system_alerts copy. No upgrade-migration code (clean cut —
   the app is not yet in anyone's hands).

Phase 16 ships:

- A new `Modules/DevMode/` module — sole home for `/dev/*` routes, the
  artisan runner, log tailer, queue inspector, doctor / env / SQL panels,
  and the command palette host. Cross-module access through Public surfaces
  only (e.g., `DevCommandRegistry`, `NavigationRegistry`,
  `AppActionRegistry`).
- An `EnsureDeveloperMode` middleware in `Modules/DevMode/` (behavior
  identical to the existing `RequireDeveloperMiddleware`: 404 not 403).
  Aliased on `/dev/*` route group. `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`
  walks the route table and asserts the alias is applied.
- A whitelisted artisan runner with SAFE and DESTRUCTIVE tiers, declarative
  arg-form schemas, live SSE-streamed stdout/stderr, cancel-running-command,
  and concurrent runs as separate run cards.
- `spatie/laravel-activitylog ^4.12` integration writing a `dev_mode_audit`
  row per command run (+ per destructive queue action, + per SQL query).
  Retention: forever. Manual prune via `beatrax:prune-dev-audit --older-than=Nd`.
- A triple-gate for DESTRUCTIVE-tier commands and bulk queue deletes:
  Dev Mode ON + Advanced toggle ON (session-scoped) + typed-app-name modal
  (`beatrax`, exact lowercase).
- A log tailer that streams `storage/logs/laravel.log` (daily channel) via
  SSE through a Monolog redaction processor. Redaction wired in two places
  (belt + braces): on WRITE via `tap` on every channel, and on STREAM in the
  tailer pipeline. OAuth secrets scrubbed via a cached scrub-set busted by
  an Eloquent observer on `OAuthSecret` writes; plus regex patterns for
  `Authorization: Bearer …` headers and JWT shapes.
- A queue inspector at `/dev/queue` (single Livewire component, three tabs
  with their own routes: `/pending`, `/failed`, `/batches`). Per-row actions
  (delete / retry / retry-failures / cancel) + bulk select. Expandable inline
  JSON payload viewer (redacted). Replaces the shipped-build Horizon
  dashboard.
- A Doctor panel that triggers `beatrax:doctor` via the same Process / SSE /
  audit pipeline as the artisan runner; results parsed into pass/warn/fail
  rows.
- An env + effective-config snapshot page (`/dev/system`) — PHP version,
  SQLite PRAGMAs, extension list, paths, `BEATRAX_*` env (redacted),
  `APP_KEY` (redacted), NativePHP runtime, flattened `config()` tree with
  secret-suffix denylist redaction.
- A SELECT-only SQL panel (`/dev/sql`) gated by Dev Mode + Advanced toggle.
  Parse-time enforcement via `doctrine/sql-formatter` token check + a
  read-only SQLite connection (`PRAGMA query_only=1`). Hard 5-second timeout.
  Every query writes a `dev_mode_audit` row.
- A schema viewer beside the SQL panel — tables / columns / indexes / row
  count / foreign keys + click-to-browse first 100 rows.
- An embedded Horizon iframe at `/dev/horizon`, conditionally registered
  only when `config('app.dev_mode') === true` AND
  `class_exists(Laravel\Horizon\HorizonServiceProvider::class)`.
- A ⌘K / Ctrl+K command palette — Livewire 4 + Alpine + Fuse.js fuzzy
  search; sources: `NavigationRegistry`, `DevCommandRegistry` (SAFE-tier
  only, devs only), `AppActionRegistry`. Global keybind handler on the
  base layout body via Alpine `x-data`.
- A queue worker heartbeat: `Queue::looping` listener in
  `DevModeServiceProvider` writes `cache('dev_mode.queue_worker_heartbeat', now()->timestamp, ttl=60)`
  on every tick. `/dev/artisan` pre-flight + `/dev` dashboard tile read this
  key.
- Logging-channel switch from `single` to `daily` (`laravel-YYYY-MM-DD.log`).
- Dashboard's hardcoded `/horizon/failed` deep link in
  `Modules/Core/Resources/views/livewire/dashboard.blade.php` retargeted to
  `/dev/queue/failed`, gated on `$isDeveloper` so non-developers see no
  toast at all (their channel is the existing `SystemAlertsBanner`).

Phase 16 also performs targeted Phase 12 cleanup:

- **Drop the entire "Act as partner" feature.** Remove
  `Modules/Auth/Public/Actions/ImpersonateUserAction.php`,
  `EndImpersonationAction.php`,
  `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php`,
  the impersonation Blade partial, the
  `auth.impersonating.original_user_id` / `_username` session keys, the
  related Pest tests
  (`Modules/Auth/tests/Feature/ImpersonationBannerTest.php`,
  `ImpersonationActionTest.php`, the impersonation assertions in
  `CrossUserIsolationTest.php`), and any `BoundaryArchTest`
  allow-list entries that reference these files. Planner inventories the
  full sweep before execution.

Phase 16 does NOT ship:

- **Opening-balance pre-fill in Settings + the broader zero-config polish
  pass** — deferred to a NEW phase between 16 and 17 (run `/gsd-phase add`
  before planning it). Belongs to the Ledger/Accounts domain, not DevMode.
- **CI/CD axes for the renamed `beatrax:*` commands** — Phase 17 owns CI
  matrix updates; Phase 16 just renames the commands.
- **Apple notarization / code signing under the new `com.beatrax.*` bundle
  id** — Phase 17. Phase 16 sets the bundle id; Phase 17 binds the signing
  identity.
- **Auto-update plumbing** — Phase 18.

</domain>

<decisions>
## Implementation Decisions

### Phase Plan Order

- **D-01: Plan ordering inside Phase 16.**
  - `16-01-PLAN.md` — app-wide sidebar restructure + base layout polish
    (primitives the Dev Console reuses).
  - `16-02-PLAN.md` — `diederik` → `beatrax` full rename.
  - `16-03-PLAN.md` onwards — DevMode panels and the Phase 12
    impersonation-removal cleanup, in whatever sequence the planner picks.
  - This order means every DevMode plan uses the new `beatrax:*` names
    from day one.

### Module / IA

- **D-02: Module home.** New `Modules/DevMode/` mirrors the `Modules/Desktop/`
  pattern. Public surfaces: `Contracts/DevCommandRegistry`,
  `Contracts/NavigationRegistry`, `Contracts/AppActionRegistry`. Internal:
  Livewire pages, middleware, the heartbeat listener, the SSE controller,
  the Monolog redaction processor.
- **D-03: Page IA — hybrid dashboard + sub-pages.** `/dev` overview with
  live tiles (queue depth, recent unack `system_alerts`, log tail preview,
  worker heartbeat, last backup timestamp; tiles `wire:poll`) + sidebar
  sub-pages: `/dev/artisan`, `/dev/audit`, `/dev/logs`, `/dev/queue` (with
  sub-routes `/pending`, `/failed`, `/batches`), `/dev/doctor`, `/dev/sql`,
  `/dev/horizon` (dev-only), `/dev/system`.
- **D-04: Navigation chrome inside `/dev/*`.** Replaces the main top-nav
  with a Dev Console sidebar (Overview / Artisan / Audit / Logs / Queue /
  Doctor / SQL / Horizon / System / "Back to app"). `SystemAlertsBanner` +
  the native tray render globally.
- **D-05: App-wide sidebar restructure.** Replaces the main app's top-nav
  with a Linear/Notion left sidebar across every non-dev page. Provides
  the layout primitives `/dev/*` reuses. Lands as `16-01-PLAN.md`.

### Middleware + arch invariant

- **D-06: New `EnsureDeveloperMode` middleware in
  `Modules/DevMode/Internal/Http/Middleware/`.** Behavior identical to the
  existing `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware`
  (raises `NotFoundHttpException`, reads `CurrentUser` via DI — no facades).
  Self-contained ownership: DevMode owns its own gate.
- **D-07: Arch invariant.**
  `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`
  walks `Route::getRoutes()`, filters to URIs prefixed `/dev`, and asserts
  every match has the `ensureDeveloperMode` middleware alias. Failing the
  test blocks PRs.

### Rename diederik → beatrax (16-02)

- **D-08: Full scope.** Composer package name, every `diederik:*` artisan
  signature → `beatrax:*` (every `protected $signature = 'diederik:...'`
  + every `$this->artisan('diederik:...')` test call + every README /
  CHANGELOG / config mention), `DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE`
  (the `config('app.dev_mode')` key itself stays unchanged — only the
  source env var name flips), `config/nativephp.php` env pass-through list,
  macOS bundle id (`com.diederik.*` → `com.beatrax.*`), Herd hostname
  (`diederik.test` → `beatrax.test`), every literal `diederik` /
  `Diederik` in `.planning/*`, tests, `system_alerts` copy, Blade views,
  log channel name, `storage/logs/laravel.log` channel comments. The
  Phase 19 documentation work picks up any user-facing wording that
  remains after find/replace.
- **D-09: No upgrade migration code.** App is not yet in anyone's hands —
  clean cut. No detection-of-old-paths code path, no `.env` rewriter, no
  one-launch fallback. Just rename and update the developer's local
  `.env` + Herd hostname manually as part of landing the plan.
- **D-10: Typed-app-name string is `beatrax`** (exact lowercase,
  case-sensitive). Pre-rename plans that mention the string in copy
  should use `beatrax` so the find/replace in 16-02 doesn't touch them.

### Phase 12 cleanup — drop "Act as partner"

- **D-11: Remove the entire impersonation surface.** Phase 12 D-20 reserved
  the UI for Phase 16; the feature is now dropped from v2.0 entirely.
  Files / symbols to remove (planner expands the full set):
  - `Modules/Auth/Public/Actions/ImpersonateUserAction.php`
  - `Modules/Auth/Public/Actions/EndImpersonationAction.php`
  - `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php`
  - `Modules/Auth/tests/Feature/ImpersonationBannerTest.php`
  - `Modules/Auth/tests/Feature/ImpersonationActionTest.php`
  - The impersonation assertions in
    `Modules/Auth/tests/Feature/CrossUserIsolationTest.php`
  - The impersonation Blade include (`auth::partials.impersonation-banner`)
    + its usage in the base layout
  - Session keys `auth.impersonating.original_user_id` /
    `auth.impersonating.original_username` (no migration — they were never
    in production)
  - The `BoundaryArchTest::noAuthFacadeOrHelper` allow-list entry for
    `ImpersonationBannerMiddleware` (Phase 12 D-24)

### Artisan runner — whitelist + UX (16-03+)

- **D-12: SAFE tier (one-click, no triple-gate).** All names below are
  post-rename:
  - `db:backup`
  - `beatrax:doctor`
  - `beatrax:failed-jobs prune`
  - `cache:clear`
  - `route:list`
  - `config:show`
  - `view:clear`
  - `queue:retry`
  - `beatrax:rederive-fingerprints`
- **D-13: DESTRUCTIVE tier (triple-gated).** All names below are post-rename:
  - `db:restore`
  - `migrate:fresh`
  - `beatrax:reset-password`
  - `beatrax:regenerate-recovery-codes`
  - `beatrax:grant-dev` (planner verifies command exists or scopes its
    creation in Phase 12 follow-up)
  - `beatrax:install`
- **D-14: NEVER exposed in the UI** — `migrate`, `migrate:rollback`,
  `db:seed`. Use the CLI.
- **D-15: Declarative arg-form schemas.** Each command registers an args
  schema in `DevCommandRegistry`: `[name, label, type:
  text|select|file-path|boolean, validation_rules]`. The runner renders a
  Flux form from the schema. Explicit, type-safe; reflection-from-`$signature`
  was rejected because Symfony signatures lose hidden-flag and type info.
- **D-16: Streaming + cancel.** `Symfony\Component\Process\Process::start()`
  spawns the command. SSE endpoint `/dev/artisan/stream/{run_id}` flushes
  `Process->getIncrementalOutput()` chunks. Livewire 4 component subscribes
  via EventSource. Cancel → `POST /dev/artisan/cancel/{run_id}` →
  `Process->stop(grace=3, signal=SIGTERM)`. `pid` + `run_id` persisted in
  cache (database driver) so a page refresh reconnects to the live stream.
- **D-17: Concurrency.** Multiple concurrent runs allowed — each renders as
  its own SSE-streamed card with its own cancel button.
- **D-18: Command history.** `dev_mode_audit` IS the history (no separate
  table). The /dev/artisan page reads the last ~20 rows filtered to the
  current user + tier and renders them as "Recent runs" with a "Re-run"
  button that pre-fills the form from the stored args JSON.
- **D-19: Worker pre-flight check.** Pre-flight pill on `/dev/artisan` and
  the `/dev` dashboard tile reads
  `cache('dev_mode.queue_worker_heartbeat')`. Status pill: "Queue worker:
  alive (Ns ago)" or "Queue worker: NOT RUNNING". Job-dispatching commands
  show an inline warning beneath Run when the worker is dead — does NOT
  block execution.

### Triple-gate semantics

- **D-20: Advanced toggle.** Session-scoped, default OFF. Resets to OFF on
  every login + on Dev Console first-load per session. Forces a deliberate
  flip each session before any DESTRUCTIVE-tier action.
- **D-21: Typed-app-name modal.** Exactly `beatrax` (lowercase,
  case-sensitive match). Modal also requires confirming the action name
  ("You are about to run `db:restore` with `path=…`").
- **D-22: Triple-gate also applies to bulk DESTRUCTIVE queue actions** (bulk
  delete, batch cancel). Bulk retry is non-destructive → single-confirm
  modal only.

### Audit log (`dev_mode_audit`)

- **D-23: Package.** `spatie/laravel-activitylog ^4.12`. Configure the
  table name as `dev_mode_audit` (matches roadmap success criterion 2).
- **D-24: Row shape.** Fields: `command`, `args` (JSON), `tier`
  (`safe|destructive`), `caller_user_id`, `started_at`, `finished_at`,
  `exit_code`, `stdout_excerpt` (8KB cap), `error_excerpt` (8KB cap).
  Excerpts pass through the same Monolog redaction processor used by the
  log tailer before write.
- **D-25: UI.** Dedicated `/dev/audit` page with filter by
  tier/caller/command and severity coloring (rose-600 for non-zero exit
  codes, amber for `destructive` rows) + a "Recent runs" panel on
  `/dev/artisan` (last ~20 rows, current user + tier filtered, Re-run
  buttons pre-filling the form from `args`).
- **D-26: Retention.** Forever, per PROJECT.md's "full history retained
  forever" constraint. A SAFE-tier
  `beatrax:prune-dev-audit --older-than=Nd` command exists for manual
  pruning when desired.

### Log tailer + redaction (16-XX)

- **D-27: Logging channel.** Switch from `single` to `daily` —
  `storage/logs/laravel-YYYY-MM-DD.log`. The tailer concatenates today +
  yesterday. Documented in Phase 19 docs work.
- **D-28: Stream mechanism.** SSE endpoint `/dev/logs/stream` opens the
  current laravel-*.log, seeks to EOF, then polls via
  `clearstatcache()` + `fseek()` (250ms cadence). Flushes new bytes
  through the Monolog redaction processor pipeline. Livewire 4 EventSource
  consumer. Cancel = client closes EventSource. Cross-platform (no shell
  `tail`). Detects inode/size shrinkage on log rotation and re-opens from
  offset 0.
- **D-29: Redaction wiring — belt + braces.**
  1. **On write** — registered via `tap` on every channel
     (stack/single/daily) in `config/logging.php`. Secrets never hit disk;
     a forensic copy of the log file is also safe.
  2. **On stream** — re-applied in the tailer pipeline as a defense layer.
- **D-30: OAuth-secrets scrub strategy.** A singleton service holds a
  scrub-set of every decrypted secret string from `oauth_secrets`
  (`client_secret` + every string value in `tokens_blob`). Loaded at boot.
  An Eloquent model observer on `OAuthSecret` busts the cache on
  save/delete. Per log line: `foreach scrub_set as $secret →
  str_replace($secret, '[REDACTED]')`. Wraps regex patterns for
  `Authorization: Bearer …` headers and JWT-shape tokens as additional
  defense.
- **D-31: Tailer UI.** Severity multi-select (DEBUG / INFO / NOTICE /
  WARNING / ERROR / CRITICAL / ALERT / EMERGENCY) + channel/module filter
  + free-text contains-filter + 10k-line scrollback buffer + Pause/Resume
  button + click-to-expand context (±10 lines around the clicked line).

### Queue inspector + dashboard toast

- **D-32: Layout.** Single `/dev/queue` Livewire component with three tabs
  (Pending / Failed / Batches). Each tab has its own route (`/dev/queue/pending`,
  `/dev/queue/failed`, `/dev/queue/batches`) backed by the same component
  so URLs are deep-linkable. Roadmap-cited ~200-line scope.
- **D-33: Per-row actions.** Pending: delete | Failed: retry, delete |
  Batches: retry-failures, cancel, delete. Plus bulk select with bulk
  retry + bulk delete. Every action writes a `dev_mode_audit` row.
- **D-34: Bulk delete needs the triple-gate (D-22). Bulk retry is
  single-confirm only** ("Retry N jobs?").
- **D-35: Count tiles.** Three small header tiles on `/dev/queue`:
  `Pending(N) / Failed(N) / Batches(N)`. `wire:poll(5s)`. Same counts feed
  the `/dev` dashboard tile.
- **D-36: Inline JSON payload viewer.** Click row → expand inline →
  pretty-printed payload JSON, full exception/trace (for `failed_jobs`),
  attempt count, queue name, timestamps. Payload passes through the same
  redaction processor as logs before render.
- **D-37: Dashboard `/horizon/failed` link replacement.** The
  `@if ($failedChainResolutionExists)` branch in
  `Modules/Core/Resources/views/livewire/dashboard.blade.php` gains an
  `&& $isDeveloper` guard. Non-developers see nothing about failed jobs
  (their channel is `SystemAlertsBanner`). Developers see the toast
  pointing at `/dev/queue/failed`.
- **D-38: Horizon iframe gating.** Sidebar link + `/dev/horizon` route
  conditionally registered only when BOTH `config('app.dev_mode') === true`
  AND `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`. Both
  signals required; naturally false in `--no-dev` bundle since Horizon is
  `require-dev` per Phase 14 D-03.

### Worker heartbeat

- **D-39: Heartbeat producer.** A `Queue::looping` listener registered in
  `DevModeServiceProvider::boot()` writes
  `cache('dev_mode.queue_worker_heartbeat', now()->timestamp, ttl=60)` on
  every queue tick. Zero cost when no worker (the listener never fires).
  Consumed by D-19 pre-flight + the dashboard tile.

### Command palette (⌘K / Ctrl+K)

- **D-40: Tech stack.** Livewire 4 modal component triggered by a global
  Alpine `x-data` keybind handler on the base layout body. Alpine handles
  open/close + keyboard nav. Fuse.js (~12KB, single CDN-able file) for
  client-side fuzzy ranking against a JSON registry the server emits on
  mount. Calm Linear/Raycast aesthetic via Flux components.
- **D-41: Sources — three Public registries.**
  - `NavigationRegistry` — every authenticated view (Dashboard,
    Transactions, Recurring, Chains, Settings, Imports, Receipts, Email,
    Categorization, Drift Alerts, Forecasts). Non-developers see only this
    source.
  - `DevCommandRegistry` — SAFE-tier entries only (DESTRUCTIVE-tier
    excluded from the palette to prevent muscle-memory disasters).
    Developers only.
  - `AppActionRegistry` — named app actions (e.g., "Scan email now", "Run
    import", "Open backups folder"; the Phase 15 app-menu entries). Each
    source returns `[label, icon, hint, url|handler]`.
- **D-42: Keybind handler.** Single `x-data` on
  `resources/views/layouts/app.blade.php`'s `<body>` listens for keydown.
  macOS uses meta, Windows/Linux uses ctrl —
  `event.metaKey || event.ctrlKey` covers both. Dispatches an Alpine event
  the palette modal subscribes to.

### Doctor / env / SQL / schema (16-XX)

- **D-43: `/dev/doctor`.** Thin page that triggers `beatrax:doctor` via
  the same `Process::start()` + SSE machinery as the artisan runner.
  Captures streamed output, parses `DoctorCommand`'s structured probe
  output into pass/warn/fail rows. Re-run button. Single code path = a
  `dev_mode_audit` row per run, identical UX to "run from CLI".
- **D-44: `/dev/system` env snapshot fields.**
  - PHP: version, sapi, php.ini path, loaded extensions.
  - SQLite: PRAGMAs (`journal_mode`, `synchronous`, `cache_size`,
    `page_size`), DB file path (resolved via `UserDataPathService`), file
    size.
  - Laravel: version, environment, debug, locale, timezone.
  - Paths: base / app / storage / config / cache.
  - Env: `BEATRAX_*` vars (post-rename), `APP_KEY` redacted, `NATIVEPHP_*`
    vars.
  - Runtime: NativePHP version, Electron version (if available), host OS.
  - Effective config: a flattened `config()` tree with denylist-based
    secret redaction (`*password*`, `*secret*`, `*key`, `*token*` suffixes
    masked).
- **D-45: SELECT-only SQL panel parse-time guard.**
  - Tokenize input via `doctrine/sql-formatter`; assert first non-whitespace,
    non-comment token is `SELECT`.
  - Reject any statement containing a semicolon followed by non-whitespace
    (blocks `SELECT 1; INSERT …`).
  - Reject CTE forms that wrap a write (the first-token check already does
    this — `WITH …` would be rejected outright).
  - Execute via `PDO::prepare` on a connection cloned from the default
    with `PRAGMA query_only=1` set (SQLite's engine-level read-only mode).
    Hard timeout: 5 seconds (`PDO::setAttribute(PDO::ATTR_TIMEOUT, 5)`).
- **D-46: SQL panel gating.** Dev Mode ON + Advanced toggle ON. No
  typed-name modal — the parser + read-only PRAGMA are the actual guard;
  typed-name would be friction without payoff. Every query writes a
  `dev_mode_audit` row (`action: 'sql.select'`, `properties:
  { query, rowcount, duration_ms }`).
- **D-47: Schema viewer.** Sidebar list of tables (read via
  `Schema::getTables()` / `getColumns()` / `getIndexes()`). Per-table:
  column list (name, type, nullable, default), index list, FK list, total
  row count. "Browse" button runs `SELECT * FROM <table> LIMIT 100`
  through the same SQL pipeline (audit row written). Sortable column
  headers.

### Claude's Discretion

- Internal structure of `Modules/DevMode/` (Public surfaces are locked by
  D-02 / D-15 / D-41; everything else is the planner's call).
- The exact Flux component choices for the runner form, the audit table,
  the queue table, the tailer scrollback, the schema viewer, and the
  palette modal.
- The Monolog `tap` class name and implementation detail (D-29 / D-30).
- The shape of the per-command args-schema array beyond `[name, label,
  type, validation]` — additional UI hints (placeholder, help text) are
  Claude's call.
- The exact JSON shape the palette server emits to Fuse.js (label, hint,
  url/handler, source tag, icon name).
- The dev_mode_audit observer for action types other than command runs
  (sql.select, queue.bulk_delete, queue.retry, etc.) — taxonomy choice is
  Claude's, subject to the existing `system_alerts` type conventions.
- The Doctor probe output parser that turns `DoctorCommand`'s streamed
  lines into pass/warn/fail rows.
- Whether the rename plan (16-02) uses a single mass commit or a series of
  per-area commits — planner's call, but the test suite must stay green
  between each commit.
- The exact set of Phase 12 impersonation artifacts to remove beyond
  D-11's list — planner does a grep sweep.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements

- `.planning/ROADMAP.md` § "Phase 16: Developer Mode UI" — goal + 6 success
  criteria. ⚠ Note SC4's `DIEDERIK_RUNTIME=herd` wording is reinterpreted
  via Phase 14 D-02 as `config('app.dev_mode') === true` (D-38). Note the
  SC2 explicit reference to `spatie/laravel-activitylog ^4.12` (D-23).
- `.planning/REQUIREMENTS.md` §DEVUI — DEVUI-01 through DEVUI-09 (the
  nine requirements in scope).

### Project conventions

- `CLAUDE.md` — DI-only rule (constructor DI; no facades / global helpers;
  Eloquent models direct OK), modular-boundary rule (cross-module access
  via Public services/events only), quality-gate stack (Larastan L10
  strict + Pint + Pest), queue/scheduler notes.
- `.planning/PROJECT.md` — calm Linear/Notion aesthetic, local-only
  constraint, full-history-retained-forever (D-26), modular architecture,
  DI-only rule.
- `.planning/STATE.md` — current milestone position; carried-forward
  decisions.

### Prior-phase context this phase depends on

- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — D-04 (first
  signup auto-promotes to `is_developer`), D-20 / D-21 (impersonation
  back-end was reserved for Phase 16 UI — now dropped, D-11), D-24
  (`noAuthFacadeOrHelper` arch test pattern the new
  `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` extends).
- `.planning/phases/13-app-paths/13-CONTEXT.md` — D-01
  (`NATIVEPHP_STORAGE_PATH` is path resolution; the env snapshot D-44
  exposes the resolved path), `UserDataPathService` (the `/dev/system`
  page reads from it).
- `.planning/phases/14-queue-rewire-horizon-carve-out/14-CONTEXT.md` —
  D-01 / D-02 (`DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE` rename in D-08;
  the `config('app.dev_mode')` key itself stays; `DIEDERIK_RUNTIME` is
  retired and roadmap's DEVUI-08 mention of it is reinterpreted as
  `app.dev_mode`), D-03 (Horizon is `require-dev` — D-38's class_exists
  gate naturally works), D-04
  (`bootstrap/providers.php`'s `class_exists()` guard pattern).
- `.planning/phases/15-desktop-shell-nativephp-integration/15-CONTEXT.md`
  — D-05 / D-07 (bundled worker daemon — D-39's heartbeat assumes it
  ticks), D-12 / D-13 / D-14 (existing OS-notification model — Dev
  Console alerts use the same SystemAlertsBanner-first pattern), D-20
  (logo asset path `resources/brand/logo.svg`), `Modules/Desktop/`
  pattern (D-02 mirrors this structure).

### Existing code Phase 16 extends

- `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php`
  — behavior reference for D-06 (new EnsureDeveloperMode class is a clone
  in a different module so DevMode owns its own gate). Not deleted —
  RequireDeveloperMiddleware stays for the partner-management pages it
  already gates (AddUserPage, ManageUserPage).
- `Modules/Auth/Providers/AuthServiceProvider.php:82` — current
  `$router->aliasMiddleware('developer', RequireDeveloperMiddleware::class)`
  binding; D-06 adds `ensureDeveloperMode` aliased to the new class.
- `Modules/Core/Internal/Console/DoctorCommand.php` — driven by D-43.
- `Modules/Core/Internal/Console/Probes/*` — the structured probe lines
  D-43 parses.
- `Modules/Core/Internal/Console/BackupDatabaseCommand.php`,
  `RestoreDatabaseCommand.php`, `FailedJobsCommand.php`,
  `InstallCommand.php` — the SAFE / DESTRUCTIVE tier members (D-12 /
  D-13).
- `Modules/Auth/Internal/Console/ResetPasswordCommand.php`,
  `Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php` —
  more tier members.
- `app/Providers/HorizonServiceProvider.php` — already gated on
  `config('app.dev_mode')` (Phase 14 D-04); D-38's iframe gate composes
  with it.
- `Modules/Core/Public/Services/SystemAlertQuery.php` +
  `Modules/Core/Public/Actions/AcknowledgeSystemAlert.php` — the `/dev`
  overview live-tile + dashboard non-dev fallback (D-37) use these.
- `Modules/Core/Resources/views/livewire/dashboard.blade.php` line 280 —
  the hardcoded `/horizon/failed` deep link (D-37 retargets +
  `$isDeveloper`-gates).
- `Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php`
  — the non-dev fallback channel (D-37).
- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` — D-30's
  scrub-set reads decrypted secrets through it / through the `OAuthSecret`
  Eloquent model. Observer attaches in `DevModeServiceProvider::boot()`.
- `Modules/Auth/Public/Actions/ImpersonateUserAction.php`,
  `Modules/Auth/Public/Actions/EndImpersonationAction.php`,
  `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php`,
  the `Modules/Auth/tests/Feature/Impersonation*Test.php` files, and
  related Blade partial — all DELETED per D-11.
- `tests/Contracts/BoundaryArchTest.php` — host of the new
  `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` invariant
  (D-07); also where Phase 12 D-24's allow-list entry for
  `ImpersonationBannerMiddleware` lives — D-11 removes that entry.
- `bootstrap/providers.php` — `class_exists()`-guarded conditional
  registration pattern (Phase 14 D-04) reused by D-38.
- `composer.json` — adds `spatie/laravel-activitylog ^4.12` (D-23) and
  `doctrine/sql-formatter` (D-45) to `require`; adds `fuse.js` to
  frontend assets (D-40); the rename in D-08 changes `name`.
- `config/logging.php` — Monolog `tap` registration for D-29's on-write
  redaction; channel default changes from `single` to `daily` (D-27).
- `config/nativephp.php` — env pass-through list updated by D-08's rename
  (DIEDERIK_DEV_MODE → BEATRAX_DEV_MODE).
- `resources/views/layouts/app.blade.php` — `<body>` gains the Alpine
  `x-data` palette keybind handler (D-42); the impersonation Blade
  include is removed (D-11).
- `Modules/Ledger/Database/Migrations/2026_05_13_010005_create_statement_summaries_table.php`
  — referenced from `<deferred>` (the opening-balance pre-fill phase
  reads `opening_balance_minor` from this table).

### Architecture

- `ARCHITECTURE.md` — modular boundary rules, the BoundaryArchTest
  invariant the new D-07 rule extends.

No external ADRs — every decision captured above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` —
  behavior + 404-not-403 pattern. D-06 clones the behavior in
  `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php`
  (different module, identical contract). Original stays in place; it
  still gates the partner-management pages.
- `Modules/Core/Internal/Console/DoctorCommand.php` +
  `Probes/ExternalToolVersionProbe.php` (already uses `Symfony\Process`) —
  the Doctor panel (D-43) drives this command; the Process-spawn pattern
  is already in the codebase.
- `Modules/Core/Internal/Console/BackupDatabaseCommand.php`,
  `RestoreDatabaseCommand.php`, `FailedJobsCommand.php`,
  `InstallCommand.php` — concrete SAFE / DESTRUCTIVE tier commands the
  runner registers (D-12 / D-13). All under `Modules/Core/Internal/Console/`
  + analogous folders in `Modules/Auth/` and `Modules/Ledger/`.
- `Modules/Core/Public/Services/SystemAlertQuery.php` +
  `AcknowledgeSystemAlert.php` + the `system_alerts` table — drives the
  `/dev` overview alerts tile + the non-dev dashboard fallback (D-37).
- `Modules/Core/Public/Services/UserDataPathService.php` — the env
  snapshot's resolved DB-file path (D-44).
- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` +
  `Modules/EmailScan/Models/OAuthSecret.php` (model lives at the
  `oauth_secrets` table; CONTEXT-12 D-15 schema) — D-30's scrub-set
  reads decrypted secrets through the Eloquent model; the observer hooks
  here.
- `app/Providers/HorizonServiceProvider.php` — already gated on
  `config('app.dev_mode')`; the D-38 iframe gate composes with it.
- `tests/Contracts/BoundaryArchTest.php` — host for D-07's new invariant
  and D-11's allow-list removal.
- `bootstrap/providers.php` — already uses
  `class_exists()`-guarded conditional provider registration for Horizon
  (Phase 14 D-04); same pattern reused for D-38.

### Established Patterns

- DI-only: constructor injection everywhere; no facades / global helpers
  in module code. Layouts (Blade) are allowed to use `auth()` (already
  done in `resources/views/layouts/app.blade.php`). The Alpine `x-data`
  keybind handler (D-42) is template-side and doesn't conflict.
- Module Public/Internal split — `Modules/DevMode/` follows the same
  shape (Public/Contracts + Public/Services as needed, Internal for
  Livewire + middleware + listeners + Monolog processor).
- `tests/Contracts/*ArchTest.php` is load-bearing — every new boundary
  gets an arch invariant (D-07).
- Migrations live inside the owning module
  (`Modules/DevMode/Database/Migrations/` for the spatie/activitylog
  table reshape to `dev_mode_audit`).
- Conditional provider registration via `class_exists()` guard in
  `bootstrap/providers.php` (Phase 14 D-04 pattern reused).
- Tailwind v4 + Livewire 4 + Flux UI 2 + Volt — confirmed via
  `composer.json`. Dark theme primitives live in the base layout
  (Phase 15 D-15) and apply automatically.
- `SystemAlertsBanner` + `system_alerts` is the canonical user-facing
  alerts surface; D-37 keeps it as the non-dev fallback.

### Integration Points

- New `Modules/DevMode/` — sole permitted home for `/dev/*` routes and
  Dev Console UI. Arch-test enforced by D-07 (route-table walk).
- `Modules/Auth/Providers/AuthServiceProvider.php` — adds
  `ensureDeveloperMode` middleware alias (or that aliasing moves to the
  new `Modules/DevMode/Providers/DevModeServiceProvider.php` — planner's
  call).
- `bootstrap/providers.php` — registers `DevModeServiceProvider`.
- `config/logging.php` — Monolog `tap` for on-write redaction (D-29); the
  default channel switches `single` → `daily` (D-27).
- `composer.json` — adds `spatie/laravel-activitylog ^4.12` (D-23) and
  `doctrine/sql-formatter` (D-45); the rename plan (D-08) flips `name`
  and every `diederik:*` literal.
- `package.json` (or equivalent frontend manifest) — adds `fuse.js` for
  the palette (D-40).
- `resources/views/layouts/app.blade.php` — the global Alpine `x-data`
  palette keybind handler (D-42); impersonation Blade include removed
  (D-11).
- `Modules/Core/Resources/views/livewire/dashboard.blade.php` —
  `/horizon/failed` deep-link replacement + `$isDeveloper` gate (D-37).
- Dependency on Phase 15 D-05's bundled worker daemon for D-39's
  heartbeat to fire in the shipped build (the listener is registered
  unconditionally; it only runs when a worker process is active).
- Dependency on Phase 12 D-04 (`is_developer` auto-promote on first
  signup) — already shipped.
- Dependency on Phase 14 D-01 / D-02 (`config('app.dev_mode')`,
  `DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE` rename in D-08).

</code_context>

<specifics>
## Specific Ideas

- **Typed-app-name string:** `beatrax` (exact lowercase, case-sensitive).
  Pre-rename plans should use this string in copy so the 16-02 find/replace
  doesn't touch them.
- **Audit table name:** `dev_mode_audit` (matches roadmap SC2).
- **Heartbeat cache key:** `dev_mode.queue_worker_heartbeat` (TTL 60s).
- **SSE endpoint paths:** `/dev/artisan/stream/{run_id}`,
  `/dev/artisan/cancel/{run_id}`, `/dev/logs/stream`.
- **Worker pre-flight pill copy:** "Queue worker: alive (Ns ago)" /
  "Queue worker: NOT RUNNING".
- **Triple-gate modal verbatim:** "Type **beatrax** to confirm" + a row
  showing the resolved command + args.
- **Dashboard toast (developer-only after D-37) verbatim:** "Chain
  resolution failed." with action "Open Queue Inspector" →
  `/dev/queue/failed`.
- **Command palette empty-state:** "Type to search views, commands, and
  actions. Press Esc to close."
- **`/dev` overview tiles, verbatim:** "Queue", "Recent alerts", "Log
  tail", "Worker", "Last backup".
- **Logging channel:** `daily` (rotating `laravel-YYYY-MM-DD.log`).
- **SQL panel timeout:** 5 seconds.
- **Schema viewer browse-limit:** 100 rows.
- **Sidebar entries (devs, inside `/dev/*`):** Overview / Artisan /
  Audit / Logs / Queue / Doctor / SQL / Horizon (conditional) / System /
  Back to app.

</specifics>

<deferred>
## Deferred Ideas

- **Opening-balance pre-fill in Settings** — a NEW phase between 16 and
  17. Scope sketch: per-account opening-balance setting in the Settings
  UI, pre-filled from `MIN(statement_summaries.opening_balance_minor)`
  for that account. Belongs to the Ledger/Accounts domain, not DevMode.
  **Action:** run `/gsd-phase add` (or equivalent) to insert it in
  ROADMAP.md before planning it.
- **Broader zero-config polish pass** — same new phase as above. Sweep
  every Settings field with no sensible default and pre-fill where
  possible (e.g., default categorization rules, default currency view).
- **Apple notarization / code signing under the new `com.beatrax.*`
  bundle id** — Phase 17 owns this. Phase 16 sets the bundle id; Phase 17
  binds the signing identity.
- **CI matrix updates for renamed `beatrax:*` commands** — Phase 17. The
  rename plan (16-02) only flips the source code; CI workflow files that
  call `php artisan diederik:doctor` etc. are Phase 17's update.
- **Public README + onboarding docs reflecting the rename** — Phase 19
  (Public Release Boundary).
- **`laravel/pulse` (TELE-03)** — already noted in Phase 14's deferred
  list. Phase 16 ships the bespoke queue inspector that makes Pulse
  redundant for the shipped build.
- **Sentry / crash reporting** — Phase 21 (beta cohort), per Phase 12
  deferred list.
- **WebSockets / `laravel/reverb` for the SSE pipeline** — not needed;
  SSE is well-supported and avoids Reverb's Redis dependency.

None of the above are scope creep — they are explicit phase boundaries
or deferrals.

</deferred>

---

*Phase: 16-Developer Mode UI*
*Context gathered: 2026-05-24*
