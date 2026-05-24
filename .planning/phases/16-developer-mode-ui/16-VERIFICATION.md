---
phase: 16-developer-mode-ui
verified: 2026-05-24T17:09:02Z
status: human_needed
score: 9/9 must-haves verified (DEVUI-01..09)
overrides_applied: 0
re_verification: false
human_verification:
  - test: "⌘K palette opens on macOS (Cmd+K)"
    expected: "Composer dev, sign in as developer, press ⌘K — palette modal opens with view + dev + action rows; ZERO destructive command names shown."
    why_human: "Real-keyboard handler — Pest tests verify server-emitted JSON + Alpine factory exists; in-browser ⌘K capture is OS-keyboard event handling."
  - test: "I-7 keybind carve-out — ⌘K inside an input/textarea does NOT open palette"
    expected: "Focus a text input on /transactions; type a query; press ⌘K — palette does NOT open (the body onKey early-returns on INPUT/TEXTAREA/contentEditable)."
    why_human: "Real-keyboard + focus interaction. The Alpine x-data handler short-circuits via document.activeElement.tagName check that can only be exercised in a live browser."
  - test: "App-menu Developer submenu shows only for is_developer=true (NativePHP)"
    expected: "Build native bundle; sign in as developer — menu shows. Toggle is_developer=false in Settings, quit bundle, relaunch — Developer submenu absent."
    why_human: "NativePHP app-menu binding is runtime electron-bound; full bundle quit + relaunch is the operator workflow. RESEARCH Pitfall 9."
  - test: "Settings → Developer Mode toggle persists across logout/login"
    expected: "Toggle on → log out → log in → toggle still on (DB-persisted, not session-scoped)."
    why_human: "Pest covers the persistence via setDevMode() + DB write; the round-trip across logout requires real session reset cookie clearing."
  - test: "Live stdout streaming feels sub-second for db:backup"
    expected: "Run db:backup from /dev/artisan; verify lines stream to UI within ~500ms of process emit (SSE perceived latency)."
    why_human: "Subjective UX latency measurement; the spawn + SSE + FileTailer code paths are unit-tested but felt-latency requires a stopwatch."
  - test: "Triple-gate modal UX (typed-name + Esc + Cancel only — click-outside does NOT close)"
    expected: "Open destructive db:restore confirm; click outside scrim — modal stays. Press Esc — closes. Type 'Beatrax' (wrong case) — Run button stays disabled. Type 'beatrax' — button enables → Run fires."
    why_human: "Click-outside-doesn't-close UX + case-sensitive typed-name modal — server-side enforcement is Pest-covered (DestructiveSpawnController re-validates), but the no-close-on-scrim Alpine behaviour is felt in browser."
  - test: "B-2 fallback runner modal lists ONLY SAFE-tier commands (visual scan)"
    expected: "Open /dev/artisan fallback Run-command modal; visually verify NO db:restore / migrate:fresh / beatrax:install / beatrax:reset-password / beatrax:grant-dev / beatrax:regenerate-recovery-codes button appears."
    why_human: "Visual UI scan — Pest test ArtisanRunnerSafeTierTest::it lists only SAFE commands is the automated half; the in-browser visual confirmation is the felt complement."
  - test: "Log tailer rotation detection survives manual file rotate"
    expected: "Tail /dev/logs; delete or recreate today's laravel-YYYY-MM-DD.log; verify stream re-opens without crashing (tri-signal: inode change OR shrinkage OR path change)."
    why_human: "OS-dependent file-system semantics — Pest covers the inode/path-change paths in isolation; the cross-system rotation behavior is felt."
  - test: "Horizon iframe loads correctly under NativePHP runtime"
    expected: "composer dev; visit /dev/horizon (dev_mode=true + Horizon class_exists); verify Horizon UI loads inside iframe with mouse + keyboard working."
    why_human: "Renders external app inside iframe — Pest verifies the route registers conditionally; the iframe rendering + Horizon-app interaction is browser-side."
  - test: "W-6 /dev/sql runaway query is killed by set_time_limit(5)"
    expected: "Run a deliberate cross-join SELECT (e.g. `SELECT * FROM big_table a, big_table b LIMIT 1`) over a large seeded dataset; verify it dies before 6 seconds."
    why_human: "Wall-clock assertion is flaky in automated tests; WallClockCap::apply(5) is unit-mocked but the real-time-elapses cap requires a stopwatch + a seeded large table."
  - test: "Manual cut-over after 16-02 rename lands locally"
    expected: "Per D-09, the developer hand-edits .env (BEATRAX_DEV_MODE), Herd hostname (beatrax.test), and the running launchd plists at ~/Library/LaunchAgents/com.diederik.*."
    why_human: "Hand-edit local config — D-09 explicitly states no upgrade-migration code ships; the developer's Herd + .env reflect the rename only after manual cut-over."
