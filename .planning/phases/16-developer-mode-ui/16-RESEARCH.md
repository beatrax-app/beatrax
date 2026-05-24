# Phase 16: Developer Mode UI - Research

**Researched:** 2026-05-24
**Domain:** Laravel 13 + Livewire 4 + Flux 2 + NativePHP bounded module — in-app developer console (route gate, command runner, log tailer, queue inspector, SQL panel, command palette)
**Confidence:** HIGH for stack/architecture, MEDIUM for SSE-vs-wire:stream choice and SQL-validator library choice, LOW where flagged

<user_constraints>
## User Constraints (from CONTEXT.md)

> Verbatim copies of locked decisions. The planner MUST honor these; research below assumes them as boundary conditions, not alternatives to explore.

### Locked Decisions (D-01 … D-47)

**Phase Plan Order**
- **D-01: Plan ordering inside Phase 16.**
  - `16-01-PLAN.md` — app-wide sidebar restructure + base layout polish (primitives the Dev Console reuses).
  - `16-02-PLAN.md` — `diederik` → `beatrax` full rename.
  - `16-03-PLAN.md` onwards — DevMode panels and the Phase 12 impersonation-removal cleanup, in whatever sequence the planner picks.
  - This order means every DevMode plan uses the new `beatrax:*` names from day one.

**Module / IA**
- **D-02: Module home.** New `Modules/DevMode/` mirrors the `Modules/Desktop/` pattern. Public surfaces: `Contracts/DevCommandRegistry`, `Contracts/NavigationRegistry`, `Contracts/AppActionRegistry`. Internal: Livewire pages, middleware, the heartbeat listener, the SSE controller, the Monolog redaction processor.
- **D-03: Page IA — hybrid dashboard + sub-pages.** `/dev` overview with live tiles + sidebar sub-pages: `/dev/artisan`, `/dev/audit`, `/dev/logs`, `/dev/queue` (with sub-routes `/pending`, `/failed`, `/batches`), `/dev/doctor`, `/dev/sql`, `/dev/horizon` (dev-only), `/dev/system`.
- **D-04: Navigation chrome inside `/dev/*`.** Replaces the main top-nav with a Dev Console sidebar; `SystemAlertsBanner` + native tray render globally.
- **D-05: App-wide sidebar restructure** lands as `16-01-PLAN.md`.

**Middleware + arch invariant**
- **D-06: New `EnsureDeveloperMode` middleware** in `Modules/DevMode/Internal/Http/Middleware/`. Identical behavior to existing `RequireDeveloperMiddleware` (404 not 403, reads `CurrentUser` via DI).
- **D-07: Arch invariant.** `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` walks `Route::getRoutes()`, filters `/dev`, asserts `ensureDeveloperMode` alias.

**Rename diederik → beatrax (16-02)**
- **D-08: Full scope.** Composer name, every `diederik:*` artisan signature → `beatrax:*`, `DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE` (the `config('app.dev_mode')` key stays), `config/nativephp.php` env list, macOS bundle id (`com.diederik.*` → `com.beatrax.*`), Herd hostname (`diederik.test` → `beatrax.test`), every `diederik` / `Diederik` literal in `.planning/*`, tests, `system_alerts` copy, Blade views, log channel name.
- **D-09: No upgrade migration code.** Clean cut.
- **D-10: Typed-app-name string is `beatrax`** (exact lowercase, case-sensitive).

**Phase 12 cleanup**
- **D-11: Remove the entire impersonation surface.** ImpersonateUserAction, EndImpersonationAction, ImpersonationBannerMiddleware, two Pest test files, impersonation assertions in `CrossUserIsolationTest`, impersonation Blade include, session keys `auth.impersonating.original_user_id`/`_username`, BoundaryArchTest allow-list entry for `ImpersonationBannerMiddleware`.

**Artisan runner**
- **D-12: SAFE tier** (one-click): `db:backup`, `beatrax:doctor`, `beatrax:failed-jobs prune`, `cache:clear`, `route:list`, `config:show`, `view:clear`, `queue:retry`, `beatrax:rederive-fingerprints`.
- **D-13: DESTRUCTIVE tier** (triple-gated): `db:restore`, `migrate:fresh`, `beatrax:reset-password`, `beatrax:regenerate-recovery-codes`, `beatrax:grant-dev`, `beatrax:install`.
- **D-14: NEVER exposed:** `migrate`, `migrate:rollback`, `db:seed`.
- **D-15: Declarative arg-form schemas** `[name, label, type: text|select|file-path|boolean, validation_rules]`.
- **D-16: Streaming + cancel.** `Symfony\Component\Process\Process::start()`; SSE `/dev/artisan/stream/{run_id}` flushes `getIncrementalOutput()`; Livewire 4 component via EventSource; cancel → `POST /dev/artisan/cancel/{run_id}` → `Process->stop(grace=3, signal=SIGTERM)`; `pid` + `run_id` persisted in cache (database driver).
- **D-17: Concurrency** — multiple concurrent runs, each its own SSE card.
- **D-18: Command history** = `dev_mode_audit` rows (no separate table); "Recent runs" panel pre-fills form from stored args JSON.
- **D-19: Worker pre-flight pill** reads `cache('dev_mode.queue_worker_heartbeat')`.

**Triple-gate**
- **D-20: Advanced toggle** — session-scoped, default OFF, resets on login + first Dev Console load.
- **D-21: Typed-app-name modal** — `beatrax` exact lowercase, plus action-name confirm row.
- **D-22: Triple-gate applies to bulk DESTRUCTIVE queue actions** (bulk delete, batch cancel). Bulk retry = single-confirm.

**Audit log**
- **D-23: Package** — `spatie/laravel-activitylog ^4.12`, table renamed to `dev_mode_audit`.
- **D-24: Row shape** — `command`, `args` (JSON), `tier`, `caller_user_id`, `started_at`, `finished_at`, `exit_code`, `stdout_excerpt` (8KB cap), `error_excerpt` (8KB cap). Excerpts redacted before write.
- **D-25: UI** — `/dev/audit` filter page + "Recent runs" panel on `/dev/artisan`.
- **D-26: Retention** — forever; `beatrax:prune-dev-audit --older-than=Nd` for manual pruning.

**Log tailer + redaction**
- **D-27: Logging channel** — `single` → `daily`.
- **D-28: Stream mechanism** — SSE `/dev/logs/stream`; `clearstatcache()` + `fseek()` at 250ms; inode/size shrinkage detection for rotation.
- **D-29: Redaction wiring — belt + braces** — on-write via `tap` on every channel + on-stream in tailer pipeline.
- **D-30: OAuth-secrets scrub strategy** — singleton holds scrub-set (`client_secret` + every value in `tokens_blob`); Eloquent observer on `OAuthSecret` busts cache on save/delete; per-line `str_replace` over the set; regex for `Authorization: Bearer …` + JWT shape.
- **D-31: Tailer UI** — severity multi-select, channel filter, contains-filter, 10k-line scrollback, Pause/Resume, click-to-expand ±10 lines context.

**Queue inspector**
- **D-32: Layout** — single `/dev/queue` Livewire with three sub-routes; ~200-line scope.
- **D-33: Per-row actions** — Pending: delete; Failed: retry, delete; Batches: retry-failures, cancel, delete. Bulk retry + bulk delete.
- **D-34: Bulk delete triple-gate; bulk retry single-confirm.**
- **D-35: Count tiles** with `wire:poll(5s)`.
- **D-36: Inline JSON payload viewer** — expand inline; payload redacted.
- **D-37: Dashboard `/horizon/failed` link replacement** — gated `&& $isDeveloper`; retargets `/dev/queue/failed`.
- **D-38: Horizon iframe gating** — only when `config('app.dev_mode') === true` AND `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`.

**Worker heartbeat**
- **D-39: `Queue::looping` listener** in `DevModeServiceProvider::boot()` writes `cache('dev_mode.queue_worker_heartbeat', now()->timestamp, ttl=60)`.

**Command palette**
- **D-40: Stack** — Livewire 4 modal + Alpine `x-data` keybind handler on `<body>` + Fuse.js (~12KB) client-side fuzzy ranking against server-emitted JSON registry. Flux components for the modal/input chrome.
- **D-41: Sources** — `NavigationRegistry`, `DevCommandRegistry` (SAFE only, devs only), `AppActionRegistry`. Each row: `[label, icon, hint, url|handler]`.
- **D-42: Keybind handler** — single `x-data` on `<body>`; `event.metaKey || event.ctrlKey` for macOS + Win/Linux parity.

**Doctor / env / SQL / schema**
- **D-43: `/dev/doctor`** — triggers `beatrax:doctor` via `Process::start()` + SSE; parses `DoctorCommand` probe lines into pass/warn/fail rows.
- **D-44: `/dev/system` snapshot fields** — PHP/SQLite/Laravel meta, paths, `BEATRAX_*` env (redacted), `APP_KEY` (redacted), `NATIVEPHP_*`, flattened `config()` tree, denylist redaction (`*password*` / `*secret*` / `*key` / `*token*`).
- **D-45: SELECT-only SQL panel guard** — tokenize via `doctrine/sql-formatter`; first non-whitespace, non-comment token must be `SELECT`; reject `;` followed by non-whitespace; reject CTE writes (first-token check covers it); read-only PDO connection cloned with `PRAGMA query_only=1`; `PDO::ATTR_TIMEOUT=5`.
- **D-46: SQL panel gating** — Dev Mode ON + Advanced toggle ON (no typed-name modal); every query writes `dev_mode_audit` row (`action: 'sql.select'`).
- **D-47: Schema viewer** — `Schema::getTables() / getColumns() / getIndexes()`; "Browse" runs `SELECT * FROM <table> LIMIT 100` through the SQL pipeline (audit row written).

### Claude's Discretion (from CONTEXT.md)

- Internal structure of `Modules/DevMode/` beyond locked Public surfaces.
- Exact Flux component choices for runner form, audit/queue/schema tables, tailer scrollback, palette modal.
- Monolog `tap` class name + implementation detail.
- Args-schema shape beyond `[name, label, type, validation]` (placeholders, help text).
- Palette server-emitted JSON shape (label/hint/url-handler/source/icon).
- `dev_mode_audit` action taxonomy beyond command runs (sql.select, queue.bulk_delete, queue.retry, …).
- DoctorCommand probe-line parser implementation.
- Rename plan commit shape (single mass commit vs. per-area).
- Exact Phase 12 impersonation artifacts beyond D-11's list (grep sweep).

### Deferred Ideas (OUT OF SCOPE)

