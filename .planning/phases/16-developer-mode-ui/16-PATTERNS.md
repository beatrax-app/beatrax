# Phase 16: Developer Mode UI — Pattern Map

**Mapped:** 2026-05-24
**Files analyzed:** ~55 new + ~12 modified
**Analogs found:** 52 of 55 new files have an in-repo analog. 3 patterns are absent (SSE, Monolog `tap`, Alpine global keybind) and must be created in Wave 0.

> Companion to `16-CONTEXT.md` and `16-RESEARCH.md`. The planner uses this map to assign analog references to per-plan action sections so executors can mirror the existing house style rather than reinvent it.

---

## Verified precedent files (RESEARCH claim → on-disk reality)

| RESEARCH-claimed path | Status | Notes |
|---|---|---|
| `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` | EXISTS (36 LoC) | 404-not-403, `CurrentUser` DI, `final readonly`. Direct clone target for `EnsureDeveloperMode`. |
| `Modules/Desktop/Providers/DesktopServiceProvider.php` | EXISTS (247 LoC) | Canonical module-provider shape: singletons in `register()`, `loadMigrationsFrom`/`loadRoutesFrom`/`loadViewsFrom`/`$livewire->component()` + `$events->listen()` in `boot()`. |
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` | EXISTS (94 LoC) | Verbatim-label public consts; `build(): list<MenuItem>`. Direct extension point for a Developer submenu (only when `is_developer=true`). |
| `tests/Contracts/BoundaryArchTest.php` | EXISTS (1,140+ LoC, ~22 `it()` blocks) | Hosts every cross-module + middleware-coverage invariant. Allow-list entries for `noAuthFacadeOrHelper` + `noHorizonImportsInShippedBuildCode` + `noNativePhpImportsOutsideDesktopModule` are the templates. |
| `Modules/Core/Internal/Console/DoctorCommand.php` | EXISTS (~140 LoC) | Driven by `/dev/doctor` panel via Process+SSE pipeline. |
| `resources/views/layouts/app.blade.php` | EXISTS (~190 LoC) | Body tag is the host for the global Alpine `x-data` keybind handler. Currently includes `auth::partials.impersonation-banner` (removed by D-11). |
| `config/cache.php` | EXISTS | Cache config (database driver) — heartbeat + run-registry write here. |
| `config/logging.php` | **MISSING** | File does NOT exist. Wave 0 must publish/create it with the `tap` registration on `daily` channel (D-27 + D-29). |
| `database/migrations/2026_05_21_001844_create_jobs_table.php` | EXISTS | Source schema for queue inspector reads. |
| `database/migrations/2026_05_21_001844_create_job_batches_table.php` | EXISTS | Source schema for queue inspector reads. |
| `composer.json` | EXISTS | Currently `name: diederik/diederik`. Phase 16-02 flips to `beatrax/beatrax`. No `spatie/laravel-activitylog`, no `doctrine/sql-formatter` yet — both added in 16-03+. |
| `package.json` | EXISTS (375 bytes — minimal) | `fuse.js` added in palette plan. |

**Additional precedents discovered (not in RESEARCH but load-bearing):**

| Path | Why it matters |
|---|---|
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (264 LoC) | Target for the Developer Mode toggle. Class-based Livewire `Component`, `#[Validate]` attributes, method-DI on `save()`/`setTheme()`/`render()`, no constructor DI (phpstan-strict-rules ban). |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` (218 LoC) | Visual analog for adding the Dev Mode toggle section: `<section class="space-y-2">` + `<h2 class="text-xs uppercase tracking-wide">` + plain `<button wire:click>` segmented controls, NOT Flux. |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` (177 LoC) | The current top-nav — REPLACED app-wide by 16-01 sidebar restructure. Reference the `$isActive` helper + active/hover classes pattern. |
| `Modules/Core/Internal/Http/Livewire/Dashboard.php` (236 LoC) + `dashboard.blade.php` | The `$isDeveloper` gating site (D-37) + `wire:poll` tile pattern reused by `/dev` overview. |
| `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | Closest analog for tabbed Livewire pages (`/dev/queue/{pending,failed,batches}`): `#[Url(as: 'tab')]` + `setTab()` allow-list guard. |
| `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php` + `rule-form-modal.blade.php` | Flux modal pattern with `Livewire.dispatch('rule-form:open')` + `<flux:modal name="rule-form" dismissible>` + `<flux:heading>`. Direct template for triple-gate modal + palette modal chrome. |
| `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | Stateless-`render()` + method-DI Livewire pattern; injected `SystemAlertQuery` + `CurrentUser`. Reused on every `/dev/*` page. |
| `Modules/Core/Internal/Listeners/HealthCheckListener.php` | Boot-time listener pattern wired in service-provider `boot()` via `$events->listen()`. Direct analog for `Queue::looping` heartbeat listener (D-39). |
| `Modules/Receipts/Internal/MatcherRegistry.php` (58 LoC) | `final` class with `readonly array` collaborator + priority dispatch — direct analog for `DevCommandRegistry`. |
| `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` (42 LoC) | `readonly array` keyed lookup with explicit unsupported-key exception — closest analog for `NavigationRegistry` / `AppActionRegistry`. |
| `Modules/Auth/Providers/AuthServiceProvider.php` line 82 | `$router->aliasMiddleware('developer', RequireDeveloperMiddleware::class)` — direct pattern for `'ensureDeveloperMode'` alias in `DevModeServiceProvider::boot()`. |
| `bootstrap/providers.php` | Confirmed: `class_exists()`-guarded conditional provider registration (Horizon) — direct pattern for D-38 iframe gate AND the new `DevModeServiceProvider::class` entry. |
| `app/Providers/HorizonServiceProvider.php` | Existing `config('app.dev_mode') !== true → return` early-exit pattern — the canonical "dev-mode-only feature" gate. |
| `Modules/Desktop/Routes/web.php` | Route group conventions: `Route::middleware(['web', 'auth'])->group(...)` + named routes. Direct analog for `/dev/*` group with `ensureDeveloperMode` middleware. |
| `Modules/Auth/Database/Migrations/2026_05_19_000002_add_is_developer_to_users_table.php` | The Phase 12 `is_developer` column already exists — Phase 16 reads it; does NOT add it. |

---

## New file → analog mapping

### Module skeleton (`Modules/DevMode/`)

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/composer.json` | `Modules/Desktop/composer.json` | Module composer manifest shape | Module name `devmode`; PSR-4 namespace `Modules\DevMode\` |
| `Modules/DevMode/Providers/DevModeServiceProvider.php` | `Modules/Desktop/Providers/DesktopServiceProvider.php` (247 LoC) | `register()` singletons + `boot()` shape: `loadMigrationsFrom` / `loadRoutesFrom` / `loadViewsFrom('dev')` / `$livewire->component()` for each page / `$events->listen()` for heartbeat + observer | Replace Desktop's NativePHP boot-gate with the DevMode-specific wires: alias `ensureDeveloperMode`, register `Queue::looping` heartbeat, attach `OAuthSecret` observer, bind `DevCommandRegistry`/`NavigationRegistry`/`AppActionRegistry` contracts |
| `Modules/DevMode/Routes/web.php` | `Modules/Desktop/Routes/web.php` (auth group) + `Modules/Auth/Routes/web.php` (middleware group) | `Route::middleware(['web', 'auth', 'ensureDeveloperMode'])->prefix('/dev')->group(...)` + named routes + Livewire-as-route pattern (`Route::get('/dev/artisan', ArtisanRunnerPage::class)`) | Add SSE controller routes outside the Livewire-component pattern; the 3 queue tabs hit the same `QueueInspectorPage::class` with route params |
| `Modules/DevMode/Routes/console.php` | `Modules/Auth/Routes/console.php`, `Modules/Core/Routes/console.php` | Console-route file convention | Empty in v1; planner judges whether to scaffold |
| `bootstrap/providers.php` (modified) | self | Existing `class_exists()`-guard array shape | Append `DevModeServiceProvider::class` (unconditional — DevMode is always registered; iframe is the only conditional surface) |

### Middleware + arch invariants

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php` | `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` (36 LoC) | `final readonly class`, constructor-DI on `CurrentUser`, `throw new NotFoundHttpException`, `Response` return type, `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` import | Namespace `Modules\DevMode\Internal\Http\Middleware`; class name `EnsureDeveloperMode`; identical body |
| `tests/Contracts/BoundaryArchTest.php` (modified) — `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` invariant | Existing `it('does not allow Native\\Desktop imports outside Modules/Desktop ...')` block at line 1260 + `noHorizonImportsInShippedBuildCode` block at 1197 (route-iteration patterns) | `it('does not allow ...')` Pest block; `Route::getRoutes()` walk; `expect()->each()` assertions; allow-list pattern | Filter routes by `str_starts_with(uri, 'dev')`; assert middleware aliases include `'ensureDeveloperMode'`. Plus delete the `ImpersonationBannerMiddleware` allow-list entry in `noAuthFacadeOrHelper` (line 1038, per D-11) |
| `tests/Contracts/BoundaryArchTest.php` (modified) — `noCrossModuleImportsFromDevMode` invariant | `noNativePhpImportsOutsideDesktopModule` block (line 1260) | Iterate `Modules/*/Internal/*` files; assert no `use Modules\DevMode\Internal\` imports outside `Modules/DevMode/` | Allow-list entry only for the cross-module Public contracts (DevCommandRegistry, NavigationRegistry, AppActionRegistry) |

### Whitelisted artisan runner + SSE streaming

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Process/CommandSpawner.php` | `Modules/Core/Internal/Console/Probes/ExternalToolVersionProbe.php` (already uses `Symfony\Process`) | `Symfony\Component\Process\Process` instantiation, `base_path()` cwd, exception shape | New: `start()` (non-blocking), persist `run_id`+`pid` to cache, return run_id |
| `Modules/DevMode/Internal/Process/RunRegistry.php` | `Modules/Receipts/Internal/MatcherRegistry.php` (58 LoC) | `final` class with `readonly` collaborator; explicit not-found exception shape | Cache-backed (not in-memory); CRUD on `(run_id, pid, command, args, started_at, status)` keyed under `dev_mode.run.{uuid}` |
| `Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php` | **NO ANALOG IN REPO** — `grep -l 'StreamedResponse\|response()->stream\|text/event-stream'` returns zero | Use `response()->stream($cb, 200, [...SSE headers])` from Laravel docs; loop while `$process->isRunning()`; `echo "data: ..."` + `ob_flush()`+`flush()`; `connection_aborted()` break; 150ms `usleep` | NEW PATTERN — Wave 0 must create a thin "streaming controller" reference. The RESEARCH § Pattern 2 code is the seed. Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no` |
| `Modules/DevMode/Internal/Http/Controllers/ArtisanCancelController.php` | `Modules/Desktop/Internal/Http/CloseActionController.php` | Single-invokable controller (`__invoke`) + DI on collaborators + 204/JSON return | Resolve `RunRegistry`, call `Process->stop(grace: 3, signal: SIGTERM)` |
| `Modules/DevMode/Public/Contracts/DevCommandRegistry.php` (interface) | `Modules/Receipts/Public/Contracts/SenderMatcher.php` (Public contract pattern) | Public interface in `Public/Contracts/`; concrete in `Internal/` | New methods: `safe(): list<CommandSpec>`, `destructive(): list<CommandSpec>`, `find(string $name): CommandSpec` |
| `Modules/DevMode/Internal/CommandRegistry.php` (concrete) | `Modules/Receipts/Internal/MatcherRegistry.php` (58 LoC) | `final` class + `readonly array` of specs + dispatch/lookup | Replace dispatch with `safe()`/`destructive()` partitioning; CommandSpec DTO (use `spatie/laravel-data` since it's already in `composer.json`) |
| `Modules/DevMode/Public/Dto/CommandSpec.php` | Any existing `Modules/*/Public/Dto/*.php` (e.g. `MatcherInputDto`) | `spatie/laravel-data` `Data` subclass, readonly properties | Fields: `name`, `label`, `tier (enum)`, `argsSchema (list<ArgSpec>)`, `description?` |
| `Modules/DevMode/Internal/Http/Livewire/ArtisanRunnerPage.php` | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | Livewire `Component`; `#[Url]` filter attrs; method-DI on `render()` + action handlers; `final class` | Add EventSource-subscribe + `wire:poll` for run-card timeline; run-card list driven by `dev_mode_audit` query |
| `Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php` | `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` | Tabbed page with filter chips; section labels; cards | New: per-run `<x-dev::run-card>` cards with SSE-driven `<pre>` output; Flux modal trigger for arg form |
| `Modules/DevMode/Resources/views/components/run-card.blade.php` | `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` row partial + UI-SPEC § Run-card sketch | Reusable Blade component (`<x-dev::run-card :run="$run" />`) | Implement `.run-card` / `.run-card-head` / `.run-card-out` / `.run-card-actions` per UI-SPEC; state-color borders via class binding |

### Triple-gate confirmation

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php` | `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php` (192 LoC) | `Livewire.dispatch('triple-gate:open', { command, args })` open event; `modal-hide` close event; `#[Validate]` on typed-name property; method-DI on `confirm()` | Server-side `hash_equals('beatrax', $typed)` guard; reads session `dev_mode.advanced` flag; emits `triple-gate:confirmed` |
| `Modules/DevMode/Resources/views/livewire/triple-gate-modal.blade.php` | `Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php` | `<flux:modal name="triple-gate" dismissible="false">` + `<flux:heading>` + `<form wire:submit>` + Save/Cancel buttons | Rose-tinted header surface; `.gate-cmd` mono preview row; disabled-until-match primary button (`Run {command-name}`); `dismissible="false"` (click-outside does NOT close per UI-SPEC) |
| `Modules/DevMode/Internal/Http/Controllers/AdvancedToggleController.php` | `Modules/Desktop/Internal/Http/CloseActionController.php` | Invokable controller writing to session | Toggle session key `dev_mode.advanced`; reset on Login event (extra `$events->listen` in DevModeServiceProvider mirroring `ContinuePendingFileIntentAfterLogin`) |

### Audit log (`dev_mode_audit`)

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php` | `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php` | Migration skeleton: `final` anonymous class, `Schema::create`, `bigIncrements`, `string`/`json`/`timestamp` columns, indexes | Columns per D-24: `command`, `args` (json), `tier`, `caller_user_id`, `started_at`, `finished_at`, `exit_code`, `stdout_excerpt`, `error_excerpt`. Index on `(caller_user_id, started_at)` + `(tier, started_at)`. (Note: this is the spatie/laravel-activitylog table with `properties=args`, renamed per D-23.) |
| `Modules/DevMode/Public/Contracts/AuditWriter.php` (interface) | `Modules/Core/Public/Contracts/Clock.php` | Public interface convention | Methods: `recordCommandRun(...)`, `recordDestructiveQueueAction(...)`, `recordSelectQuery(...)` |
| `Modules/DevMode/Internal/Audit/SpatieAuditWriter.php` | Existing facade-free service classes (e.g. `Modules/Core/Public/Services/SystemAlertQuery.php`) | Constructor-DI on `CurrentUser` + `Clock`; thin wrapper over a vendor library | Wraps `Spatie\Activitylog\ActivityLogger`; runs each excerpt through `RedactSecretsProcessor` (D-24); SPATIE VERSION: research recommends `^5.0` over CONTEXT D-23's `^4.12` — planner must reconcile |
| `Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php` | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | `#[Url]` filter attrs + tab switching + cursor pagination | Filter by tier/caller/command; severity coloring (non-zero exit → rose, destructive → amber) |
| `Modules/DevMode/Internal/Console/PruneDevAuditCommand.php` | `Modules/Core/Internal/Console/BackupDatabaseCommand.php` | Console-command shape; `--older-than` option; DI on writer/repo; no facades | New: command name `beatrax:prune-dev-audit`; deletes rows by cutoff |

### Log tailer + Monolog redaction

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `config/logging.php` (NEW — file is missing from repo) | **NO ANALOG** — must be created from Laravel skeleton publish | Standard Laravel logging-config skeleton with `single` / `daily` / `stack` channels | Default channel `daily` (D-27); every channel adds `'tap' => [PushRedactProcessor::class]`. **Wave 0 work.** |
| `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php` | **NO ANALOG IN REPO** — RESEARCH § Pattern 4 is the seed | Monolog `Psr\Log\ProcessorInterface`; `__invoke(LogRecord)` returns new record via `$record->with(...)` | Regex Bearer + JWT shapes; `str_replace` over `OAuthScrubSet::all()`; pure server-side |
| `Modules/DevMode/Internal/Logging/PushRedactProcessor.php` | **NO ANALOG** — Laravel `tap` class pattern | Invokable class accepting `Logger`; `foreach getHandlers() → pushProcessor(...)` | Resolves `RedactSecretsProcessor` via container |
| `Modules/DevMode/Internal/Services/OAuthScrubSet.php` | `Modules/Core/Public/Services/SystemAlertQuery.php` | Service-class shape with constructor DI; explicit return-type list | Singleton (registered in provider); holds `?array $set` cache; reads `Modules/EmailScan/Public/Services/OAuthSecretsRepository` |
| `Modules/DevMode/Internal/Listeners/BustOAuthScrubSetOnSecretChange.php` | `Modules/Core/Internal/Listeners/HealthCheckListener.php` | Listener-class shape; final invokable handler; container singleton | Subscribes to Eloquent `saved`+`deleted` events on `OAuthSecret`; calls `OAuthScrubSet::bust()` |
| `Modules/DevMode/Internal/Http/Controllers/LogStreamController.php` | Same as `ArtisanStreamController.php` — see SSE section | `response()->stream(...)` with the same headers | `fopen` log file, `fseek` to EOF, `clearstatcache` + `fseek` loop at 250ms; detect inode/size shrinkage (rotation) and re-open offset 0 |
| `Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php` | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` | Livewire page with `#[Url]` filters + method-DI render | Severity multi-select, channel filter, contains-filter; 10k-line scrollback ring buffer; EventSource consumer in Blade with Pause/Resume |
| `Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php` | `Modules/Core/Resources/views/livewire/dashboard.blade.php` (Alpine + wire:poll patterns) | Alpine `x-data` ring buffer (10k lines); EventSource consumer | New: pre/mono lines + click-to-expand context ±10 lines |

### Queue inspector

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php` | `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` (tab pattern) | `#[Url(as: 'tab', except: 'pending')]`, `setTab()` allow-list guard, single Livewire component for multiple routes | Three tabs (`pending`/`failed`/`batches`) backed by `jobs`/`failed_jobs`/`job_batches` Eloquent reads; `wire:poll(5s)` on count tiles; bulk-select |
| `Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php` | `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` | Tabbed table layout; per-row action buttons; inline-expand panels | New: `.table` primitive per UI-SPEC (9px 10px cell padding, tabular-nums); inline JSON payload viewer (redacted) |
| `Modules/DevMode/Public/Models/Job.php` + `FailedJob.php` + `JobBatch.php` | `Modules/Core/Models/User.php`, `Modules/Core/Models/SystemAlert.php` | Plain Eloquent model in `Models/`; explicit `$table`; final class | Read-only; no fillable; mappers for serialized payload JSON. NOTE: keep direct Eloquent (CLAUDE.md exempts Eloquent from DI rule) |
| `Modules/DevMode/Internal/Queue/QueueActions.php` | Existing action-class pattern (e.g. `Modules/Auth/Public/Actions/LogoutAction.php`) | Invokable / single-method action class; constructor DI | Methods inject `Illuminate\Contracts\Queue\Factory` + `Illuminate\Bus\BatchRepository`; emit `DevCommandExecuted` event for audit |
| `Modules/Core/Resources/views/livewire/dashboard.blade.php` (modified, line ~280) | self | Existing `@if ($failedChainResolutionExists)` branch | Add `&& $isDeveloper` guard (D-37); retarget `/horizon/failed` → `/dev/queue/failed`. Non-devs see nothing (channel = `SystemAlertsBanner`) |

### Doctor panel

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Livewire/DoctorPanelPage.php` | Same as `ArtisanRunnerPage.php` | EventSource subscriber + Run button | Specialised: triggers fixed `beatrax:doctor` command; parses streamed lines into pass/warn/fail row data |
| `Modules/DevMode/Internal/Doctor/ProbeOutputParser.php` | `Modules/Core/Internal/Console/Probes/*.php` (existing probe shape) | Pure parser; no IO; tested in isolation | New: regex-matches the `DoctorCommand` line format into `[status, label, detail]` rows |

### SELECT-only SQL panel + schema viewer

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` | `Modules/Ledger/Internal/.../*.php` validator-style classes (or any pure-function service in repo) | `final` class; throws explicit ValidationException; pure logic | Wraps `Doctrine\SqlFormatter\Tokenizer` (marked `@internal` — wrap inside this class so the seam is owned per RESEARCH Q2); first non-comment token must be `SELECT`; regex semicolon-then-non-whitespace reject |
| `Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php` | `Modules/Core/Public/Services/UserDataPathService.php` (path-resolving service) | DI on `DatabaseManager`; service-class shape | Clones default connection; sets `PRAGMA query_only=1`; `PDO::ATTR_TIMEOUT=5` |
| `Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php` | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` (form + results page) | Form input + results table; method-DI render | New: triple-gate check on Advanced toggle (no typed-name per D-46); audit row per query (`action: sql.select`) |
| `Modules/DevMode/Internal/Http/Livewire/SchemaViewerPage.php` | `Modules/Categorization/Internal/Http/Livewire/RulesPage.php` | Sidebar list + detail pane | Reads `Schema::getTables()`/`getColumns()`/`getIndexes()`; "Browse" reuses `SqlPanelPage` execution path (`SELECT * FROM <table> LIMIT 100`) |

### `/dev/system` env snapshot

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php` | `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (read-only render) | Stateless page; method-DI render | Reads `UserDataPathService`, `php_uname`, `phpversion`, `extension_loaded`, `config()` flattened with denylist redaction (`*password*`, `*secret*`, `*key`, `*token*`) |
| `Modules/DevMode/Internal/System/ConfigFlattener.php` | `Modules/Core/Internal/Console/Probes/*.php` (pure-utility shape) | Pure function class; no IO | Recursive flatten with denylist redaction |

### `/dev` overview + Horizon iframe

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php` | `Modules/Core/Internal/Http/Livewire/Dashboard.php` (236 LoC, tile-based dashboard) | Tile-based dashboard with `wire:poll`; method-DI render; multiple queries injected | Tiles per UI-SPEC: "Worker heartbeat" / "Queue" / "Last command" / "Recent runs" / "Open alerts"; console-pane is the primary visual anchor (theme-locked dark inset) |
| `Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php` | `Modules/Core/Resources/views/livewire/dashboard.blade.php` | Card-grid layout, header tiles | New: `.console-pane` (fixed dark) + sparkline SVG (220×32 emerald polyline, server-rendered static) + tail-tail mono 8-line rollup |
| `Modules/DevMode/Internal/Http/Livewire/HorizonFramePage.php` | `Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php` (thin Livewire wrapper) | Minimal Livewire component | Renders single `<iframe src="/horizon">`; route conditionally registered via `class_exists(\Laravel\Horizon\HorizonServiceProvider::class)` AND `config('app.dev_mode') === true` (same composition as `bootstrap/providers.php`) |

### Worker heartbeat

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Internal/Listeners/WriteWorkerHeartbeat.php` | `Modules/Core/Internal/Listeners/HealthCheckListener.php` | Invokable listener class; `final`; constructor DI on `Clock` + `CacheRepository` | Wired via `Queue::looping($listener)` in `DevModeServiceProvider::boot()`; writes `cache('dev_mode.queue_worker_heartbeat', now()->timestamp, ttl=60)` |

### Command palette (⌘K / Ctrl+K)

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/DevMode/Public/Contracts/NavigationRegistry.php` | `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` (42 LoC) | Interface + readonly-array concrete; explicit unsupported-key exception | Returns `list<NavigationEntry>` for the palette JSON |
| `Modules/DevMode/Public/Contracts/AppActionRegistry.php` | Same as above | Same shape | Returns `list<AppAction>` (Phase 15 app-menu entries) |
| `Modules/DevMode/Internal/Navigation/NavigationRegistryImpl.php` | `Modules/Receipts/Internal/MatcherRegistry.php` (58 LoC) | Concrete class; `readonly array` of entries | Bound in `DevModeServiceProvider::register()`; entries cross-injected from each module's service provider (similar to Phase 15 menu-extension pattern) |
| `Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php` | `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php` | Global Livewire modal mounted in base layout; dispatched via event; method-DI render | Emits server JSON registry on mount (all 3 sources merged + filtered by `is_developer`); Fuse.js client-side rank |
| `Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php` | `Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php` | `<flux:modal name="palette" dismissible>` chrome | New: 760px width (collapses to 640px <1100px); split-pane (180px rail + results); `.palette-input` + `.palette-row` + `.palette-source` chips per UI-SPEC |
| `package.json` (modified) | self | Existing JSON shape | Add `"fuse.js": "^7.0"` to dependencies |
| `resources/views/layouts/app.blade.php` (modified — `<body>` Alpine x-data) | **NO ANALOG IN REPO** — closest is `Modules/Categorization/Resources/views/livewire/rule-form-modal.blade.php` which uses `Livewire.dispatch` from a button, not a global keybind | Existing per-component Alpine pattern | NEW: global `x-data` on `<body>` listening for `keydown.cmd.k` / `keydown.ctrl.k` / `keydown.cmd.period`; dispatches `palette:open` Livewire event. Use `event.metaKey \|\| event.ctrlKey` per D-42 (Wave 0 invents this body-level pattern) |

### Settings → Developer Mode toggle

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (modified) | self | Existing `setTheme()` instant-apply pattern; `#[Validate]`; method-DI on save | Add `setDevMode(bool)` method; writes `users.is_developer`; gated by `CurrentUser`+ Phase 12 "first signup = developer" rule. New `#[Validate('boolean')] public bool $isDeveloper` property. (NOTE: this is a UI surface on the existing toggle; `is_developer` column already exists from Phase 12.) |
| `Modules/Core/Resources/views/livewire/settings-page.blade.php` (modified) | self | Existing `<section class="space-y-2">` + `<h2 class="text-xs uppercase tracking-wide">` + `wire:click` segmented control pattern | Add "Developer" section with toggle switch (`.switch` primitive per UI-SPEC, emerald-when-on); copy: "Show the Dev Console at /dev. Resets the Advanced toggle on every login." |

### App-menu Developer submenu

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` (modified) | self | Existing verbatim-label const pattern; `build()` array shape | Add Developer submenu after Help, conditional on `is_developer=true` (inject `CurrentUser` into `build()`). Submenu: "Open Dev Console" / "⌘K Run a command" |
| `Modules/DevMode/Internal/Native/DeveloperSubmenuContributor.php` (optional) | `Modules/Desktop/Providers/DesktopServiceProvider.php` cross-module contributor pattern | Singleton service contributed to AppMenuBuilder | Only if planner chooses extension-via-DI over direct AppMenuBuilder edit (Discretion item) |

### App-wide sidebar restructure (16-01)

| New file | Closest analog | What to mirror | What to change |
|---|---|---|---|
| `Modules/Core/Internal/Http/Livewire/AppSidebar.php` (replaces `TopNav.php`) | `Modules/Core/Internal/Http/Livewire/TopNav.php` (51 LoC) | Existing stateless render with method-DI; `$currentPath` route detection | Sectioned sidebar layout (248px); sections per UI-SPEC: "THIS MONTH" / "MONEY" / settings + account + version + search row |
| `Modules/Core/Resources/views/livewire/app-sidebar.blade.php` (replaces `top-nav.blade.php`) | `Modules/Core/Resources/views/livewire/top-nav.blade.php` (177 LoC) | Existing `$isActive` helper + nav-link class pattern | Sidebar markup + `.side-section-label` / `.side-item` / `.side-badge` / `.side-dev-block` (server-side gated on `$isDeveloper` — DOM-absent for non-devs) / `.dot-live` per UI-SPEC + sketch findings |
| `resources/views/layouts/app.blade.php` (modified) | self | Existing slot-based layout | Replace `@livewire('core.top-nav')` with `@livewire('core.app-sidebar')`; remove `auth::partials.impersonation-banner` include (D-11); add Alpine `x-data` on `<body>` for keybind (see palette row above) |
| `resources/views/layouts/dev-shell.blade.php` (NEW — inside DevMode module) | `resources/views/layouts/app.blade.php` | Existing layout shape (head + body + slots) | Renders the Dev Console sidebar (220px, hard-swap not nested per UI-SPEC); "← Back to app" foot link; embed `@livewire('core.system-alerts-banner')` |

### Rename plan (16-02 — `diederik` → `beatrax`)

| Modified files | Pattern source | What to change |
|---|---|---|
| `composer.json` | self | `name: diederik/diederik` → `name: beatrax/beatrax`; update github URLs in `Modules/Desktop/Internal/Native/AppMenuBuilder.php` consts (`GITHUB_REPO_URL`, `REPORT_ISSUE_URL`, `HELP_ABOUT`); update PSR-4 root literal in `autoload` if applicable |
| Every `Modules/*/Internal/Console/*.php` with `protected $signature = 'diederik:...'` | grep sweep | Flip `diederik:*` → `beatrax:*` (RESEARCH lists: `BackupDatabaseCommand`, `DoctorCommand`, `FailedJobsCommand`, `InstallCommand`, `ResetPasswordCommand`, `RederiveFingerprintsCommand`, plus any others surfaced by the sweep) |
| Every test file calling `$this->artisan('diederik:...')` | grep sweep | Flip literals; tests stay green between commits per planner's Discretion |
| `config/nativephp.php` (env pass-through list) | existing env pass-through array | `DIEDERIK_DEV_MODE` → `BEATRAX_DEV_MODE` (note: `config('app.dev_mode')` KEY stays per D-08) |
| `config/nativephp.php` macOS bundle id | existing `bundle_id` field | `com.diederik.*` → `com.beatrax.*` |
| Herd hostname (`.test` files / docs) | existing Herd config | `diederik.test` → `beatrax.test` |
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` HELP_ABOUT const | self | `'About diederik'` → `'About beatrax'` |
| Layout title literal in `resources/views/layouts/app.blade.php` | self | `$title ?? 'diederik'` → `'beatrax'` |
| `Modules/Core/Resources/views/livewire/top-nav.blade.php` line 12 brand text | self | `>diederik<` → `>beatrax<` (replaced anyway by sidebar restructure; only matters if 16-01 lands after 16-02 — per D-01 it lands before, so this becomes a one-liner in `app-sidebar.blade.php` instead) |
| Every `.planning/*.md` mentioning `diederik` / `Diederik` | grep sweep | Find/replace; pre-rename plans already use `beatrax` in copy per D-10 |

### Phase 12 impersonation deletion (folded into 16)

| Deleted files | Notes |
|---|---|
| `Modules/Auth/Public/Actions/ImpersonateUserAction.php` | D-11 |
| `Modules/Auth/Public/Actions/EndImpersonationAction.php` | D-11 |
| `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` | D-11 |
| `Modules/Auth/Resources/views/partials/impersonation-banner.blade.php` (and any Blade include) | D-11 |
| `Modules/Auth/tests/Feature/ImpersonationBannerTest.php` | D-11 |
| `Modules/Auth/tests/Feature/ImpersonationActionTest.php` | D-11 |
| Impersonation assertions inside `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` (partial delete) | D-11 |
| `Modules/Auth/Routes/web.php` impersonation route entries (`ImpersonateUserAction`, `EndImpersonationAction` imports + routes) | grep-sweep for `ImpersonateUserAction`, `EndImpersonationAction`, `ImpersonationResult` imports |
| `tests/Contracts/BoundaryArchTest.php` allow-list line for `ImpersonationBannerMiddleware` inside `noAuthFacadeOrHelper` (line ~1038) | D-11 + D-24 reference |
| `auth::partials.impersonation-banner` include in `resources/views/layouts/app.blade.php` | D-11 |

---

## Shared patterns (apply across multiple new files)

### Pattern A — DI-only constructor for services and middleware
**Source:** `Modules/Auth/Internal/Http/Middleware/RequireDeveloperMiddleware.php` (36 LoC)
**Apply to:** Every new middleware, service, listener, registry, validator. NOT to Livewire `Component` subclasses (phpstan-strict-rules ban — use method-DI instead, per `SettingsPage`/`DriftPage`/`SystemAlertsBanner`).
**Mirror:** `final readonly class` + constructor `private CurrentUser $currentUser` + no facades + no global helpers. Eloquent models direct is allowed (CLAUDE.md MEMORY).

### Pattern B — Livewire `Component` method-DI on `render()` + actions
**Source:** `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php`, `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`, `Modules/Core/Internal/Http/Livewire/SettingsPage.php`
**Apply to:** Every new `Modules/DevMode/Internal/Http/Livewire/*Page.php` and `*Modal.php`.
**Mirror:** No constructor; `#[Validate]` attributes on properties; `render(CurrentUser $user, ViewFactory $views, ...): View`; action methods take their own collaborator params.

### Pattern C — Public/Internal split + Public contract for cross-module reach
**Source:** `Modules/Receipts/Public/Contracts/SenderMatcher.php` (interface) + `Modules/Receipts/Internal/MatcherRegistry.php` (concrete), `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php`
**Apply to:** `DevCommandRegistry`, `NavigationRegistry`, `AppActionRegistry`, `AuditWriter` — every cross-module reach into DevMode.
**Mirror:** Interface in `Public/Contracts/`, concrete in `Internal/`, bind in `DevModeServiceProvider::register()`. Other modules import the contract, never the concrete.

### Pattern D — Module service provider boot wiring
**Source:** `Modules/Desktop/Providers/DesktopServiceProvider.php` (247 LoC)
**Apply to:** `DevModeServiceProvider.php`.
**Mirror:** `register()` singletons; `boot(LivewireManager $livewire, Dispatcher $events)`; `$livewire->component()` for each page; `$events->listen()` for each listener; `$router->aliasMiddleware()` for middleware alias; `loadMigrationsFrom`/`loadRoutesFrom`/`loadViewsFrom`.

### Pattern E — Conditional provider registration via `class_exists()`
**Source:** `bootstrap/providers.php` (Horizon entry)
**Apply to:** `/dev/horizon` route registration (D-38).
**Mirror:** Wrap the route closure (or a sub-provider) in `if (class_exists(\Laravel\Horizon\HorizonServiceProvider::class) && config('app.dev_mode') === true)`.

### Pattern F — Arch invariant in Pest
**Source:** `tests/Contracts/BoundaryArchTest.php` lines 1197 (`noHorizonImportsInShippedBuildCode`) and 1260 (`noNativePhpImportsOutsideDesktopModule`)
**Apply to:** Both new invariants (D-07 + cross-module imports from DevMode).
**Mirror:** `it('does not allow ...', function (): void { ... })` Pest block; iterate filesystem; build allow-list array; `expect($actual)->toEqual($expected)` with the allow-list subtracted.

### Pattern G — Migration shape
**Source:** `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php`
**Apply to:** `2026_05_24_000001_create_dev_mode_audit_table.php`.
**Mirror:** Anonymous-class migration (per `phpstan.neon` rule noted at BoundaryArchTest line 1140); `Schema::create` + `bigIncrements` + `string`/`json`/`timestamp` + indexes.

### Pattern H — Flux modal globally mounted
**Source:** `Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php` + `rule-form-modal.blade.php`
**Apply to:** TripleGateModal, CommandPaletteModal.
**Mirror:** `<flux:modal name="...">`; mount globally via `@livewire('dev.triple-gate-modal')` / `@livewire('dev.command-palette-modal')` in base layout; dispatch via `Livewire.dispatch('triple-gate:open', { ... })`; close via `modal-hide`.

### Pattern I — Tab-on-one-Livewire-page with deep-linkable URL
**Source:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`
**Apply to:** `QueueInspectorPage` (`/dev/queue/{pending,failed,batches}`).
**Mirror:** `#[Url(as: 'tab', except: 'pending')] public string $tab`; `setTab()` with allow-list guard returning early on unknown values.

### Pattern J — Listener wired via `$events->listen()` in provider `boot()`
**Source:** `Modules/Desktop/Providers/DesktopServiceProvider.php` lines 178+ + `Modules/Core/Internal/Listeners/HealthCheckListener.php`
**Apply to:** `WriteWorkerHeartbeat` (Queue::looping), `BustOAuthScrubSetOnSecretChange` (Eloquent observer attach), `AdvancedToggleResetOnLogin` (Auth Login event).
**Mirror:** Singleton listener in `register()`; `$events->listen(...)` in `boot()`. `Queue::looping` is registered the same way (callable into the Queue facade — Eloquent/queue exemption).

### Pattern K — Single-invokable controller for non-Livewire HTTP
**Source:** `Modules/Desktop/Internal/Http/CloseActionController.php`, `Modules/Desktop/Internal/Http/FileOpenController.php`
**Apply to:** `ArtisanStreamController`, `ArtisanCancelController`, `LogStreamController`, `AdvancedToggleController`.
**Mirror:** `final class X { public function __invoke(...): Response {} }`; constructor DI on collaborators.

### Pattern L — Theme tokens via Tailwind v4 `@theme` (UI-SPEC § Theme Wiring)
**Source:** `resources/css/app.css` (current — Tailwind v4 + `@custom-variant dark`)
**Apply to:** All new DevMode Blade components.
**Mirror:** UI-SPEC § Theme Wiring locks the full token block to hand-port; use Tailwind utilities or `var(--color-text)` refs. Honor the three theme-locked dark insets (console pane, run-card-out, gate-cmd) inline rather than via `.dark` flip.

---

## Missing patterns — must be created in Wave 0

These have NO analog in the repo. The planner must allocate explicit Wave 0 work to invent them before downstream plans depend on them.

| Missing pattern | Where it lands | Reference for Wave 0 |
|---|---|---|
| **SSE / StreamedResponse controller** | `Modules/DevMode/Internal/Http/Controllers/ArtisanStreamController.php` (+ `LogStreamController.php`) | RESEARCH § Pattern 2 (full reference implementation). Standard Laravel `response()->stream()` + Symfony Process `getIncrementalOutput()`. Three controllers will share the SSE-header + loop scaffold — extract a tiny base trait/abstract or just duplicate (planner's call). |
| **Monolog `tap` class registered in `config/logging.php`** | `Modules/DevMode/Internal/Logging/PushRedactProcessor.php` + `RedactSecretsProcessor.php` | RESEARCH § Pattern 4. Also: `config/logging.php` is absent from the repo — Wave 0 must publish the Laravel skeleton (`php artisan config:publish logging`) before adding the `tap` registration. |
| **Global `<body>` Alpine x-data keybind handler** | `resources/views/layouts/app.blade.php` body tag | UI-SPEC § Command Palette + D-42. Every existing Alpine usage is per-component (e.g. `rule-form-modal.blade.php` inside the Livewire root); the body-level pattern is new. Implementation seed: `x-data` listening for `keydown.cmd.k.window.prevent` + `keydown.ctrl.k.window.prevent` + `keydown.cmd.period.window.prevent`, dispatching `Livewire.dispatch('palette:open')` / `Livewire.dispatch('navigate-to-dev')`. |
| **Fuse.js client-side fuzzy-search wired into a Livewire modal** | `command-palette-modal.blade.php` | RESEARCH Q7. No existing JS-library-inside-Livewire-modal example in repo. Add `fuse.js` to `package.json`, import in the modal's `<x-data>` block, ingest a JSON registry the server emits on mount. |
| **`spatie/laravel-activitylog` install + table-rename migration** | `Modules/DevMode/Database/Migrations/...` | First spatie package in the project. Two issues to reconcile: (1) CONTEXT says `^4.12` but RESEARCH § Standard Stack flags `^5.0` as the only Laravel-13-compatible release — planner must reconcile; (2) the table-name override goes through `config('activitylog.table_name')` (per spatie docs) plus a migration to rename. |
| **`doctrine/sql-formatter` wrapper around `@internal` Tokenizer** | `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` | RESEARCH Q2 mitigation: wrap the `@internal` `Tokenizer` behind this class so future upstream removal hits one file. Add a Pest contract test (`tests/Contracts/SelectOnlyValidatorContractTest.php`) that locks the public-facing assertions — sits alongside the existing contract tests in `tests/Contracts/`. |

---

## Metadata

**Analog search scope:** `Modules/*` (all 14 modules), `tests/Contracts/`, `app/Providers/`, `bootstrap/`, `config/`, `database/migrations/`, `resources/views/`. Excluded `vendor/`, `node_modules/`, `nativephp/electron/dist/`, `storage/`.
**Files scanned:** ~120 Livewire classes / Blade views / providers / migrations / contracts.
**Heaviest analogs read in full:** `DesktopServiceProvider` (247 LoC), `RequireDeveloperMiddleware` (36 LoC), `MatcherRegistry` (58 LoC), `SourceAdapterRegistry` (42 LoC), `AppMenuBuilder` (94 LoC), `SettingsPage.php` (264 LoC, head only), `app.blade.php` (190 LoC, head only).
**Pattern extraction date:** 2026-05-24