warnings:
  - issue: "Stray /horizon/failed deep link remains in Modules/Import/Resources/views/livewire/preview-wizard.blade.php (line 207)"
    severity: warning
    impact: "When the chain-resolution-failed branch fires inside the import preview wizard, the user sees an 'Open Horizon' link pointing at /horizon/failed. In shipped builds (dev_mode=false), this route is absent and the link 404s. D-37 targeted only the dashboard; this peer surface was not retargeted to dev.queue.tab."
    fix: "Apply the same D-37 retarget — gate on $isDeveloper + flip href to route('dev.queue.tab', ['tab' => 'failed']) + change label to 'Open Queue Inspector'. Out-of-scope for this phase's stated D-37 boundary but consistent with the phase's intent."
  - issue: "Pint flag on Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php (class_definition, fully_qualified_strict_types, braces_position fixers)"
    severity: warning
    impact: "composer format:check fails on this single file. 16-08-SUMMARY.md acknowledges the flag as pre-existing from 16-07 commit 2034b91 'out of this plan's scope'. The file is test code; functional tests pass. Per CLAUDE.md memory 'Fix every severity, not just blockers — quality above speed' this should have been fixed in 16-07."
    fix: "Run `./vendor/bin/pint Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` to apply the three fixers; no behavior change."
  - issue: "19 ->todo() rendered-badge assertions deferred to follow-up plan"
    severity: info
    impact: "Six pre-Phase-16 cross-module tests (TopNavBadgeViaComposerTest in EmailScan, TopNavDriftBadgeTest in DriftAlerts, TopNavForecastSlotTest in Forecasting, TopNavBadgeComposerTest in Recurring, CrossUserChainLinkIsolationTest in Chains) had their badge-chrome assertions marked ->todo() during 16-01 because the badge composers still target the deleted core::livewire.top-nav view. The .side-badge slots exist in the new sidebar markup but the composers do not yet write to them. Documented as the 'follow-up composer-rewiring plan' in 16-01-SUMMARY.md."
    fix: "Out of Phase 16 scope — a follow-up plan re-points registerTopNavBadgeComposer() at core::livewire.app-sidebar. Cross-user-isolation assertions on the underlying queries (not the badge chrome) remain green."
---

# Phase 16: Developer Mode UI — Verification Report

**Phase Goal:** A user with `is_developer = true` can open an in-app Developer Console from the app menu and run whitelisted artisan commands with live stdout/stderr streaming, tail logs with OAuth-token redaction, inspect queue + failed-jobs + job-batches, run `beatrax:doctor` from the UI, browse `system_alerts` + env snapshot + effective-config tree, and execute SELECT-only SQL — all gated by the `EnsureDeveloperMode` middleware + the `User::is_developer` flag; destructive commands require triple confirmation (Dev Mode on + Advanced toggle on + typed-app-name modal).

**Verified:** 2026-05-24T17:09:02Z
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria + DEVUI requirements)