- **Opening-balance pre-fill in Settings + broader zero-config polish** — NEW phase between 16 and 17.
- **CI/CD axes for the renamed `beatrax:*` commands** — Phase 17.
- **Apple notarization / code signing under `com.beatrax.*`** — Phase 17.
- **Auto-update plumbing** — Phase 18.
- **Public README + onboarding docs reflecting rename** — Phase 19.
- **`laravel/pulse` (TELE-03)** — already deferred.
- **Sentry / crash reporting** — Phase 21.
- **WebSockets / `laravel/reverb`** — explicitly rejected; SSE is sufficient.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DEVUI-01 | `User::is_developer` boolean + Settings toggle + `EnsureDeveloperMode` middleware on every dev-mode route | Q9 (`is_developer` column ALREADY exists from Phase 12; only Settings toggle UI + new middleware needed) + Q8 (`NotFoundHttpException` 404 pattern verified against existing `RequireDeveloperMiddleware`) |
| DEVUI-02 | Whitelisted SAFE-tier artisan runner with live stdout/stderr streaming, history, cancel | Q1 (Livewire 4 `wire:stream` vs SSE comparison + recommendation) + Q5 (registry pattern) + Symfony Process `start()` + `getIncrementalOutput()` + `stop(grace, signal)` |
| DEVUI-03 | DESTRUCTIVE-tier runner with triple-gating + audit log via `spatie/laravel-activitylog ^4.12` | Q13 (activitylog version Laravel-13 compatibility — flag) + locked D-20/D-21/D-22 triple-gate, D-23 audit table rename |
| DEVUI-04 | Log tailer + Monolog redaction processor scrubs Bearer, OAuth tokens, oauth_secrets values | Q3 (Monolog `ProcessorInterface` + Laravel `tap` channel pattern) + Q4 (tail follow with rotation detection) |
| DEVUI-05 | Queue inspector reads `jobs` / `failed_jobs` / `job_batches`; ~200-line Livewire; replaces Horizon in shipped build | Q6 (queue schemas confirmed against installed migrations; programmatic retry/cancel APIs verified) |
| DEVUI-06 | Doctor panel + system_alerts viewer + env snapshot + effective-config tree | Q12 (env/config render strategy + redaction); locked D-43 reuses Process+SSE; D-44 fields enumerated |
| DEVUI-07 | SELECT-only SQL panel — parse-time non-SELECT rejection + schema viewer | Q2 (validator-library options + recommendation, including the `Doctrine\SqlFormatter\Tokenizer` `@internal` warning) + PRAGMA `query_only` confirmed against SQLite docs |
| DEVUI-08 | Embedded Horizon iframe — dev-mode-only behind dev-runtime flag | locked D-38 — `config('app.dev_mode') === true` AND `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)`; existing `bootstrap/providers.php` precedent reusable |
| DEVUI-09 | ⌘K / Ctrl+K command palette with fuzzy search across views + dev commands | Q7 (palette stack analysis — Flux Pro vs. custom Alpine+Fuse.js); locked D-40/D-41/D-42 |
</phase_requirements>

## Summary

This phase delivers a new bounded module — `Modules/DevMode/` — that hosts every `/dev/*` surface (overview, artisan runner, audit log, log tailer, queue inspector, doctor panel, SQL panel + schema viewer, env/config snapshot, optional Horizon iframe) and a global ⌘K command palette. Two cross-cutting changes also land in this phase: (16-01) an app-wide sidebar restructure that becomes the primitive the Dev Console sidebar reuses, and (16-02) the full `diederik` → `beatrax` rename. The Phase 12 impersonation surface is deleted in the same phase.

The stack is fully Laravel-native — no new heavy runtime dependencies. The three packages that genuinely need adding are `spatie/laravel-activitylog` (audit log), `doctrine/sql-formatter` (SQL token validation), and the JS-side `fuse.js` (palette fuzzy ranking). All three are mature, well-maintained, and locked in CONTEXT.md.

**Primary recommendation:** Build everything in the locked stack. Three pivot calls the planner must make consciously:

1. **Stream transport choice (Q1):** `wire:stream` (Livewire 4 native) IS suitable for command output streaming if the run lifetime is bounded (most artisan commands finish in seconds to minutes); the SSE-via-controller path locked in D-16 / D-28 is more flexible (reconnect after page refresh, multiple cards, log tailer that lives across a Livewire navigation). Recommend the planner stick with the locked SSE-controller approach.
2. **Activitylog version (Q13):** CONTEXT D-23 says `^4.12`, but `spatie/laravel-activitylog 5.0.0` (released 2026-03-25) is the version that officially supports Laravel 13 and PHP 8.4+. **The locked `^4.12` constraint cannot satisfy this project's Laravel 13 + PHP 8.5 installed versions.** This is a CONTEXT inconsistency that the planner must reconcile — either bump the constraint to `^5.0` (recommended) or pin Laravel 12 (rejected by current composer.json `^13.0`).
3. **SQL tokenizer choice (Q2):** CONTEXT D-45 prescribes `doctrine/sql-formatter`'s tokenizer, but `Doctrine\SqlFormatter\Tokenizer` is marked `@internal` in the package source. Relying on it works today but is brittle. Recommend a thin wrapper that owns the dependency-injection seam plus a Pest contract test that locks the public-facing assertions, OR pivot to `greenlion/php-sql-parser` (purpose-built parser, validates structure, public API).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `EnsureDeveloperMode` middleware | API / Backend | — | Route gating happens server-side; 404 masking requires the route to be absent from the response, not hidden by CSS |
| Artisan command execution | API / Backend (Symfony Process spawn) | — | The Livewire component cannot fork processes; spawning is a controller/listener responsibility |
| Live stdout/stderr streaming | API / Backend (SSE controller) | Browser (EventSource consumer in Livewire) | SSE endpoint owns flush cadence; browser owns reconnect-on-disconnect |
| Triple-gate UI (Advanced toggle + typed-name modal) | Frontend Server (SSR + Livewire) | Browser (Alpine `x-trap` focus, Esc binding) | Session-scoped Advanced toggle is server state; the modal trapping focus is client UX |
| Monolog redaction processor | API / Backend | — | Runs inside the log-write pipeline; never client-visible |
| OAuth-secrets scrub-set cache | API / Backend (singleton service + Eloquent observer) | — | Pure server concern — secrets must never reach the browser |
| Log tailer SSE stream | API / Backend (controller) | Browser (EventSource consumer + scrollback buffer) | Server reads files; browser maintains the 10k-line ring buffer |
| Queue inspector reads `jobs` / `failed_jobs` / `job_batches` | API / Backend (Eloquent) | — | Standard DB-backed Livewire — no special transport |
| Queue retry/delete/cancel actions | API / Backend (`Queue::*` + `Batch::find()`) | — | Uses the queue.failed provider + `Bus\Batch` facade; audit-log row written from same listener |
| SELECT-only SQL panel — parse + execute | API / Backend (Tokenizer + read-only PDO connection) | Browser (CodeMirror or `<textarea>`) | SQL parsing is server-side enforcement; the input UI is irrelevant to the security guard |
| Schema viewer | API / Backend (`Schema::getTables()` etc.) | — | Native Laravel 11+ API; replaces the Doctrine DBAL path that older docs reference |
| Command palette — keybind capture | Browser (Alpine `x-data` on `<body>`) | — | Cross-platform keyboard handling is purely client-side; uses `event.metaKey \|\| event.ctrlKey` |
| Command palette — fuzzy ranking | Browser (Fuse.js against server-emitted JSON) | API / Backend (registry JSON emit on mount) | Server provides the searchable corpus; browser ranks (no roundtrip per keystroke) |
| Command palette — registry sources | API / Backend (DI-resolved registries) | — | `NavigationRegistry`, `DevCommandRegistry`, `AppActionRegistry` are constructor-injected services |
| Worker heartbeat producer | API / Backend (`Queue::looping` listener writes cache) | — | Listener registered in `DevModeServiceProvider::boot()`; runs inside the worker process |
| Worker heartbeat consumer | API / Backend (cache read) | Browser (`wire:poll` 5s) | Standard Livewire polling — no streaming needed |
| Horizon iframe | Browser (`<iframe src="/horizon">`) | API / Backend (conditional route registration) | Iframe is purely a client embed; gating is server-side conditional registration per existing `app/Providers/HorizonServiceProvider.php` pattern |
| NativePHP app-menu Developer submenu | API / Backend (Modules/Desktop AppMenuBuilder edits) | — | Menu composition happens at boot via `AppMenuBuilder::build()` — purely server-side |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^13.0 (13.11.2 installed) [VERIFIED: composer.json + `php artisan --version`] | Web framework | Already pinned; native `Schema::getTables/getColumns/getIndexes` since Laravel 11+ replaces Doctrine DBAL |
| `livewire/livewire` | ^4.0 [VERIFIED: composer.json] | Reactive UI | Already pinned; Livewire 4 ships native `wire:stream` (since Jan 2026) [CITED: livewire.laravel.com/docs/4.x/wire-stream] |
| `livewire/flux` | ^2.0 [VERIFIED: composer.json] | Component library | Already pinned; `<flux:modal>`, `<flux:button>`, `<flux:input>` cover triple-gate modal + arg form. Flux Pro Command Palette exists but is paid ($149+); D-40 explicitly chooses the custom Alpine+Fuse.js path instead [CITED: fluxui.dev/components/command, fluxui.dev/pricing] |
| `symfony/process` | ^7.0 [VERIFIED: composer.json require-dev] | Process spawning + incremental output | Already in repo (used by `Modules/Core/Internal/Console/Probes/ExternalToolVersionProbe.php`); `getIncrementalOutput()` + `stop(grace, signal)` are the canonical SSE producer + cancel primitives [CITED: symfony.com/doc/current/components/process.html] |
| `nwidart/laravel-modules` | ^13.0 [VERIFIED: composer.json] | Module skeleton | Existing pattern; new `Modules/DevMode/` mirrors `Modules/Desktop/` |
| `spatie/laravel-activitylog` | **^5.0** (5.0.0 released 2026-03-25) [VERIFIED: packagist] [WARNING: CONTEXT D-23 says ^4.12, but 5.0 is the version that supports Laravel 13 + PHP 8.4+ — the planner must reconcile] | Audit log | Standard Laravel audit-log package; `activity()->log()` helper supports ad-hoc events; configurable table name + log_name [CITED: spatie.be/docs/laravel-activitylog, github.com/spatie/laravel-activitylog] |
| `doctrine/sql-formatter` | ^1.5 (1.5.4 released 2026-02-08) [VERIFIED: packagist] | SQL tokenization for SELECT-only guard | Lightweight, no external deps, PHP 8.1+ — confirmed via `composer show`. **`Doctrine\SqlFormatter\Tokenizer` is marked `@internal`** [VERIFIED: github.com/doctrine/sql-formatter/blob/master/src/Tokenizer.php line "@internal"] — relying on it is brittle. See Q2 for alternative + mitigation. |
| `fuse.js` | ^7.0 (JS-side, added to `package.json`) [ASSUMED — verify with `npm view fuse.js version`] | Client-side fuzzy ranking | Standard pick for command palettes [CITED: kbar's bundled choice, Nuxt UI command palette uses it]; ~12KB minified |

### Supporting (existing, reused)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Modules/Core/Public/Services/UserDataPathService` | already shipped (Phase 13) | Resolves SQLite + storage paths | Required by `/dev/system` env snapshot (D-44) and the log tailer for the laravel-*.log glob root |
| `Modules/Core/Public/Contracts/CurrentUser` | already shipped (Phase 12) | DI-friendly user accessor | Required by `EnsureDeveloperMode` middleware (DI-only rule) |
| `Modules/Core/Public/Services/SystemAlertQuery` + `AcknowledgeSystemAlert` | already shipped | system_alerts read/write | Drives `/dev` overview alerts tile + the dashboard non-dev fallback (D-37) |
| `Modules/EmailScan/Public/Services/OAuthSecretsRepository` + `Models/OAuthSecret` | already shipped (Phase 12) | OAuth secrets data layer | Source of the scrub-set the Monolog processor uses (D-30) |
| `Modules/Desktop/Internal/Native/AppMenuBuilder` | already shipped (Phase 15-02) | NativePHP app-menu composition | Extended in this phase to add the Developer submenu (Q11) |
| `Illuminate\Contracts\Queue\Factory` | Laravel core | Programmatic queue control | Constructor-inject into queue-inspector actions (DI-only) instead of facades |
| `Illuminate\Bus\Batch` + `BatchRepository` | Laravel core | `Batch::find($id)->cancel()` for D-33 batch cancel | Standard programmatic batch API [CITED: laravel.com/docs/13.x/queues] |
| `Illuminate\Queue\Failed\FailedJobProviderInterface` (container key `queue.failed`) | Laravel core | Programmatic `forget(uuid)` + `all()` + `find($id)` | Use container resolution via DI, not `Artisan::call('queue:retry')` (which is correct programmatic API but Artisan-shell shape is opaque) [CITED: laravel.com/docs/13.x/queues] |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Custom Livewire+Alpine+Fuse.js palette (D-40) | `<flux:command>` (Flux Pro) | Flux Pro is paid ($149/project minimum) AND lacks multi-source categories + fuse-style ranking. Locked decision is correct. |
| `wire:stream` for artisan output streaming | Locked: SSE controller (D-16) | `wire:stream` works inside a Livewire roundtrip; SSE-controller supports page-refresh reconnect (D-16 caches `pid`+`run_id`) and multiple concurrent cards (D-17). Locked path is correct. **Note for planner:** the log tailer (D-28) similarly needs the controller path because it outlives any single Livewire request lifecycle. |
| `doctrine/sql-formatter` Tokenizer (`@internal`) | `greenlion/php-sql-parser` (purpose-built, public API) | greenlion is a structured parser that recognizes statement types; locked D-45 is fine if wrapped in a small adapter + Pest test that the planner adds — see Q2. |
| `spatie/laravel-activitylog ^4.12` (locked) | `^5.0` (Laravel 13 + PHP 8.4 compatible) | **Must reconcile** — see Q13. Locked v4 cannot resolve under the installed Laravel 13. |
| Server-side fuzzy ranking via Livewire `wire:model.live.debounce` | Locked: client-side Fuse.js | Server-side ranking adds a roundtrip per keystroke; client-side is calmer + works offline-in-bundle. Locked path is correct. |
| `laravel-imap` mention (vestigial) | n/a | composer.json explicitly conflicts `webklex/laravel-imap`, `webklex/php-imap`, `ddeboer/imap`. The CLAUDE.md "Recommended Stack" mention of webklex is stale — actual project uses Google API + Microsoft Graph for email. No bearing on Phase 16. |

**Installation (incremental, in three commits per locked D-08 rename order):**

```bash
# 16-01 (sidebar) — no new packages
# 16-02 (rename) — no new packages (only literal changes + composer.json `name` flip)
# 16-03+ (DevMode panels)
composer require spatie/laravel-activitylog:^5.0     # SEE Q13 — supersedes CONTEXT D-23's ^4.12
composer require doctrine/sql-formatter:^1.5
# 16-XX (palette plan)
npm install fuse.js@^7.0
```

**Version verification commands (planner must run before locking):**

```bash
composer show spatie/laravel-activitylog -a | head -5   # confirm v5 resolves under Laravel 13
composer show doctrine/sql-formatter -a | head -5
npm view fuse.js version
```

## Package Legitimacy Audit

> Slopcheck not installed in this session; running it would require `pip install slopcheck`. Mitigation: all three packages are independently verified as the standard, widely-deployed picks for their domains. Two are from established orgs (Spatie, Doctrine); fuse.js is a well-known JS library (~40M weekly downloads).

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `spatie/laravel-activitylog` | Packagist | 9+ years (first release 2017) | 91M+ installs [VERIFIED: packagist] | github.com/spatie/laravel-activitylog | not run | Approved (Spatie first-party; canonical Laravel audit-log) |
| `doctrine/sql-formatter` | Packagist | 3+ years under Doctrine org (forked from jdorn/sql-formatter) | 100M+ installs [CITED: packagist] | github.com/doctrine/sql-formatter | not run | Approved (Doctrine first-party); **but Tokenizer is `@internal` — see Q2 mitigation** |
| `fuse.js` | npm | 11+ years (since 2014) | ~40M weekly | github.com/krisk/Fuse | not run | Approved (industry standard for client-side fuzzy search) |

**Packages removed due to slopcheck [SLOP] verdict:** none (slopcheck did not run; manual verification clean).
**Packages flagged as suspicious [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        Browser (Electron WebView or Herd)               │
│                                                                          │
│   ┌────────────────────────┐    ┌──────────────────────────────────┐    │
│   │  Global ⌘K palette     │◄──►│  Alpine x-data keybind on <body> │    │
│   │  (Fuse.js client-side) │    │  (metaKey || ctrlKey)            │    │
│   └────────────────────────┘    └──────────────────────────────────┘    │
│             │                                                            │
│             │ /dev/*  EventSource (SSE)                                  │
│             ▼                                                            │
└─────────────────────────────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│              Laravel app (Modules/DevMode + cross-module)                │
│                                                                          │
│   ┌─────────────────────────────────────────────────────────────────┐    │
│   │  EnsureDeveloperMode middleware (404, not 403; CurrentUser DI)  │    │
│   └─────────────────────────────────────────────────────────────────┘    │
│            │                                                              │
│   ┌────────┼──────────────────────────────────────────────────────┐      │
│   │        ▼                                                      │      │
│   │  /dev (overview)  /dev/artisan  /dev/audit  /dev/logs         │      │
│   │  /dev/queue/{pending|failed|batches}  /dev/doctor             │      │
│   │  /dev/sql  /dev/system  /dev/horizon (conditional)            │      │
│   │  (Livewire 4 components)                                      │      │
│   └────────────────────────────────────────────────────────────────┘    │
│            │             │             │             │                  │
│            ▼             ▼             ▼             ▼                  │
│   ┌────────────┐  ┌────────────┐  ┌──────────┐  ┌─────────────────┐    │
│   │ SSE artisan│  │ SSE log    │  │ Queue    │  │ SELECT-only SQL │    │
│   │ controller │  │ tailer ctrl│  │ inspector│  │ panel (PDO ROW  │    │
│   │            │  │            │  │ (jobs/   │  │ query_only=1)   │    │
│   │ spawns     │  │ fseek 250ms│  │ failed/  │  │                 │    │
│   │ Process    │  │ + rotation │  │ batches) │  │ doctrine/sql-   │    │
│   │ ::start()  │  │ detection  │  │          │  │ formatter token │    │
│   └─────┬──────┘  └─────┬──────┘  └────┬─────┘  └────────┬────────┘    │
│         │               │               │                 │              │
│         ▼               ▼               ▼                 ▼              │
│   ┌──────────────────────────────────────────────────────────────┐      │
│   │  Monolog redaction processor pipeline (D-29 belt+braces)     │      │
│   │  • Bearer/JWT regex                                          │      │
│   │  • OAuth scrub-set (cached, busted by OAuthSecret observer)  │      │
│   └──────────────────────────────────────────────────────────────┘      │
│         │               │               │                 │              │
│         ▼               ▼               ▼                 ▼              │
│   ┌──────────────────────────────────────────────────────────────┐      │
│   │  dev_mode_audit (spatie/laravel-activitylog ^5.0 table)      │      │
│   │  every command run + every destructive queue action +        │      │
│   │  every sql.select query                                       │      │
│   └──────────────────────────────────────────────────────────────┘      │
│                                                                          │
│   ┌──────────────────────────────────────────────────────────────┐      │
│   │  Queue::looping listener (DevModeServiceProvider::boot)      │      │
│   │  writes cache('dev_mode.queue_worker_heartbeat', now(), 60)  │      │
│   │  Heartbeat consumed by /dev tile (wire:poll 5s) and          │      │
│   │  /dev/artisan pre-flight pill                                │      │
│   └──────────────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Storage: SQLite (UserDataPathService) — jobs / failed_jobs /            │
│  job_batches / dev_mode_audit / system_alerts / oauth_secrets /         │
│  storage/logs/laravel-YYYY-MM-DD.log (daily rotation per D-27)          │
└─────────────────────────────────────────────────────────────────────────┘
```

### Recommended Project Structure (mirrors Modules/Desktop)

```
Modules/DevMode/
├── Providers/
│   └── DevModeServiceProvider.php       # registers routes/views/migrations + Queue::looping heartbeat listener + EnsureDeveloperMode alias + OAuthSecret observer wiring + activity-log binding
├── Routes/
│   └── web.php                          # /dev/* routes; the middleware('ensureDeveloperMode')->prefix('/dev')->group(...) wrapper
├── Database/
│   └── Migrations/
│       └── 2026_05_24_000001_create_dev_mode_audit_table.php   # renames spatie table per D-23
├── Internal/
│   ├── Http/
│   │   ├── Middleware/
│   │   │   └── EnsureDeveloperMode.php       # D-06 (404-not-403; CurrentUser DI)
│   │   ├── Controllers/
│   │   │   ├── ArtisanStreamController.php   # SSE producer for /dev/artisan/stream/{run_id}
│   │   │   ├── ArtisanCancelController.php   # POST /dev/artisan/cancel/{run_id}
│   │   │   └── LogStreamController.php       # SSE producer for /dev/logs/stream
│   │   └── Livewire/
│   │       ├── DevOverviewPage.php
│   │       ├── ArtisanRunnerPage.php
│   │       ├── AuditLogPage.php
│   │       ├── LogTailerPage.php
│   │       ├── QueueInspectorPage.php        # ~200 lines (D-32); three sub-routes mount the same component
│   │       ├── DoctorPanelPage.php
│   │       ├── SqlPanelPage.php
│   │       ├── SystemSnapshotPage.php
│   │       ├── HorizonFramePage.php          # conditional registration (D-38)
│   │       └── CommandPaletteModal.php       # shared modal; mounted in base layout
│   ├── Listeners/
│   │   ├── WriteWorkerHeartbeat.php          # D-39 Queue::looping listener
│   │   └── BustOAuthScrubSetOnSecretChange.php  # D-30 model observer
│   ├── Native/
│   │   └── DeveloperSubmenuExtension.php     # contributes the Developer submenu (registered by Modules/Desktop AppMenuBuilder via DI)
│   ├── Logging/
│   │   └── RedactSecretsProcessor.php        # Monolog ProcessorInterface — registered via `tap` on every channel (D-29)
│   ├── Process/
│   │   ├── RunRegistry.php                   # cache-backed pid + run_id store (D-16 reconnect)
│   │   └── CommandSpawner.php                # wraps Symfony Process::start() + incremental output
│   ├── Sql/
│   │   ├── SelectOnlyValidator.php           # D-45 token validator (wraps doctrine/sql-formatter Tokenizer — see Q2)
│   │   └── ReadOnlySqliteConnection.php      # clones default connection + PRAGMA query_only=1 + PDO::ATTR_TIMEOUT=5
│   └── Services/
│       └── OAuthScrubSet.php                 # singleton — holds decrypted secrets for the redaction processor
├── Public/
│   ├── Contracts/
│   │   ├── DevCommandRegistry.php            # D-15 / D-41 — registered SAFE + DESTRUCTIVE commands with arg schemas
│   │   ├── NavigationRegistry.php            # D-41 — every authenticated app view
│   │   └── AppActionRegistry.php             # D-41 — named app actions
│   └── Events/
│       └── DevCommandExecuted.php            # surfaces command runs for cross-module listeners (e.g. system_alerts)
└── Resources/
    └── views/
        ├── components/
        │   ├── run-card.blade.php
        │   ├── triple-gate-modal.blade.php
        │   ├── status-pill.blade.php
        │   └── tier-chip.blade.php
        ├── livewire/
        │   └── dev/... (per-page Blade)
        └── layouts/
            └── dev-shell.blade.php           # /dev/* sidebar layout (D-04)
```

### Pattern 1: `EnsureDeveloperMode` Middleware — 404-Not-403

**What:** Mask the existence of `/dev/*` routes to non-developers entirely.
**When to use:** Every `/dev/*` route, enforced via arch invariant D-07.
**Example (clones the existing `RequireDeveloperMiddleware` pattern verified at `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php`):**

```php
namespace Modules\DevMode\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class EnsureDeveloperMode
{
    public function __construct(private CurrentUser $currentUser) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentUser->isAuthenticated()
            || $this->currentUser->user()->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);
        return $response;
    }
}
```

Register the alias in `DevModeServiceProvider::boot()`:

```php
$router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class);
```

[Source: existing `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` + `Modules/Auth/Providers/AuthServiceProvider.php:82`]

### Pattern 2: SSE artisan stream + cancel (D-16)

**What:** Spawn the artisan command via `Symfony\Component\Process\Process::start()`, then a separate SSE controller endpoint flushes incremental output to the browser. Cancel is a sibling POST endpoint that sends `SIGTERM`.
**When to use:** Every artisan-runner card AND the `/dev/doctor` panel (D-43 explicitly reuses the same pipeline).
**Example:**

```php
// CommandSpawner::start(string $command, array $args): string  -> returns run_id
public function start(string $command, array $args): string
{
    $runId = (string) Str::uuid();
    $process = new Process(array_merge(['php', 'artisan', $command], $args), base_path());
    $process->setTimeout(null);  // SSE controller polls; no time limit
    $process->start();           // non-blocking
    $this->registry->store($runId, $process->getPid(), $command, $args, now());
    return $runId;
}

// ArtisanStreamController::__invoke(string $runId): StreamedResponse
public function __invoke(string $runId): StreamedResponse
{
    return response()->stream(function () use ($runId): void {
        $process = $this->registry->find($runId);   // resolves the live Process or reconnects
        ignore_user_abort(false);                    // honor client disconnect
        while ($process->isRunning()) {
            $chunk = $process->getIncrementalOutput()
                   . $process->getIncrementalErrorOutput();
            if ($chunk !== '') {
                echo "data: " . $this->redact($chunk) . "\n\n";
                @ob_flush();
                @flush();
            }
            if (connection_aborted()) break;
            usleep(150_000);   // 150ms cadence
        }
        // Final exit-code event + audit-log row write
        echo "event: done\ndata: " . $process->getExitCode() . "\n\n";
    }, 200, [
        'Content-Type'      => 'text/event-stream',
        'Cache-Control'     => 'no-cache',
        'X-Accel-Buffering' => 'no',
    ]);
}
```

[Sources: symfony.com/doc/current/components/process.html — `getIncrementalOutput()`, `stop(int $timeout, int $signal)`; laravel.com/docs/13.x/responses — `response()->stream()`; serversideup.net SSE guide]

### Pattern 3: Triple-Gate Modal (D-13 / D-20 / D-21)

**What:** Three independent checks before a DESTRUCTIVE command runs.
**When to use:** Every DESTRUCTIVE-tier command AND bulk DESTRUCTIVE queue actions (D-22).
**Example:** Server-side rejection in the controller (UI alone is insufficient):

```php
final class ConfirmDestructiveCommand
{
    public function __construct(
        private DevModeFlag $devMode,                // reads config('app.dev_mode')
        private SessionRepository $session,
        private CurrentUser $currentUser,
    ) {}

    public function __invoke(string $typed, string $command, array $resolvedArgs): void
    {
        // Gate 1 — Dev Mode ON (config-level)
        if ($this->devMode->isOn() !== true) {
            throw new ValidationException(['_gate' => 'dev_mode_off']);
        }
        // Gate 2 — Advanced session toggle ON
        if ($this->session->get('dev_mode.advanced') !== true) {
            throw new ValidationException(['_gate' => 'advanced_off']);
        }
        // Gate 3 — typed app-name exact match (case-sensitive)
        if (! hash_equals('beatrax', $typed)) {
            throw new ValidationException(['typed' => 'app_name_mismatch']);
        }
    }
}
```

[Source: locked D-20 / D-21 / D-22; UI-SPEC § Triple-gate modal §]

### Pattern 4: Monolog Redaction Processor with `tap` (D-29)

**What:** A custom Monolog processor invoked via Laravel's `tap` channel modifier; runs on every log line for every channel.
**When to use:** All channels (`single`, `daily`, `stack`, `stderr`).
**Example:**

```php
// Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php
final class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(private OAuthScrubSet $scrubSet) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->scrub($record->message);
        $context = array_map(fn ($v) => is_string($v) ? $this->scrub($v) : $v, $record->context);
        return $record->with(message: $message, context: $context);
    }

    private function scrub(string $line): string
    {
        $line = preg_replace('/Authorization:\s*Bearer\s+[A-Za-z0-9._\-]+/i', 'Authorization: Bearer [REDACTED]', $line) ?? $line;
        $line = preg_replace('/eyJ[A-Za-z0-9._\-]+\.[A-Za-z0-9._\-]+\.[A-Za-z0-9._\-]+/', '[JWT_REDACTED]', $line) ?? $line;
        foreach ($this->scrubSet->all() as $secret) {  // cached set — see D-30
            if ($secret !== '') {
                $line = str_replace($secret, '[REDACTED]', $line);
            }
        }
        return $line;
    }
}

// Modules/DevMode/Internal/Logging/PushRedactProcessor.php — Laravel `tap` class
final class PushRedactProcessor
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(app(RedactSecretsProcessor::class));
        }
    }
}

// config/logging.php — daily channel:
'daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => env('LOG_LEVEL', 'debug'),
    'days'   => 14,
    'tap'    => [\Modules\DevMode\Internal\Logging\PushRedactProcessor::class],
],
```

**Cache strategy for `oauth_secrets` scrub-set (D-30):**

```php
final class OAuthScrubSet
{
    /** @var list<string>|null */ private ?array $set = null;

    public function __construct(private OAuthSecretsRepository $repo) {}

    /** @return list<string> */
    public function all(): array
    {
        if ($this->set !== null) return $this->set;
        $this->set = [];
        foreach ($this->repo->allDecrypted() as $secret) {
            $this->set[] = $secret->clientSecret;
            foreach ((array) $secret->tokensBlob as $v) {
                if (is_string($v)) $this->set[] = $v;
            }
        }
        return $this->set;
    }

    public function bust(): void { $this->set = null; }
}

// Observer wires bust() to OAuthSecret save/delete (registered in DevModeServiceProvider::boot)
```

[Sources: symfony.com/doc/current/logging/processors.html — Monolog processor pattern; github.com/luka-mladenovic/laravel-monolog-processors-tap — Laravel tap class pattern; existing `OAuthSecretsRepository` at `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`]

### Pattern 5: SELECT-only SQL Validator (D-45) — with the @internal mitigation

**What:** Reject non-SELECT at parse time, before the read-only PDO connection ever sees the statement.
**When to use:** `/dev/sql` panel + the schema viewer "Browse" button (D-47).
**Example:**

```php
namespace Modules\DevMode\Internal\Sql;

use Doctrine\SqlFormatter\Token;
use Doctrine\SqlFormatter\Tokenizer;   // ⚠ @internal — wrap behind this service so the seam is owned

final class SelectOnlyValidator
{
    public function validate(string $sql): void
    {
        // Reject: semicolon followed by non-whitespace
        if (preg_match('/;\s*\S/', $sql) === 1) {
            throw new ValidationException(['sql' => 'semicolon_followed_by_statement']);
        }
        // Tokenize and locate first non-whitespace, non-comment token.
        $tokens = (new Tokenizer())->tokenize($sql);
        foreach ($tokens as $token) {
            if (in_array($token->type(), [
                Token::TOKEN_TYPE_WHITESPACE,
                Token::TOKEN_TYPE_COMMENT,
                Token::TOKEN_TYPE_BLOCK_COMMENT,
            ], true)) continue;
            if (strtoupper(trim($token->value())) !== 'SELECT') {
                throw new ValidationException(['sql' => 'first_token_not_select:' . strtoupper(trim($token->value()))]);
            }
            return;
        }
        throw new ValidationException(['sql' => 'empty_statement']);
    }
}
```

**Pest contract test (locks the @internal-API behavior):**

```php
it('rejects every non-SELECT first-token variant', function (string $sql, string $reason): void {
    $this->expectException(ValidationException::class);
    app(SelectOnlyValidator::class)->validate($sql);
})->with([
    ['INSERT INTO t VALUES (1)',              'INSERT'],
    ['UPDATE t SET a=1',                       'UPDATE'],
    ['DELETE FROM t',                          'DELETE'],
    ['DROP TABLE t',                           'DROP'],
    ['WITH x AS (SELECT 1) UPDATE t SET a=1',  'WITH-write'],
    ['SELECT 1; INSERT INTO t VALUES (1)',     'semicolon-stack'],
    ['/* SELECT */ INSERT INTO t VALUES (1)',  'comment-only-prefix'],
]);
```

The contract test gives us early warning if a future doctrine/sql-formatter release reshapes the `Tokenizer` API.

**Read-only PDO connection (D-45):**

```php
final class ReadOnlySqliteConnection
{
    public function __construct(private DatabaseManager $db) {}

    public function execute(string $sql): array
    {
        $clone = $this->db->connection('readonly_select');   // see config/database.php entry below
        $pdo   = $clone->getPdo();
        $pdo->exec('PRAGMA query_only = 1');
        $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
        $start = hrtime(true);
        $rows  = $clone->select($sql);
        return ['rows' => $rows, 'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000)];
    }
}
```

```php
// config/database.php
'readonly_select' => array_merge(config('database.connections.sqlite'), [
    // separate connection so PRAGMA query_only=1 doesn't pollute the default
    'name' => 'readonly_select',
]),
```

[Sources: sqlite.org/pragma.html#pragma_query_only — confirms SQLITE_READONLY error on any write attempt when `query_only=1`; laravel.com/docs/13.x/database — multiple connections]

### Pattern 6: Queue Inspector — Programmatic retry/delete/cancel (D-33)

**What:** Read `jobs` / `failed_jobs` / `job_batches` directly via Eloquent; mutate via DI-injected `FailedJobProviderInterface` + `Bus\Batch` API.
**Example:**

```php
final class QueueInspectorActions
{
    public function __construct(
        private FailedJobProviderInterface $failed,        // container key 'queue.failed'
        private DatabaseManager $db,
        private QueueManager $queue,
    ) {}

    public function retryFailed(string $uuid): void { /* re-dispatch + delete from failed_jobs */ }
    public function forgetFailed(string $uuid): void { $this->failed->forget($uuid); }
    public function cancelBatch(string $batchId): void
    {
        $batch = Bus::findBatch($batchId);
        $batch?->cancel();           // sets cancelled_at; pending jobs short-circuit
    }
    public function deletePending(int $id): void
    {
        $this->db->connection()->table('jobs')->where('id', $id)->delete();
    }
}
```

Schema of installed migrations [VERIFIED: `database/migrations/2026_05_21_001844_create_jobs_table.php`, `create_job_batches_table.php`, `2026_05_16_174022_create_failed_jobs_table.php`]:
- `jobs`: id (PK), queue, payload (text), attempts, reserved_at, available_at, created_at
- `failed_jobs`: id, uuid (unique), connection, queue, payload, exception, failed_at
- `job_batches`: id (UUID PK), name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at

[Source: laravel.com/docs/13.x/queues — "Dealing With Failed Jobs"; Laravel core `Illuminate\Queue\Failed\FailedJobProviderInterface`; `Illuminate\Bus\Batch::cancel()`]

### Pattern 7: Activitylog ad-hoc events (D-23 / D-24)

```php
activity('dev_mode')
    ->causedBy($this->currentUser->user())
    ->withProperties([
        'command'         => 'db:restore',
        'args'            => $args,
        'tier'            => 'destructive',
        'exit_code'       => $exitCode,
        'stdout_excerpt'  => mb_substr($redactedStdout, 0, 8192),
        'error_excerpt'   => mb_substr($redactedStderr, 0, 8192),
    ])
    ->log('command_executed');
```

Configure `config/activitylog.php` after `vendor:publish`:
```php
'table_name'       => 'dev_mode_audit',         // D-23
'default_log_name' => 'dev_mode',
```

[Source: spatie.be/docs/laravel-activitylog/v4/basic-usage/logging-activity]

### Pattern 8: Schema Viewer (D-47) — Laravel-native, no Doctrine DBAL

```php
final class SchemaSnapshot
{
    public function __construct(private SchemaBuilder $schema) {}

    public function all(): array
    {
        $tables = $this->schema->getTables();          // [{name, schema, size, ...}, …]
        return collect($tables)->map(fn ($t) => [
            'name'    => $t['name'],
            'columns' => $this->schema->getColumns($t['name']),       // name/type/nullable/default
            'indexes' => $this->schema->getIndexes($t['name']),
            'fks'     => $this->schema->getForeignKeys($t['name']),
            'rows'    => DB::table($t['name'])->count(),
        ])->all();
    }
}
```

[Source: laravel.com/docs/11.x/upgrade — "deprecated Doctrine-based Schema::getAllTables/getAllViews/getAllTypes methods have been removed in favor of new Laravel native Schema::getTables/getViews/getTypes"; available since Laravel 11+ and present in 13]

### Anti-Patterns to Avoid

- **Calling `Artisan::call('queue:retry')` from controllers.** Use `FailedJobProviderInterface` + re-dispatch; the Artisan call shape is opaque and harder to audit. (DI-only rule additionally forbids the facade.)
- **Returning 403 from `EnsureDeveloperMode`.** SC1 / D-06 require 404 — 403 leaks route existence.
- **Allowing `wire:stream` to drive the log tailer.** The tailer lives across page navigations; a Livewire roundtrip ends when the user clicks away. Stick with SSE controller (locked D-28).
- **Building a regex-only SELECT validator.** Edge cases multiply (block comments, dollar quoting, nested parens, `WITH … UPDATE …`). Locked D-45 tokenizer path is correct.
- **Trusting Bash `tail -F` for the log tailer.** Cross-platform: doesn't exist on Windows by default; pure-PHP `fseek` + `clearstatcache()` is portable (locked D-28).
- **Caching the OAuth scrub-set in `Cache::remember(60)`.** Stale secrets in the scrub set after a Settings change would leak. The observer-bust pattern (D-30) is the right shape.
- **Adding any `.planning/` / `D-NN` reference into runtime code, view text, or PHPDoc.** Forbidden by user memory `feedback_codebase_gsd_agnostic` and by the upcoming Phase 19 REL-05 arch test.
- **Hand-rolling a CodeMirror integration for the SQL panel in 16-XX.** A plain `<textarea>` + tabular-nums result table is calmer and matches the UI-SPEC's no-fancy-editor stance. Defer syntax highlighting to a later phase if asked.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Audit log table + writer | Custom `dev_mode_audit` Eloquent model + writer | `spatie/laravel-activitylog ^5.0` | Solved: causer/subject tracking, JSON properties, query scopes, log-name filtering |
| SQL statement classification | Regex against `^SELECT` | `doctrine/sql-formatter` Tokenizer (with `@internal` wrapper) OR `greenlion/php-sql-parser` | Comments, CTEs, semicolons, dialect quirks make regex unreliable |
| Schema introspection | `PRAGMA table_info` raw queries | `Schema::getTables() / getColumns() / getIndexes() / getForeignKeys()` | Native since Laravel 11; cross-DB; the right unit |
| Process spawn + cancel + incremental output | `proc_open` + manual loops | `Symfony\Component\Process\Process` (already in repo) | Battle-tested signal handling, getIncrementalOutput, stop(grace, signal) |
| Fuzzy search ranking | Custom Levenshtein scoring | `fuse.js` | Industry standard; configurable keys + threshold + ignoreLocation |
| SSE controller boilerplate | DIY chunked transfer | `response()->stream(...)` + `ignore_user_abort` + `ob_flush()` + `flush()` | Standard pattern; documented |
| Queue worker heartbeat | Custom polling daemon | `Queue::looping` listener | Built-in lifecycle hook; cost-free when no worker is running |
| File tail + rotation detection | `tail -F` shell-out | `fseek` + `clearstatcache` + inode/size check | Cross-platform; pure PHP; no fork |
| Forced focus trap inside triple-gate modal | DIY keyboard handler | `<flux:modal>` focus trap (Flux ships it) | Already in the bundle; no extra library |
| Activitylog UI table | Bespoke audit table page | `Activity` Eloquent model + Livewire pagination | spatie's model exposes all the filterable fields |

**Key insight:** This phase is "wire together canonical Laravel + Symfony + spatie primitives behind a 404-gated bounded module." The temptation to write a slick custom abstraction layer is the wrong instinct; the calm-control surface only works if every piece is the boring obvious one.

## Runtime State Inventory

> Triggered by the 16-02 `diederik` → `beatrax` full rename (D-08). The grep audit finds files; this inventory finds the runtime state grep cannot.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **None.** SQLite is path-based, not name-based; no DB stores the literal "diederik" as a key. `dev_mode_audit` table is being created fresh in this phase; no historical activity-log rows to migrate. system_alerts message copy may contain the literal "diederik" — every row uses Blade/translation rendering via `Modules/Core` so the text changes when source strings flip. | Verify via `select count(*) from system_alerts where body like '%diederik%'` — if non-zero, document as a known-issue "old alerts retain old name" (D-09 says no migration code, so old rows stay as-is; new rows render `beatrax`). |
| Live service config | **None.** No external service stores the project name (no Datadog, no n8n, no Tailscale, no Cloudflare). Horizon is local-only. NativePHP bundles a self-contained Electron app — no cloud-side config. | None. |
| OS-registered state | **macOS LaunchAgent plist** if the user installed one for the bundled worker/scheduler (Phase 15-03 ships `deploy/launchd/*` templates with `com.diederik.*` labels). **App bundle id** `com.diederik.*` → `com.beatrax.*` requires re-installing the app under the new bundle id; macOS treats the new bundle as a separate app. **Herd hostname** `diederik.test` → `beatrax.test` — Herd's dnsmasq config + project link must be reseated. | Document in the 16-02 plan: uninstall the old `.app`, reinstall with new bundle id; `herd unlink diederik && herd link beatrax`; reload `~/Library/LaunchAgents/com.diederik.*` plists as `com.beatrax.*`. |
| Secrets and env vars | **`DIEDERIK_DEV_MODE` env var** in the developer's local `.env` + every `*.env.example` in repo — D-08 renames the var name; the developer must hand-edit their `.env` (D-09 confirms no auto-migration). The `config('app.dev_mode')` config key itself stays unchanged so consumers don't break. **`NATIVEPHP_*` env vars** unchanged. **`oauth_secrets` table values** unchanged — the rename does not touch user data. | Document in 16-02: edit local `.env` after pulling the rename commit. |
| Build artifacts / installed packages | **`nativephp/electron/dist/*/diederik.app/`** is gitignored (Phase 15-05) — no action needed; next `php artisan native:build` produces `beatrax.app`. **`composer.json` `name: diederik/diederik`** flips to `beatrax/beatrax` — anyone with the project locked at the old name needs `composer install` to refresh. | `composer install` after pulling 16-02. |

**The canonical question — answered:** After the find/replace, the only runtime systems that retain "diederik" are (a) historical `system_alerts` row content (acceptable per D-09), (b) the developer's locally-edited `.env` (one manual edit), (c) the old `.app` bundle on disk (uninstall + reinstall), (d) any `launchd` plists the developer set up (re-register). All four are documented in the 16-02 plan's hand-off notes.

## Common Pitfalls

### Pitfall 1: `wire:stream` and Octane incompatibility
**What goes wrong:** Plan picks `wire:stream` for the artisan runner; later switching to Octane breaks the page silently.
**Why it happens:** Livewire docs explicitly state "wire:stream is not compatible with Laravel Octane" [CITED: livewire.laravel.com/docs/4.x/wire-stream]. This project doesn't use Octane today but the NativePHP bundle runs a long-lived PHP-FPM-style process.
**How to avoid:** Stick with the locked SSE-controller path (D-16). The architecture is identical (browser EventSource), but the producer is a regular controller — Octane-safe and cancel-safe.
**Warning signs:** A planner suggesting "let's simplify by using wire:stream instead of the SSE controller" should be rejected at PLAN-CHECK.

### Pitfall 2: `Doctrine\SqlFormatter\Tokenizer` is `@internal`
**What goes wrong:** A future minor release of `doctrine/sql-formatter` reshapes the tokenizer (or moves it into a different namespace), and the SELECT-only validator silently changes behavior or fatals.
**Why it happens:** The class header carries `@internal` [VERIFIED: github.com/doctrine/sql-formatter/blob/master/src/Tokenizer.php]. Semantic versioning gives the maintainers freedom to refactor.
**How to avoid:** (a) Pin the version tightly (`^1.5` is OK; broader is risky). (b) Wrap the tokenizer behind `SelectOnlyValidator` so only one file references it (the Q2 pattern above). (c) Add the Pest contract test that locks the rejection cases — gives an early signal on upgrade. **Alternative pivot:** use `greenlion/php-sql-parser` which has a public, purpose-built API.
**Warning signs:** Tokenizer-related Larastan errors after a `composer update`.

### Pitfall 3: spatie/laravel-activitylog `^4.12` cannot install on Laravel 13
**What goes wrong:** CONTEXT D-23 prescribes `^4.12`. v4 requires Laravel 9/10/11. The composer constraint will fail to resolve under this project's `laravel/framework: ^13.0`.
**Why it happens:** CONTEXT was written when the project was still Laravel 12 in CLAUDE.md — Laravel was bumped to 13 mid-Phase-15 alongside the PHP 8.4/8.5 relaxation.
**How to avoid:** Bump the constraint to `^5.0` in the actual install (v5.0.0 released 2026-03-25, supports Laravel 12 + 13, requires PHP 8.4+). The API at the call sites this phase needs (`activity()->log()`, custom table name via config) is unchanged across v4→v5.
**Warning signs:** `composer require spatie/laravel-activitylog:^4.12` returns "Your requirements could not be resolved to an installable set of packages."

### Pitfall 4: SSE behind a buffering reverse proxy
**What goes wrong:** Output buffers up at the proxy; the user sees a wall of text dumped at the end of the run instead of streaming.
**Why it happens:** nginx default buffers responses; PHP's output_buffering defaults vary; gzip middleware buffers.
**How to avoid:** Send `X-Accel-Buffering: no` header (nginx-aware), disable `zlib.output_compression`, set `output_buffering: 0` (via `ini_set` inside the controller), and call `ob_flush(); flush();` after every chunk. NativePHP's bundled PHP runs an internal server with no proxy in front, so this only matters in Herd; still add the header for safety.
**Warning signs:** Stream works in CLI `curl` but not in the browser.

### Pitfall 5: Forgetting to alias the middleware before the arch test runs
**What goes wrong:** `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` greps for the alias string `ensureDeveloperMode` on every `/dev/*` route. If the middleware is bound by class instead, the test fails even though security is correct.
**Why it happens:** Two valid Laravel patterns; the arch test pins the alias form.
**How to avoid:** Always register via `$router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class)` in `DevModeServiceProvider::boot()`; the route group uses `->middleware('ensureDeveloperMode')`.
**Warning signs:** Arch test fails with "route URI `/dev/foo` does not apply ensureDeveloperMode".

### Pitfall 6: `is_developer = false` doesn't invalidate active `/dev/*` sessions
**What goes wrong:** The owner toggles their own `is_developer` off via Settings; their open `/dev/queue` Livewire poll keeps returning data until the page is reloaded.
**Why it happens:** `EnsureDeveloperMode` runs on every HTTP request, but a Livewire `wire:poll` request DOES go through the middleware stack (good news). However, the user can have non-dev tabs open that were rendered when `is_developer=true`.
**How to avoid:** No special handling needed — the next `wire:poll` returns a 404 and Livewire surfaces it as a session-expired toast. Document the behavior in the Settings toggle copy ("Open Dev Console tabs will stop refreshing after a few seconds").
**Warning signs:** Manual test: toggle off in tab A; tab B's `/dev/queue` polling stops within 5s.

### Pitfall 7: SQL panel cancel under PHP CLI vs. FPM
**What goes wrong:** `PDO::ATTR_TIMEOUT` is honored differently by drivers; SQLite's PDO driver does not honor `ATTR_TIMEOUT` for query timeouts (it's for connection timeout).
**Why it happens:** The PDO timeout attribute is driver-specific.
**How to avoid:** Use SQLite's `PRAGMA busy_timeout` for lock waits, and for query duration cap, wrap the call in a `pcntl_alarm()` or run on a separate Symfony Process worker with a real timeout. Simpler: set a sane `EXPLAIN` cost cap by inspecting `Modules/DevMode/Internal/Sql/SelectOnlyValidator::validate()` and rejecting cross-joins on large tables. **For v1 of this phase, ship the 5-second `set_time_limit(5)` wall-clock cap inside the controller** — coarse but reliable.
**Warning signs:** Long-running runaway `SELECT` doesn't time out as advertised.

### Pitfall 8: Monolog processor adds overhead to every log line
**What goes wrong:** The redaction processor iterates the scrub-set on every log line; with 10+ OAuth secrets and a noisy debug log, this can add measurable CPU during heavy ingestion runs.
**Why it happens:** `str_replace($haystack, [array_of_needles], '[REDACTED]')` is O(n*m).
**How to avoid:** Pre-compile a single regex from the scrub-set once per request (`'/' . implode('|', array_map(preg_quote, $secrets)) . '/'`) and run one regex per line. Cache the compiled pattern across requests behind the same observer bust used for the set itself.
**Warning signs:** Production log throughput drops under the redaction processor.

### Pitfall 9: NativePHP app menu changes don't appear until full quit
**What goes wrong:** Adding the Developer submenu to `AppMenuBuilder::build()` doesn't show up after `php artisan native:dev` reload.
**Why it happens:** The macOS menu bar is composed at process boot; Electron doesn't recompose menus on PHP reload.
**How to avoid:** Document in the plan that menu changes need a full bundle restart, not just a PHP autoreload.
**Warning signs:** Tester says "I don't see the Developer menu" after a code change.

## Code Examples

### Common Operation 1 — Register the EnsureDeveloperMode alias

```php
// Modules/DevMode/Providers/DevModeServiceProvider.php
public function boot(Router $router, Dispatcher $events, LivewireManager $livewire): void
{
    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    $this->loadViewsFrom(__DIR__.'/../Resources/views', 'devmode');

    $router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class);

    // D-39 worker heartbeat — Queue::looping fires on every worker tick
    $events->listen('Illuminate\Queue\Events\Looping', WriteWorkerHeartbeat::class);

    // D-30 scrub-set observer
    OAuthSecret::observe(BustOAuthScrubSetOnSecretChange::class);
}
```

### Common Operation 2 — Register palette source

```php
// Modules/DevMode/Internal/Listeners/RegisterDevCommands.php
public function __construct(private DevCommandRegistry $registry) {}

public function register(): void
{
    $this->registry->add('db:backup', label: 'Back up database', tier: 'safe', args: [
        ['name' => 'destination', 'label' => 'Destination', 'type' => 'file-path', 'rules' => ['nullable']],
    ]);
    $this->registry->add('db:restore', label: 'Restore database', tier: 'destructive', args: [
        ['name' => 'from', 'label' => 'Backup file', 'type' => 'file-path', 'rules' => ['required']],
    ]);
}
```

### Common Operation 3 — Emit palette JSON to the browser

```php
// CommandPaletteModal::getRegistryJson() — returns a list<array{id,label,hint,source,icon,url}>
public function getRegistryJson(): array
{
    return collect([
        ...$this->nav->all()->map(fn ($e) => [...$e, 'source' => 'view']),
        ...$this->currentUser->user()->is_developer
            ? $this->commands->safeTier()->map(fn ($e) => [...$e, 'source' => 'dev'])
            : collect(),
        ...$this->actions->all()->map(fn ($e) => [...$e, 'source' => 'action']),
    ])->values()->all();
}
```

### Common Operation 4 — Browser-side Fuse.js bootstrap

```html
<!-- mounted by CommandPaletteModal Blade -->
<div x-data="palette({{ Js::from($registry) }})" x-on:keydown.window="onKey($event)">
    <input x-model="query" wire:ignore />
    <ul x-show="open">
        <template x-for="hit in results.slice(0, 50)" :key="hit.item.id">
            <li @click="execute(hit.item)" x-text="hit.item.label"></li>
        </template>
    </ul>
</div>

<script>
window.palette = (registry) => ({
    open: false, query: '',
    fuse: new Fuse(registry, {
        keys: [{name:'label',weight:0.65},{name:'hint',weight:0.20},{name:'keywords',weight:0.15}],
        threshold: 0.35, ignoreLocation: true,
    }),
    get results() { return this.query ? this.fuse.search(this.query) : registry.map(item => ({item})); },
    onKey(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); this.open = !this.open; }
        if (this.open && e.key === 'Escape') { this.open = false; }
    },
    execute(item) { if (item.url) window.location.href = item.url; this.open = false; },
});
</script>
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Schema::getAllTables()` (Doctrine DBAL-based, deprecated) | `Schema::getTables() / getColumns() / getIndexes() / getForeignKeys()` (Laravel-native) | Laravel 11 (2024) | Doctrine DBAL no longer required; schema viewer code is shorter + faster |
| Livewire Volt as a separate package | Livewire 4 supersedes Volt — single-file components are the new default | Livewire 4 (Jan 2026) | UI-SPEC reference to Volt is stale [CITED: github.com/livewire/livewire/discussions/9520] — use plain Livewire 4 SFCs; functionally equivalent |
| Native `ext-imap` for email | webklex/php-imap (pure PHP) — but this project bypassed both via Gmail API + MS Graph | PHP 8.4 (2024) | Not relevant to Phase 16 (no email integration touched) |
| `Cache::lock()` requires Redis | `cache.locks_store: database` (Laravel 11+) | Laravel 11 (2024) | Already adopted in Phase 14; queue heartbeat uses `cache()` directly via the database driver |
| Hand-rolled command palettes (kbar, custom React) | cmdk pattern; in Laravel: Livewire + Alpine + Fuse.js | 2024–2026 | UI-SPEC's locked stack matches the calm-aesthetic state of the art |

**Deprecated/outdated:**

- **Doctrine DBAL** for schema introspection in Laravel projects — removed; use native Schema methods.
- **Livewire Volt** as a separate `livewire/volt` package — superseded by Livewire 4 SFCs. Do not introduce in new code.

## Project Constraints (from CLAUDE.md)

| Directive | Source | Application in Phase 16 |
|-----------|--------|-------------------------|
| **PHP 8.5 dev / 8.4 bundle** (composer.json relaxed `^8.4`) | composer.json + State [Phase 15-05] | spatie/laravel-activitylog v5 requires PHP 8.4+; ✓ compatible |
| **Laravel 13** (NOT 12 as CLAUDE.md still says) | composer.json `laravel/framework: ^13.0` | Forces activitylog ^5.0 (CONTEXT D-23 says ^4.12 — see Pitfall 3) |
| **Livewire 4 + Volt + Flux + Alpine + Tailwind 4** | composer.json | Use Livewire 4 SFCs (Volt is superseded — see State of the Art) |
| **No facade calls / global helpers in module code** | feedback_laravel_di_only memory + BoundaryArchTest noAuthFacadeOrHelper | EnsureDeveloperMode reads CurrentUser via DI; queue inspector uses FailedJobProviderInterface via DI; never `Auth::user()` / `auth()` / `request()->user()` |
| **No `.planning/` / PLAN.md / D-NN refs in code, PHPDocs, comments, views, errors, log lines, route names** | feedback_codebase_gsd_agnostic memory | Audit every Blade + PHP file emitted in this phase; route names like `dev.artisan.run` are fine; descriptive PHPDocs without `D-NN` references are fine |
| **Docs describe current state, never history** | feedback_docs_describe_current_state | No "I changed this because…" comments; PHPDocs say what the code does, not why it was added |
| **Fix every severity, not just blockers** | feedback_fix_all_severities | Plan-check passes must address BLOCKER + WARNING + INFO; do not weaken arch tests to pass early |
| **Modular boundary — cross-module access via Public services/events only** | CLAUDE.md + nwidart/laravel-modules pattern | `DevCommandRegistry`, `NavigationRegistry`, `AppActionRegistry` are the public surfaces (D-02); every other module's `Internal/` is off-limits |
| **Larastan L10 strict + Pint + Pest CI-enforced** | composer.json + project config | Every new class typed-strict; arch tests are Pest |
| **MVP slicing (vertical, not horizontal)** | CLAUDE.md project slicing | Each plan ends with a demoable surface; e.g. 16-03 ships the artisan runner end-to-end (route → middleware → Livewire page → Process spawn → SSE → audit log) before 16-04 ships another surface |
| **ICS Cards consumer portal is PDF-only** | project_ics_portal_pdf_only memory | Not relevant to Phase 16 |
| **Local-only — no webfont fetch** | PROJECT.md privacy constraint | Fuse.js loaded via Vite bundle (already npm-installed); no CDN fetch |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `fuse.js@^7.0` is the current major | Standard Stack | Low — verify with `npm view fuse.js version` before adding to package.json |
| A2 | Flux Pro Command Palette unsuitable (locked decision was correct) | Alternatives Considered | Low — Flux docs confirm Pro-only + single-source flat list, not multi-category fuzzy |
| A3 | `PDO::ATTR_TIMEOUT` is not reliable for SQLite query duration | Pitfall 7 | Medium — verify with a quick `set_time_limit(5)` fallback test; coarse but reliable |
| A4 | `Queue::looping` listener fires from inside the long-running worker process | Pattern 6 / D-39 | Medium — verify with `php artisan queue:work --verbose` showing the cache key write |
| A5 | Existing `bootstrap/providers.php` `class_exists()` pattern (Phase 14 D-04) is reusable verbatim for Horizon iframe gate | D-38 | Low — pattern proved out in Phase 14 |
| A6 | Re-installing the macOS app under `com.beatrax.*` bundle id is required (treated as a separate app) | Runtime State Inventory | Low — macOS bundle-id semantics are well-known |
| A7 | `Doctrine\SqlFormatter\Tokenizer`'s `@internal` marker is stable across `^1.5` minor versions | Pitfall 2 | Medium — the Pest contract test mitigates this; pin tightly |

## Open Questions

1. **Should the SAFE-tier `queue:retry` command in D-12 take a `--queue=` arg form, or always retry all?**
   - What we know: D-12 lists `queue:retry` without args.
   - What's unclear: D-15's "Declarative arg-form schemas" — does this command need any?
   - Recommendation: Plan with optional args `[id?: text, queue?: select-from-known-queues]`; default to retry-all if both blank.

2. **Does the `/dev/sql` schema viewer "Browse" button respect the SQLite-only constraint or generalize for a future Postgres?**
   - What we know: SQLite is the only DB in v2.0; `Schema::getTables()` is cross-DB.
   - What's unclear: Worth the abstraction?
   - Recommendation: Code against `Schema::*` (cross-DB by accident is free); document that the read-only PDO connection assumes SQLite.

3. **What's the retention policy for the `pid` + `run_id` cache entries (D-16)?**
   - What we know: Stored in cache so page-refresh can reconnect to running stream.
   - What's unclear: TTL not specified.
   - Recommendation: 24h TTL on the cache entry; clean up immediately when the SSE controller observes process exit.

4. **Should the env snapshot D-44 redact `BEATRAX_DEV_MODE` itself?**
   - What we know: D-44 lists `BEATRAX_*` env vars (redacted). `BEATRAX_DEV_MODE` is a boolean flag, not a secret.
   - What's unclear: Redact all `BEATRAX_*` blindly, or per-key denylist?
   - Recommendation: Apply the denylist (`*password*` / `*secret*` / `*key` / `*token*`) to env keys too, so `BEATRAX_DEV_MODE` renders `true` plainly while `BEATRAX_OAUTH_SECRET` would mask.

5. **Does the `noAuthFacadeOrHelper` allow-list need updating for the new EnsureDeveloperMode middleware?**
   - What we know: The new middleware uses CurrentUser DI — no facade call.
   - What's unclear: Anything in DevMode that legitimately needs a facade carve-out?
   - Recommendation: Plan should NOT need any new allow-list entries; the locked DI-only approach is fully compatible. If a Blade view inside `Resources/views/devmode/` reads `auth()->user()`, that follows the established layout-level carve-out and is fine.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.4+ | All phase code (activitylog v5 requirement) | ✓ | 8.5.0alpha1 dev / 8.4 bundle | — |
| ext-pdo_sqlite | Read-only SQL connection | ✓ | bundled with PHP | — |
| ext-pcntl | Symfony Process signal handling | ✓ | loaded | — |
| ext-mbstring | string redaction; multi-byte substring caps | ✓ | loaded | — |
| ext-fileinfo | unrelated; standard | ✓ | loaded | — |
| Symfony Process | artisan runner | ✓ (^7.0) | composer.json require-dev | already in code |
| Livewire 4 + Flux 2 + Tailwind 4 | UI | ✓ | composer.json + package.json | — |
| Node + npm (for fuse.js install) | Palette build | ✓ | npm script `build` runs Vite | — |
| Laravel Horizon (gated) | `/dev/horizon` iframe | ✓ in dev | require-dev `^5.46` | Iframe absent when `config('app.dev_mode') !== true` OR class missing — graceful per D-38 |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none — all needed packages are either present or scheduled to be added (spatie/laravel-activitylog, doctrine/sql-formatter, fuse.js).

## Validation Architecture

> `workflow.nyquist_validation: true` per `.planning/config.json`.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 with pestphp/pest-plugin-arch + pestphp/pest-plugin-laravel + canvural/larastan-strict-rules + spatie/pest-plugin-snapshots [VERIFIED: composer.json] |
| Config file | `tests/Pest.php` (root) + per-module `Modules/*/tests/Pest.php` |
| Quick run command | `pest --parallel --filter=DevMode` |
| Full suite command | `composer test` (runs `pest --parallel`) |
| Static analysis | `composer analyse` (Larastan L10 strict via `phpstan.neon`) |
| Formatter check | `composer format:check` (Pint) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DEVUI-01 | Non-developer hitting `/dev/foo` gets 404 not 403 | feature | `pest Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` | ❌ Wave 0 |
| DEVUI-01 | `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` (D-07) | arch | `pest --filter everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` | ❌ Wave 0 |
| DEVUI-02 | SAFE-tier `db:backup` spawn → live stream → audit row | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` | ❌ Wave 0 |
| DEVUI-02 | Cancel-running-command sends SIGTERM and audit row records `cancelled` | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerCancelTest.php` | ❌ Wave 0 |
| DEVUI-03 | DESTRUCTIVE `db:restore` rejected without all three gates | feature | `pest Modules/DevMode/tests/Feature/TripleGateTest.php` | ❌ Wave 0 |
| DEVUI-03 | `dev_mode_audit` row written via spatie/laravel-activitylog | feature | `pest Modules/DevMode/tests/Feature/AuditLogWriteTest.php` | ❌ Wave 0 |
| DEVUI-04 | Injected fake secret in log line is redacted before stream emit | unit | `pest Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php` | ❌ Wave 0 |
| DEVUI-04 | OAuth secret saved → scrub-set busted → next log line contains [REDACTED] | feature | `pest Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php` | ❌ Wave 0 |
| DEVUI-05 | Queue inspector retry-failed re-dispatches + removes failed row | feature | `pest Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php` | ❌ Wave 0 |
| DEVUI-05 | Bulk delete requires triple-gate (D-22) | feature | `pest Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php` | ❌ Wave 0 |
| DEVUI-06 | Doctor panel pipeline parses pass/warn/fail rows from `beatrax:doctor` | feature | `pest Modules/DevMode/tests/Feature/DoctorPanelParserTest.php` | ❌ Wave 0 |
| DEVUI-06 | `/dev/system` env snapshot redacts `*password*` / `*secret*` / `*key` / `*token*` keys | unit | `pest Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php` | ❌ Wave 0 |
| DEVUI-07 | SelectOnlyValidator rejects each non-SELECT shape (table-driven) | unit | `pest Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php` | ❌ Wave 0 |
| DEVUI-07 | Read-only connection rejects INSERT via SQLITE_READONLY (defense-in-depth) | feature | `pest Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` | ❌ Wave 0 |
| DEVUI-07 | SQL panel writes `dev_mode_audit` row with rowcount + duration | feature | `pest Modules/DevMode/tests/Feature/SqlPanelAuditTest.php` | ❌ Wave 0 |
| DEVUI-08 | Horizon iframe route absent when `config('app.dev_mode') === false` | feature | `pest Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php` | ❌ Wave 0 |
| DEVUI-09 | Palette registry JSON contains nav + dev (SAFE only) + actions for a developer | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` | ❌ Wave 0 |
| DEVUI-09 | Palette registry excludes DEV source for non-developers | feature | same file, second `it()` | ❌ Wave 0 |
| 16-01 sidebar | Sidebar renders for authenticated users (snapshot) | snapshot | `pest tests/Snapshot/SidebarTest.php` | ❌ Wave 0 |
| 16-02 rename | `beatrax:doctor` command resolves (every renamed signature is callable) | feature | `pest tests/Feature/BeatraxCommandsResolveTest.php` | ❌ Wave 0 |
| 16-02 rename | Composer name flipped to `beatrax/beatrax`; no `diederik` literal in `Modules/*` (greppable lock) | arch | `pest --filter noDiederikLiteralAfterRename` | ❌ Wave 0 |
| Phase 12 cleanup | `ImpersonateUserAction` class is absent | arch | `pest --filter impersonationSurfaceRemoved` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `pest --parallel --filter=DevMode` (~30s)
- **Per wave merge:** `composer test` (full suite) + `composer analyse` + `composer format:check`
- **Phase gate:** Full suite green + Larastan L10 strict clean + Pint clean before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/DevMode/tests/Pest.php` — module-level Pest config
- [ ] `Modules/DevMode/tests/TestCase.php` — module test base
- [ ] Module skeleton files (`DevModeServiceProvider`, `Routes/web.php`, `Resources/views/` placeholder)
- [ ] Update `composer.json` autoload-dev with `Modules\\DevMode\\Tests\\` PSR-4 mapping
- [ ] Update `bootstrap/providers.php` to register `DevModeServiceProvider`
- [ ] All 22 test files enumerated above
- [ ] `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` invariant added
- [ ] `BoundaryArchTest::impersonationSurfaceRemoved` invariant added
- [ ] `BoundaryArchTest::noDiederikLiteralAfterRename` invariant added
- [ ] `composer.json` adds `spatie/laravel-activitylog ^5.0` + `doctrine/sql-formatter ^1.5`
- [ ] `package.json` adds `fuse.js@^7.0`
- [ ] `config/activitylog.php` published with `table_name: dev_mode_audit`
- [ ] `config/database.php` adds `readonly_select` connection
- [ ] `config/logging.php` flips `single` → `daily` and adds the `tap` array on every channel
- [ ] `phpstan.neon` ignoreErrors entries for any Tokenizer-related deprecation noise (only if larastan flags `@internal` usage)

## Security Domain

> `security_enforcement` is enabled by default (config.json does not set it to false).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (consumed) | Existing Fortify + recovery codes (Phase 12); this phase only consumes `CurrentUser` |
| V3 Session Management | yes | The Advanced toggle (D-20) is a session-scoped flag; resets on login + first Dev Console load; standard Laravel session driver |
| V4 Access Control | yes (primary) | `EnsureDeveloperMode` middleware (D-06); arch invariant D-07 enforces the gate is applied; 404-not-403 masking; triple-gate (D-20/D-21/D-22) for destructive |
| V5 Input Validation | yes (primary) | Artisan arg-form validation via Laravel `validate()` (D-15 declarative schemas); SQL panel input validation via SelectOnlyValidator (D-45); typed-name modal hash_equals check (D-21) |
| V6 Cryptography | yes (consumed) | OAuth secrets are SQLite-encrypted by Phase 12's `OAuthSecretsRepository`; this phase only decrypts inside the scrub-set service; never logs decrypted values |
| V7 Error Handling and Logging | yes (primary) | Monolog redaction processor (D-29); audit log via spatie/laravel-activitylog (D-23); secrets must never reach disk or stream |
| V8 Data Protection | yes | Scrub-set busts on `OAuthSecret` save/delete (D-30); SQL panel runs against a read-only connection; the env snapshot redacts secret-suffix keys (D-44) |
| V12 Files and Resources | yes | Log tailer reads `storage/logs/laravel-*.log` only — path-pinned, never accepts user-supplied paths; artisan runner spawns whitelisted command names only |
| V14 Configuration | yes | Horizon iframe gated by both env flag AND `class_exists()` (D-38); SAFE/DESTRUCTIVE tier separation is configuration |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Authenticated non-developer enumerates dev surface via 403 vs 404 difference | Information Disclosure | `EnsureDeveloperMode` returns 404 (D-06); arch test guarantees every route applies it (D-07) |
| Destructive command run by mis-clicked button | Tampering | Triple-gate: Dev Mode ON + Advanced toggle ON + typed `beatrax` (D-20/D-21); server-side rejection (not UI-only) |
| OAuth tokens leak through Laravel log file or live stream | Information Disclosure | Belt+braces redaction (D-29): on-write Monolog tap on every channel + on-stream pipeline re-applies |
| SQL panel used to write/delete data | Tampering | Defense-in-depth: parse-time SelectOnlyValidator rejection + execution on `PRAGMA query_only=1` read-only connection (D-45) |
| Artisan command injection via arg form | Tampering | Declarative arg-form schemas with type validation (D-15); never string-concat command + args; pass as array to `Process::start([…])` |
| Process orphaned by browser disconnect | Denial of Service | `connection_aborted()` check in SSE loop; cancel endpoint exposes deliberate `SIGTERM`; cache TTL on `pid` + `run_id` (24h Q-OQ-3) |
| Log tailer reads outside `storage/logs/` | Information Disclosure | Log path pinned in controller; never accepts user input |
| Activity log table grows unbounded and exposes excerpts | Information Disclosure | 8KB excerpt cap (D-24) + Monolog redaction applied to excerpts before write (D-24); manual prune via `beatrax:prune-dev-audit` (D-26) |
| Race condition: `is_developer` flipped off while user has Dev Console open | Elevation of Privilege | Middleware runs on every request including `wire:poll`; next refresh returns 404 (Pitfall 6) |
| Horizon route exists in shipped build | Information Disclosure | Conditional registration on TWO signals (D-38: env flag AND class_exists); BoundaryArchTest::noHorizonImportsInShippedBuildCode already enforces |

## Sources

### Primary (HIGH confidence)

- [Livewire 4 wire:stream docs](https://livewire.laravel.com/docs/4.x/wire-stream) — `$this->stream()`, transport, Octane incompatibility
- [Laravel 13 Queues](https://laravel.com/docs/13.x/queues) — `Bus\Batch::cancel()`, FailedJobProviderInterface, queue:retry semantics
- [Laravel 11 Upgrade Guide](https://laravel.com/docs/11.x/upgrade) — Schema::getTables/getColumns/getIndexes replaced Doctrine DBAL
- [Symfony Process component](https://symfony.com/doc/current/components/process.html) — `start()`, `getIncrementalOutput()`, `stop()` signal
- [Symfony Logging Processors](https://symfony.com/doc/current/logging/processors.html) — Monolog ProcessorInterface pattern
- [SQLite PRAGMA reference](https://sqlite.org/pragma.html) — `query_only` produces SQLITE_READONLY error
- [packagist.org/packages/spatie/laravel-activitylog](https://packagist.org/packages/spatie/laravel-activitylog) — v5.0.0 release 2026-03-25, Laravel 12/13 compat
- [packagist.org/packages/doctrine/sql-formatter](https://packagist.org/packages/doctrine/sql-formatter) — 1.5.4 release 2026-02-08, PHP 8.1+
- [github.com/doctrine/sql-formatter — Tokenizer.php @internal marker](https://github.com/doctrine/sql-formatter/blob/master/src/Tokenizer.php) — VERIFIED via `curl` inspect of source
- [fluxui.dev/components/command](https://fluxui.dev/components/command) — Flux Pro command palette specs (single-list, no fuzzy ranking, Pro-only)
- [fluxui.dev/pricing](https://fluxui.dev/pricing) — Flux Pro pricing (locked-decision rationale)
- Existing project code: `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php`, `Modules/Desktop/Providers/DesktopServiceProvider.php`, `Modules/Desktop/Internal/Native/AppMenuBuilder.php`, `tests/Contracts/BoundaryArchTest.php`, `composer.json`, `package.json`, `database/migrations/2026_05_21_001844_create_jobs_table.php`, `database/migrations/2026_05_21_001844_create_job_batches_table.php`, `Modules/Core/Internal/Console/DoctorCommand.php`, `resources/views/layouts/app.blade.php`, `config/cache.php`

### Secondary (MEDIUM confidence)

- [serversideup.net Sending Server-Sent Events with Laravel](https://serversideup.net/blog/sending-server-sent-events-with-laravel/) — canonical SSE controller pattern
- [github.com/luka-mladenovic/laravel-monolog-processors-tap](https://github.com/luka-mladenovic/laravel-monolog-processors-tap) — Laravel tap class pattern for Monolog processors
- [kritimyantra.com — Laravel 12 with spatie activitylog](https://www.kritimyantra.com/blogs/laravel-12-with-spatie-laravel-activitylog-a-complete-guide) — config + table customization
- [livewire/livewire discussions #9520](https://github.com/livewire/livewire/discussions/9520) — Livewire 4 supersedes Volt

### Tertiary (LOW confidence, flagged)

- [greenlion/PHP-SQL-Parser](https://github.com/greenlion/PHP-SQL-Parser) — alternative SQL tokenizer if doctrine/sql-formatter Tokenizer becomes a problem; "non-validating" parser per its own README (still validates structure)
- [github.com/krisk/Fuse](https://github.com/krisk/Fuse) — Fuse.js homepage (industry-standard fuzzy search; version assumption A1)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every package is verified against Packagist + project composer.json
- Architecture: HIGH — mirrors the existing `Modules/Desktop/` precedent; reuses Phase 12/14/15 patterns
- Pitfalls: MEDIUM — three are HIGH-impact (Pitfall 1, 2, 3) and need planner attention; rest are operational
- SSE vs wire:stream choice: MEDIUM — locked decision is correct; Octane risk surfaced
- SQL validator choice: MEDIUM — locked Tokenizer works but is `@internal`; mitigation pattern provided
- activitylog version: HIGH — v5 required; v4 cannot resolve under Laravel 13

**Research date:** 2026-05-24
**Valid until:** 2026-06-24 (30 days; the underlying Laravel + Livewire + Flux + Symfony Process API surface is stable; the only freshness risk is doctrine/sql-formatter publishing a major version that reshapes the `@internal` Tokenizer)