| # | Truth (from ROADMAP SC + DEVUI) | Status | Evidence |
|---|---|---|---|
| SC1 | First-signup user auto-promotes to `is_developer=true`; non-developer at `/dev/*` gets HTTP 404 (not 403); arch invariant green | ✓ VERIFIED | `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php` throws `NotFoundHttpException` (line 37); `BoundaryArchTest::everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` green (run, lines 1501-1538); Phase 12 D-04 auto-promote already verified; `EnsureDeveloperModeTest` 4 tests green |
| SC2 | Developer clicks `db:backup` and sees live stdout streaming; `db:restore` requires triple gate; every run writes a `dev_mode_audit` row via spatie/laravel-activitylog | ✓ VERIFIED | CommandSpawner spawn-then-tail at `Modules/DevMode/Internal/Process/CommandSpawner.php`; SSE stream via `ArtisanStreamController`; TripleGateModal + DestructiveSpawnController re-validate; FinalizeRunAudit writes to `dev_mode_audit` table via SpatieAuditWriter + DevModeActivity (table_name=dev_mode_audit). spatie/laravel-activitylog ^5.0 in composer.json (CONTEXT D-23 supersession from ^4.12 documented). 12 AuditLogWriteTest + 6 TripleGateTest + 5 DestructiveTripleGateRoundTripTest + 4 ArtisanCancelTest tests pass |
| SC3 | Log tailer streams `storage/logs/laravel.log` filtered through Monolog redaction processor; Bearer headers, OAuth tokens, and any oauth_secrets value scrubbed before render | ✓ VERIFIED | Two-layer belt+braces redaction confirmed: `RedactSecretsProcessor` (on-write via `PushRedactProcessor` tap in `config/logging.php` on stack/single/daily channels) + `LogStreamController` (on-stream re-application). `OAuthScrubSet` loads every `oauth_secrets.client_secret` + `tokens_blob` string leaf; `BustOAuthScrubSetOnSecretChange` observer busts cache on save/delete. 6 RedactSecretsProcessorTest + 4 OAuthScrubSetBustTest + 7 LogStreamController + 6 LogTailerPage tests pass |
| SC4 | Queue inspector replaces v1.0 Horizon dashboard in shipped builds; embedded Horizon iframe reachable only when `DIEDERIK_RUNTIME=herd` (reinterpreted as `config('app.dev_mode')===true`) | ✓ VERIFIED | `QueueInspectorPage` reads jobs/failed_jobs/job_batches at `/dev/queue/{pending\|failed\|batches}`. `Routes/web.php` line 134 confirms Horizon iframe registered only when BOTH `config('app.dev_mode')===true` AND `class_exists(Laravel\Horizon\HorizonServiceProvider::class)`. Dashboard toast (D-37) gated on `$isDeveloper` + routes via `route('dev.queue.tab', ['tab'=>'failed'])`. 11 QueueInspectorActions + 6 QueueBulkDelete + 8 HorizonIframeGating + 4 DashboardFailedToastGating tests pass. CONTEXT canonical-refs section explicitly reinterprets `DIEDERIK_RUNTIME=herd` as `config('app.dev_mode')===true` per Phase 14 D-02 (acceptable rebinding) |
| SC5 | Doctor panel runs `beatrax:doctor` and displays results inline; SELECT-only SQL panel rejects non-SELECT at parse time + schema viewer enumerates tables/columns/indexes | ✓ VERIFIED | `DoctorPanelPage` reuses CommandSpawner pipeline via Re-run button (single code path = CLI + UI); `ProbeOutputParser` parses to pass/warn/fail rows. `SelectOnlyValidator` at `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` is single seam over doctrine/sql-formatter @internal Tokenizer; `tests/Contracts/SelectOnlyValidatorContractTest.php` locks 7 rejection cases. `ReadOnlySqliteConnection` engine-level PRAGMA query_only=1 + WallClockCap 5-second seam. `SchemaSnapshot` enumerates via Schema::getTables/getColumns/getIndexes/getForeignKeys. 16 SelectOnlyValidator + 6 DoctorPanelParser + 4 ReadOnlyConnection + 7 SqlPanelAudit + 7 EnvSnapshotRedaction + 3 SystemSnapshotPage tests pass |
| SC6 | Command palette (⌘K macOS / Ctrl+K Win/Linux) opens from any page with fuzzy search across views + Dev Console commands | ✓ VERIFIED (automated) — manual ⌘K capture required | `CommandPaletteModal` at `Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php`; `buildRegistry()` server-side filters dev rows by `is_developer` AND excludes DESTRUCTIVE; `resources/js/palette.js` Alpine factory wraps fuse.js@^7.3.0 (locked weights label 0.65/hint 0.20/keywords 0.15 + threshold 0.35). Both base layouts (`resources/views/layouts/app.blade.php` + `Modules/DevMode/Resources/views/layouts/dev-shell.blade.php`) carry the body `x-data` keybind handler with metaKey \|\| ctrlKey + I-7 INPUT/TEXTAREA carve-out. 5 CommandPaletteRegistry + 4 PaletteLayoutMount tests pass. Real-keyboard ⌘K capture is human-verified (see human_verification §1) |
| DEVUI-01 | `User::is_developer` boolean + `EnsureDeveloperMode` middleware gating every dev-mode route | ✓ VERIFIED | Settings page toggle (`SettingsPage::setDevMode()`) writes `users.is_developer` via Eloquent. 4 SettingsPageDevModeToggleTest passes. Arch invariant verified. The is_developer column was provided by Phase 12. |
| DEVUI-02 | Whitelisted artisan runner — SAFE tier with form args, live stdout streaming, command history, cancel-running-command | ✓ VERIFIED | `CommandRegistry` holds 9 SAFE entries verbatim per D-12 (db:backup, beatrax:doctor, beatrax:failed-jobs prune, cache:clear, route:list, config:show, view:clear, queue:retry, beatrax:rederive-fingerprints). `ArtisanSpawnController` POST /dev/artisan/spawn + SSE stream + cancel via posix_kill. `ArtisanRunnerPage` filter chips + day-section timeline + audit-derived history. 6 CommandRegistry + 4 CommandSpawner + 6 ArtisanStreamReconnect (page-refresh-reconnect proves D-16 spawn-then-tail) + 4 ArtisanCancel tests pass |
| DEVUI-03 | Destructive artisan runner with triple-gating + audit log via spatie/laravel-activitylog | ✓ VERIFIED | DESTRUCTIVE tier (6 commands per D-13): db:restore, migrate:fresh, beatrax:reset-password, beatrax:regenerate-recovery-codes, beatrax:grant-dev, beatrax:install. Two new commands `GrantDevCommand` + `RegenerateRecoveryCodesCommand` scaffolded in `Modules/Auth/Internal/Console/` (verified via `php artisan list`). `TripleGateModal` + `DestructiveSpawnController` defense-in-depth two-layer enforcement. `ResetAdvancedToggleOnLogin` listener clears session on login. `WriteWorkerHeartbeat` via QueueManager::looping(closure). 6 TripleGate + 2 WorkerHeartbeat + 5 DestructiveTripleGateRoundTrip + 12 AuditLogWrite tests pass |
| DEVUI-04 | Log tailer with Monolog redaction processor — scrubs Authorization: Bearer, OAuth tokens, oauth_secrets values | ✓ VERIFIED | Three-layer scrub (OAuthScrubSet → Bearer regex → JWT shape) in RedactSecretsProcessor + RedactionExcerptCap (audit excerpts). Pre-compiled regex alternation (Pitfall 8 mitigation). 250ms SSE cadence + tri-signal rotation detection. 10k-line client ring buffer + Pause/Resume + click-to-expand ±10 lines via context endpoint. 6 RedactSecretsProcessor + 4 RedactSecretsProcessorBaseline + 4 OAuthScrubSetBust + 7 LogStreamController + 6 LogTailerPage + 1 OAuthSecretDeletionStopsScrubbing tests pass |
| DEVUI-05 | Queue inspector — jobs + failed_jobs + job_batches tabular view with retry/cancel/delete actions; ~200-line Livewire component; replaces Horizon dashboard | ✓ VERIFIED | `QueueInspectorPage` single Livewire component backs /dev/queue/{pending\|failed\|batches}. Per-row actions (pending:delete \| failed:retry/delete \| batches:retry-failures/cancel/delete) + bulk select with bulk-delete behind triple-gate (D-34) + bulk-retry single-confirm. wire:poll(5s) count tiles. Inline JSON payload viewer with RedactSecretsProcessor scrub. `QueueActions` final readonly with constructor DI on FailedJobProviderInterface + BatchRepository + QueueFactory + DatabaseManager + AuditWriter + CurrentUser (W-4 — Bus facade banned). 11 QueueInspectorActions + 6 QueueBulkDeleteTripleGate tests pass |
| DEVUI-06 | Doctor panel + system_alerts viewer + env snapshot + effective-config tree viewer | ✓ VERIFIED | `DoctorPanelPage` at /dev/doctor parses ProbeOutputParser output into pass/warn/fail rows via the same Process+SSE pipeline as the artisan runner (D-43 single code path). `SystemSnapshotPage` at /dev/system displays PHP / SQLite PRAGMAs / paths / BEATRAX_* env / NATIVEPHP_* / APP_KEY (redacted) / NativePHP runtime / flattened config via `ConfigFlattener` with suffix denylist (*password*/*secret*/*key/*token*). DevOverviewPage includes `system_alerts` via Open Alerts card (read from `SystemAlertQuery`). 13 DevOverviewPage + 6 DoctorPanelParser + 7 EnvSnapshotRedaction + 3 SystemSnapshotPage tests pass |
| DEVUI-07 | Read-only SQLite query panel — SELECT-only runner with schema viewer + table-row browser; gated by Dev Mode + Advanced | ✓ VERIFIED | `SqlPanelPage` at /dev/sql with inner-sidebar schema viewer (I-6 locked: single route). Defense-in-depth: SelectOnlyValidator (first-token + semicolon-stack + WITH-write rejection via doctrine/sql-formatter @internal Tokenizer) + ReadOnlySqliteConnection (PRAGMA query_only=1 per-PDO with finally-block restore) + WallClockCap (set_time_limit(5) mockable seam). Every SELECT writes dev_mode_audit row (AuditEvent::SqlSelect). `tests/Contracts/SelectOnlyValidatorContractTest.php` locks the 7 rejection cases as a contract ratchet against composer-update silent breakage. 16 SelectOnlyValidator + 4 ReadOnlyConnection + 7 SqlPanelAudit tests pass |
| DEVUI-08 | Embedded Horizon iframe inside Dev Console — dev-mode-only, behind dev-runtime flag | ✓ VERIFIED | `Routes/web.php` line 134 two-signal gate: `if (config('app.dev_mode') === true && class_exists(Laravel\Horizon\HorizonServiceProvider::class))`. `HorizonFramePage` Livewire wrapper renders `<iframe src="/horizon">`. `noHorizonImportsInShippedBuildCode` arch invariant preserved via name-conflict shim (Pint hoist suppression). DevSidebarItems entry uses sentinel `enabled => 'conditional'` so the sidebar DROPS the item entirely (DOM-absent, not nav-disabled) when D-38 signals fail. 8 HorizonIframeGating tests pass |
| DEVUI-09 | Command palette ⌘K/Ctrl+K with fuzzy search — Linear/Raycast calm-shell aesthetic | ✓ VERIFIED (automated) — manual ⌘K capture required | `NavigationRegistryImpl` (10 main-app + 9 dev sub-routes) + `AppActionRegistryImpl` (Run import / Scan email now / Open profile / Toggle theme) replace 16-03 Null* defaults. `CommandPaletteModal::buildRegistry()` server-side filters dev rows by is_developer + SAFE-only filter for dev commands (DESTRUCTIVE excluded per D-41). Per-user recent cache `dev_mode.palette_recent.{userId}` (5 entries, 30d TTL, deduped). `resources/js/palette.js` Alpine factory with fuse.js locked weights/threshold/ignoreLocation. Body x-data on both base layouts with I-7 INPUT/TEXTAREA carve-out. 5 CommandPaletteRegistry + 4 PaletteLayoutMount + 3 AppMenuDeveloperSubmenu + 5 AppSidebarDevBlockLiveData tests pass. Real-keyboard ⌘K capture is human-verified |

**Score:** 9/9 DEVUI requirements verified + 6/6 ROADMAP success criteria verified

---

## Required Artifacts (level 1-3 verification)

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/DevMode/Internal/Http/Middleware/EnsureDeveloperMode.php` | 404 (not 403) gate on /dev/* | ✓ VERIFIED | 46 lines, constructor DI on CurrentUser, throws NotFoundHttpException, wired via aliasMiddleware('ensureDeveloperMode', ...) in DevModeServiceProvider line 525 |
| `Modules/DevMode/Routes/web.php` | All /dev/* routes apply ensureDeveloperMode | ✓ VERIFIED | 143 lines; single Route::middleware group(['web', 'auth', 'ensureDeveloperMode'])->prefix('/dev'); arch invariant locks coverage |
| `Modules/DevMode/Internal/CommandRegistry.php` + DevModeServiceProvider singleton | Full SAFE (9) + DESTRUCTIVE (6) roster per D-12/D-13 | ✓ VERIFIED | All 15 CommandSpec entries present (verified at provider lines 100-272); find() throws InvalidArgumentException for unknown |
| `Modules/DevMode/Internal/Process/CommandSpawner.php` | spawn-then-tail architecture b per D-16 | ✓ VERIFIED | bash wrapper `bash -c '<cmd> > out 2>&1 < /dev/null & echo $!'`; three-guard injection (whitelist + escapeshellarg + Laravel validate); ArtisanStreamReconnectTest proves D-16 page-refresh-reconnect honored |
| `Modules/DevMode/Internal/Http/Livewire/TripleGateModal.php` + `Internal/Http/Controllers/DestructiveSpawnController.php` | Defense-in-depth dual-gate enforcement | ✓ VERIFIED | TripleGateModal::confirm() validates env+session+typed; DestructiveSpawnController re-validates same three locks; hash_equals('beatrax', $typed) case-sensitive |
| `Modules/DevMode/Internal/Audit/SpatieAuditWriter.php` + `DevModeActivity.php` (table_name override) | Writes to dev_mode_audit via spatie/laravel-activitylog ^5.0 | ✓ VERIFIED | spatie/laravel-activitylog ^5.0 in composer.json (CONTEXT D-23 supersession of ^4.12 documented inline; v4 cannot resolve under Laravel 13). Custom DevModeActivity model overrides $table (v5 removed table_name config); `Modules/DevMode/Database/Migrations/2026_05_24_000001_create_dev_mode_audit_table.php` |
| `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php` + `PushRedactProcessor.php` | Monolog tap on stack/single/daily channels (W-1 fix) | ✓ VERIFIED | Both files exist; config/logging.php 'tap' => [PushRedactProcessor::class] on all three channels; container DI inside __invoke lets 16-05 upgrade propagate without touching tap class. Three-layer scrub (OAuthScrubSet → Bearer → JWT) |
| `Modules/DevMode/Internal/Services/OAuthScrubSet.php` + `Internal/Listeners/BustOAuthScrubSetOnSecretChange.php` | Eloquent observer busts cache on OAuthSecret save/delete | ✓ VERIFIED | OAuthScrubSet lazy-load + compiledPattern() pre-compiled regex alternation (Pitfall 8); observer attached in DevModeServiceProvider::boot via OAuthSecret::observe(...) |
| `Modules/DevMode/Internal/Http/Livewire/QueueInspectorPage.php` + `Internal/Queue/QueueActions.php` | Three-tab inspector + bulk-delete behind triple-gate (W-4 Bus facade banned) | ✓ VERIFIED | Single Livewire component backs /dev/queue/{tab}; QueueActions DI on FailedJobProviderInterface + BatchRepository + QueueFactory + DatabaseManager + AuditWriter + CurrentUser (no Bus facade). AuditEvent enum extended with 8 queue.* cases (I-5 fix) |
| `Modules/Core/Internal/Http/Livewire/Dashboard.php` + dashboard.blade.php | D-37 toast retarget + $isDeveloper gate | ✓ VERIFIED | render() passes 'isDeveloper' => $user->is_developer === true; toast @if ($failedChainResolutionExists && $isDeveloper); href via route('dev.queue.tab', ['tab' => 'failed']); label "Open Queue Inspector" |
| `Modules/DevMode/Internal/Http/Livewire/CommandPaletteModal.php` + `resources/js/palette.js` | Fuse.js fuzzy + dev/SAFE filter at JSON-emit time | ✓ VERIFIED | buildRegistry() filters dev.* nav entries + DevCommandRegistry safe() entries by is_developer. palette.js Alpine factory with locked weights (label 0.65, hint 0.20, keywords 0.15) + threshold 0.35 + ignoreLocation true |
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` | Conditional Developer submenu via Menu::route('dev.overview', ...) | ✓ VERIFIED | Constructor DI on CurrentUser; build() appends Developer submenu only if isDeveloper(); two items use Menu::route() (B-4 route-name not hardcoded URL) |
| `Modules/Core/Internal/Http/Livewire/AppSidebar.php` + app-sidebar.blade.php | Sectioned left sidebar + server-side-gated Dev block + live data wire:poll.5s | ✓ VERIFIED | render() method-DI on CurrentUser + Request + ViewFactory + CacheRepository + DatabaseManager + Clock; .side-dev-block rendered only when isDeveloper; .dev-pulse wire:poll.5s row reads jobs.count() + dev_mode.queue_worker_heartbeat |
| Impersonation surface deletions | 7 files deleted per D-11 | ✓ VERIFIED | Modules/Auth/Public/Actions/{ImpersonateUserAction,EndImpersonationAction}.php, Modules/Auth/Public/Dto/ImpersonationResult.php, Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php, Modules/Auth/Resources/views/partials/impersonation-banner.blade.php, Modules/Auth/tests/Feature/{ImpersonationBannerTest,ImpersonationActionTest}.php all confirmed absent. impersonationSurfaceRemoved arch invariant green |
| `tests/Contracts/SelectOnlyValidatorContractTest.php` | @internal Tokenizer contract ratchet | ✓ VERIFIED | 7 rejection cases locked; documented in 16-07-SUMMARY.md as the (a) mitigation from RESEARCH planner-attention block |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| /dev/* routes | EnsureDeveloperMode middleware | aliasMiddleware('ensureDeveloperMode', ...) + Route::middleware group | ✓ WIRED | DevModeServiceProvider line 525 + Routes/web.php line 41; arch invariant locks runtime route-table walk |
| ArtisanSpawnController | CommandRegistry whitelist + escapeshellarg + Laravel validate | constructor DI | ✓ WIRED | Three-guard injection-resistance; CommandSpawnerTest::it rejects an injection-attempt path (no /tmp/PWNED sentinel) |
| ArtisanStreamController done branch | FinalizeRunAudit → SpatieAuditWriter → DevModeActivity → dev_mode_audit table | constructor DI (16-04b modification) | ✓ WIRED | Audit row written BEFORE event: done emitted; verified by AuditLogWriteTest |
| TripleGateModal::confirm() | DestructiveSpawnController re-validate | Livewire dispatch 'triple-gate:confirmed' + POST /dev/artisan/destructive-spawn | ✓ WIRED | Both layers validate DevModeFlag + session('dev_mode.advanced') + hash_equals('beatrax', $typed); DestructiveTripleGateRoundTripTest covers full round trip |
| RedactSecretsProcessor | OAuthScrubSet → Bearer → JWT three-layer | container DI nullable constructor | ✓ WIRED | OAuthScrubSetBustTest proves rotated secret takes effect on next log line; observer attached in DevModeServiceProvider::boot |
| Dashboard.php @if branch | route('dev.queue.tab', ['tab' => 'failed']) + $isDeveloper gate | render() passes isDeveloper + Blade @if + route() helper | ✓ WIRED | DashboardFailedToastGatingTest 4 cases verify all combinations; no hardcoded /horizon/failed remains in this surface |
| AppMenuBuilder::build() | Menu::route('dev.overview', ...) | constructor DI on CurrentUser | ✓ WIRED | B-4 fix — route-name based, works in both Herd dev + shipped Electron bundles; menu structurally absent for non-developers |
| Command palette JSON | NavigationRegistry + DevCommandRegistry (SAFE only) + AppActionRegistry filtered by is_developer | server-side buildRegistry() at JSON-emit time | ✓ WIRED | CommandPaletteRegistryTest #3 (developer JSON has view+dev SAFE+action, ZERO destructive) + #4 (non-developer JSON has ZERO dev rows) |
| AppSidebar.render() | dev_mode.queue_worker_heartbeat cache + jobs.count() | wire:poll.5s on .dev-pulse subtree | ✓ WIRED | AppSidebarDevBlockLiveDataTest verifies the live data; heartbeat written by WriteWorkerHeartbeat via QueueManager::looping (W-8 fix) |
| Horizon iframe | config('app.dev_mode')===true AND class_exists(Laravel\Horizon\HorizonServiceProvider::class) | Routes/web.php line 134 inline two-signal gate | ✓ WIRED | HorizonIframeGatingTest 8 cases; name-conflict shim prevents Pint hoist + arch invariant; sentinel 'conditional' makes sidebar item DOM-absent on signal fail |

---

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| AppSidebar.render() | $queueCount | jobs table count via DatabaseManager | Yes (real query) | ✓ FLOWING |
| AppSidebar.render() | $workerSecondsAgo | dev_mode.queue_worker_heartbeat cache + WriteWorkerHeartbeat listener | Yes (cache → DB Worker tick) | ✓ FLOWING |
| DevOverviewPage console pane | heartbeat / queue counts / last command | Cache + jobs/failed_jobs/job_batches + last dev_mode_audit | Yes | ✓ FLOWING |
| QueueInspectorPage tabs | jobs / failed_jobs / job_batches | DatabaseManager raw query builder | Yes | ✓ FLOWING |
| LogTailerPage | redacted log lines | LogStreamController SSE + RedactSecretsProcessor | Yes (real laravel-YYYY-MM-DD.log via FileTailer) | ✓ FLOWING |
| AuditLogPage | dev_mode_audit rows | DatabaseManager raw query | Yes | ✓ FLOWING |
| ArtisanRunnerPage timeline | per-user dev_mode_audit rows | DatabaseManager raw query scoped to causer_id | Yes | ✓ FLOWING |
| DoctorPanelPage | latest beatrax:doctor audit row | dev_mode_audit + ProbeOutputParser | Yes | ✓ FLOWING |
| SystemSnapshotPage | PHP / SQLite PRAGMAs / config | Real config + PDO + filesystem reads | Yes | ✓ FLOWING |
| SqlPanelPage | SELECT result | ReadOnlySqliteConnection::execute() | Yes (live read-only PDO) | ✓ FLOWING |
| CommandPaletteModal | merged registry | NavigationRegistryImpl + DevCommandRegistry + AppActionRegistryImpl | Yes (concretes replace Null* defaults at 16-08) | ✓ FLOWING |

No HOLLOW or DISCONNECTED artifacts found.

---

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All beatrax:* artisan commands resolve | `php artisan list` | beatrax:doctor, beatrax:failed-jobs, beatrax:grant-dev, beatrax:install, beatrax:prune-dev-audit, beatrax:rederive-fingerprints, beatrax:regenerate-recovery-codes, beatrax:reset-password (8 visible — db:backup is native) | ✓ PASS |
| No diederik:* command ghosts | `php artisan list \| grep diederik:` | (no output) | ✓ PASS |
| DevMode test suite | `./vendor/bin/pest Modules/DevMode --no-coverage` | 181 passed (673 assertions); 0 failures | ✓ PASS |
| All boundary arch tests green | `./vendor/bin/pest --filter BoundaryArchTest --no-coverage` | 45 passed (78 assertions); all 4 Phase 16 invariants green | ✓ PASS |
| Full suite | `./vendor/bin/pest --no-coverage` | 2394 passed, 0 failed, 19 todos (16-01 deferred badge composer rewiring), 6 skipped (Horizon-conditional + Receipts contract); 25530 assertions | ✓ PASS |
| Larastan L10 strict | `composer analyse` | 0 errors across 631 analyzed files | ✓ PASS |
| Pint formatter | `composer format:check` | FAIL — Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php (3 fixers: class_definition, fully_qualified_strict_types, braces_position) | ✗ FAIL (test file only; pre-existing from 16-07 per 16-08-SUMMARY) |
| Composer dependencies installed | grep composer.json | spatie/laravel-activitylog ^5.0, doctrine/sql-formatter ^1.5; composer name = beatrax/beatrax | ✓ PASS |
| Frontend dependency installed | grep package.json | fuse.js ^7.3.0 | ✓ PASS |

---

## Probe Execution

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| (no project probes under scripts/*/tests/probe-*.sh) | — | — | — |

No conventional probes; phase verification relies on Pest tests + arch invariants + spot-checks.

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DEVUI-01 | 16-03 | is_developer flag + EnsureDeveloperMode middleware | ✓ SATISFIED | EnsureDeveloperMode 404-not-403; Settings toggle persists; arch invariant green |
| DEVUI-02 | 16-04, 16-04b | Whitelisted SAFE artisan runner with form args + live streaming + cancel + history | ✓ SATISFIED | Full SAFE roster + spawn-then-tail + SSE + cancel + audit-derived recent runs (D-18) |
| DEVUI-03 | 16-04b | DESTRUCTIVE runner with triple-gating + audit via spatie/laravel-activitylog | ✓ SATISFIED | TripleGateModal + DestructiveSpawnController dual-gate + dev_mode_audit via DevModeActivity (v5 compat) |
| DEVUI-04 | 16-04b (baseline), 16-05 (full) | Log tailer with Monolog redaction processor | ✓ SATISFIED | Belt+braces on-write + on-stream; OAuthScrubSet observer-bust; pre-compiled alternation |
| DEVUI-05 | 16-06 | Queue inspector — jobs / failed_jobs / job_batches with retry/cancel/delete | ✓ SATISFIED | Single Livewire component, three tabs, per-row + bulk actions, count tiles wire:poll(5s) |
| DEVUI-06 | 16-07 | Doctor panel + system_alerts + env snapshot + effective-config | ✓ SATISFIED | DoctorPanelPage via Process+SSE; SystemSnapshotPage with ConfigFlattener denylist redaction; DevOverviewPage open-alerts card reads SystemAlertQuery |
| DEVUI-07 | 16-07 | Read-only SQLite SELECT panel + schema viewer; gated by Dev Mode + Advanced | ✓ SATISFIED | SelectOnlyValidator (parse-time) + ReadOnlySqliteConnection (engine-time PRAGMA) + WallClockCap (5s); contract test ratchet |
| DEVUI-08 | 16-06 | Embedded Horizon iframe inside Dev Console (dev-runtime only) | ✓ SATISFIED | Two-signal gate (config('app.dev_mode')===true AND class_exists) at Routes/web.php line 134; arch invariant preserved via shim |
| DEVUI-09 | 16-08 | Command palette ⌘K with fuzzy search | ✓ SATISFIED | NavigationRegistryImpl + AppActionRegistryImpl concretes + CommandPaletteModal + palette.js Alpine factory + fuse.js locked weights; manual ⌘K capture human-verified |

**Orphaned requirements:** None — all 9 DEVUI requirements claimed by at least one plan; no requirements in REQUIREMENTS.md mapped to Phase 16 are unclaimed.

---

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| Modules/Import/Resources/views/livewire/preview-wizard.blade.php | 207 | hardcoded /horizon/failed deep link + "Open Horizon" label, no $isDeveloper gate | ⚠️ WARNING | When the preview wizard chain-resolution-failed branch fires, non-developers see a link that 404s in shipped builds (config('app.dev_mode') !== true → no /horizon/* route). D-37 explicitly targeted only the Dashboard. This peer surface was missed by the retarget sweep. |
| Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php | (whole file) | Pint format-check fail (class_definition, fully_qualified_strict_types, braces_position) | ⚠️ WARNING | composer format:check fails on this single file. Documented in 16-08-SUMMARY.md as a "pre-existing unrelated flag" inherited from 16-07 commit 2034b91 — explicitly out of 16-08 scope but should have been fixed during 16-07. Per CLAUDE.md memory ("Fix every severity, not just blockers — quality above speed") this is non-conforming closure debt. |

No BLOCKER anti-patterns found. No TBD/FIXME/XXX debt markers in Phase 16 code. No placeholder returns. No console.log stubs. No empty handlers. No hardcoded empty rendered state.

---

## Human Verification Required

11 items need human testing (see frontmatter `human_verification`). Highlights:

### 1. ⌘K palette opens on macOS

**Test:** Composer dev, sign in as developer, press ⌘K
**Expected:** Palette modal opens with view + dev + action rows; ZERO destructive command names shown
**Why human:** Real-keyboard handler; Pest tests verify server-emitted JSON and Alpine factory exists, but in-browser ⌘K capture is OS-keyboard event handling

### 2. I-7 keybind carve-out — ⌘K inside input/textarea does NOT open palette

**Test:** Focus a text input on /transactions; type a query; press ⌘K
**Expected:** Palette does NOT open (the body onKey handler early-returns on INPUT/TEXTAREA/contentEditable focus)
**Why human:** Real-keyboard + focus interaction; the document.activeElement check is browser-only

### 3. App-menu Developer submenu only for is_developer=true (NativePHP)

**Test:** Build native bundle; sign in as developer — menu shows. Toggle is_developer=false in Settings, quit bundle, relaunch — Developer submenu absent
**Expected:** Submenu appears only for developers and persists across the full bundle restart (per RESEARCH Pitfall 9, app-menu changes require full quit + relaunch)
**Why human:** NativePHP app-menu binding is runtime electron-bound

### 4. Settings → Developer Mode toggle persists across logout/login

**Test:** Toggle on → log out → log in → toggle still on
**Expected:** State survives session reset (DB-persisted via users.is_developer)
**Why human:** Pest covers DB write via setDevMode(); the round-trip across logout requires real session reset cookie clearing

### 5. Live stdout streaming feels sub-second for db:backup

**Test:** Run db:backup from /dev/artisan; verify lines stream to UI within ~500ms of process emit
**Expected:** Felt-latency of SSE pipeline matches D-16 promise
**Why human:** Subjective UX latency measurement; SSE code paths are unit-tested but felt-latency requires a stopwatch

### 6. Triple-gate modal UX

**Test:** Open destructive db:restore confirm; click outside scrim — modal stays. Press Esc — closes. Type 'Beatrax' (wrong case) — Run button stays disabled. Type 'beatrax' — button enables → Run fires
**Expected:** Click-outside-doesn't-close + case-sensitive typed-name enforced
**Why human:** Server-side enforcement is Pest-covered; the no-close-on-scrim Alpine behaviour is felt in browser

### 7. B-2 fallback runner modal — SAFE-only commands

**Test:** Open /dev/artisan fallback Run-command modal; visually verify NO destructive command buttons appear (db:restore, migrate:fresh, beatrax:install, beatrax:reset-password, beatrax:grant-dev, beatrax:regenerate-recovery-codes)
**Expected:** Only SAFE-tier commands appear as buttons (the destructive ones are structurally absent, not just disabled)
**Why human:** Visual UI scan complement to ArtisanRunnerSafeTierTest

### 8. Log tailer rotation detection

**Test:** Tail /dev/logs; delete or recreate today's laravel-YYYY-MM-DD.log
**Expected:** Stream re-opens without crashing (tri-signal: inode change OR shrinkage OR path change)
**Why human:** OS-dependent file-system semantics

### 9. Horizon iframe loads under NativePHP runtime

**Test:** composer dev; visit /dev/horizon (with dev_mode=true and Horizon installed); verify Horizon UI loads inside iframe with mouse + keyboard working
**Expected:** External app renders correctly inside the iframe
**Why human:** Iframe rendering + Horizon-app interaction is browser-side

### 10. W-6 /dev/sql runaway killed by set_time_limit(5)

**Test:** Run deliberate cross-join SELECT over a large seeded dataset
**Expected:** Query dies before 6 seconds wall-clock
**Why human:** Wall-clock assertion is flaky in automated tests; the WallClockCap::apply(5) seam is unit-mocked

### 11. Manual cut-over after 16-02 rename

**Test:** Hand-edit local .env (BEATRAX_DEV_MODE), Herd hostname (beatrax.test), running launchd plists at ~/Library/LaunchAgents/com.diederik.*
**Expected:** Developer's runtime reflects the rename after manual cut-over (D-09 — no upgrade migration code ships)
**Why human:** Hand-edit local config that does not ship with the app

---

## Warnings Summary

Two non-blocking warnings to surface:

1. **Stray /horizon/failed deep link in Modules/Import/Resources/views/livewire/preview-wizard.blade.php** — the dashboard retarget (D-37) explicitly targeted only the dashboard, but the same `/horizon/failed` deep link with "Open Horizon" label exists in the Import preview wizard's chain-resolution-failed branch. In shipped builds (config('app.dev_mode') !== true), this link would 404. Consistent with the phase's stated D-37 intent to gate Horizon links on developer status; missed by the sweep.

2. **Pint format-check fails on Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php** — three mechanical fixers (class_definition, fully_qualified_strict_types, braces_position) need to be applied. The flag was inherited from 16-07 commit 2034b91 and explicitly acknowledged in 16-08-SUMMARY.md as pre-existing. Per CLAUDE.md memory ("Fix every severity, not just blockers — quality above speed") this should have been fixed in 16-07; the test functionality is unaffected.

3. **19 ->todo() rendered-badge assertions** — 16-01 replaced the top-nav with the app sidebar; the .side-badge slots exist in the new markup, but the cross-module composers (Recurring pending-count, DriftAlerts drift count, EmailScan inbox badges, Forecasting forecast slot, Chains review-chains badge) still target the deleted core::livewire.top-nav view and silently no-op. Documented as the "follow-up composer-rewiring plan" deferral in 16-01-SUMMARY.md. Not a Phase 16 goal failure — the sidebar primitive and Dev block ship; only the badge composer rewiring is deferred.

---

## Overall Status: `human_needed`

All 9 DEVUI requirements + all 6 ROADMAP success criteria are verified in the codebase by code reads, arch invariants, and ~181 DevMode Pest tests + 45 BoundaryArchTest + 25530 total assertions across the full suite (2394 passed, 0 failed). Two non-blocking warnings (stray /horizon/failed link + Pint flag) are surfaced for developer review.

The Manual-Only Verifications section of 16-VALIDATION.md (11 items reproduced above) requires human testing in a live browser + NativePHP bundle. These items are intentionally deferred per `workflow.human_verify_mode = end-of-phase` and span:
- Real-keyboard ⌘K capture across macOS + Windows/Linux (DEVUI-09)
- I-7 input/textarea focus carve-out
- NativePHP app-menu Developer submenu visibility (DEVUI-01 + DEVUI-09)
- Settings toggle round-trip across logout/login
- SSE perceived sub-second latency
- Triple-gate modal click-outside + Esc + case-sensitive typed-name UX
- B-2 fallback modal visual SAFE-only scan
- Log tailer rotation detection on real filesystem
- Horizon iframe rendering
- SQL 5-second wall-clock cap (W-6)
- 16-02 rename manual cut-over

Phase 16 goal is achieved in the codebase. Phase close-out awaits the human verification pass.

---

_Verified: 2026-05-24T17:09:02Z_
_Verifier: Claude (gsd-verifier)_
