---
phase: 16
slug: developer-mode-ui
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-24
updated: 2026-05-24
---

# Phase 16 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Derived from `16-RESEARCH.md` § Validation Architecture; planner refines per-plan during task breakdown.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 + pest-plugin-arch + pest-plugin-laravel + canvural/larastan-strict-rules + spatie/pest-plugin-snapshots |
| **Config file** | `tests/Pest.php` (root) + `Modules/DevMode/tests/Pest.php` (module — Wave 0 — landed by 16-03) |
| **Quick run command** | `pest --parallel --filter=DevMode` |
| **Full suite command** | `composer test` |
| **Static analysis** | `composer analyse` (Larastan L10 strict via `phpstan.neon`) |
| **Formatter check** | `composer format:check` (Pint) |
| **Estimated runtime** | ~30s quick / ~3–5min full |

---

## Sampling Rate

- **After every task commit:** `pest --parallel --filter=DevMode`
- **After every plan wave:** `composer test` + `composer analyse` + `composer format:check`
- **Before `/gsd:verify-work`:** Full suite green, Larastan L10 clean, Pint clean
- **Max feedback latency:** ~30s

---

## Per-Task Verification Map

> Bootstrapped from RESEARCH.md § Phase Requirements → Test Map. Planner has filled `Task ID` / `Plan` / `Wave` columns by emitting PLAN.md files 16-01 through 16-08. **REVISION PASS 1:** Task IDs split between 16-04 (SAFE process pipeline) and 16-04b (audit + UI + triple-gate) per B-5 fix; new row added for D-16 page-refresh-reconnect (B-1 fix); Test "SQL runaway killed by set_time_limit(5)" moved to manual-only per W-6; new I-7 manual entry added.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 16-03/T1 | 16-03 | 3 | DEVUI-01 | T-16-01 | Non-developer → 404 (not 403) | feature | `pest Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-03/T2 | 16-03 | 3 | DEVUI-01 | T-16-01 | Every `/dev/*` route applies `EnsureDeveloperMode` (I-2: filter tightened to `dev/` prefix) | arch | `pest --filter everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-03/T3 | 16-03 | 3 | DEVUI-01 | T-16-08 | Settings Developer toggle writes users.is_developer; survives logout/login | feature | `pest Modules/Core/tests/Feature/SettingsPageDevModeToggleTest.php` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-04/T1 | 16-04 | 4 | DEVUI-02 | T-16-11 | CommandRegistry holds full SAFE+DESTRUCTIVE roster per D-12/D-13 with ArgSpec entries | unit | `pest Modules/DevMode/tests/Feature/CommandRegistryTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T1 | 16-04 | 4 | DEVUI-02 | T-16-SC2 | CommandSpawner shell-redirect uses escapeshellarg on every arg; injection attempt does NOT modify FS outside controlled paths | feature | `pest Modules/DevMode/tests/Feature/CommandSpawnerTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| **16-04/T2** | **16-04** | **4** | **DEVUI-02** | **—** | **B-1 / B-6 fix: D-16 page-refresh-reconnect — a second SSE handle adopting the same run_id after closing the first observes lines emitted after the reconnect point (proves architecture b spawn-then-tail honors D-16 verbatim)** | **feature** | **`pest Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php`** | ❌ **Wave 0 (16-04 creates)** | ⬜ |
| 16-04/T2 | 16-04 | 4 | DEVUI-02 | T-16-12, T-16-16 | Cancel sends SIGTERM + SIGKILL fallback; SSE stream observes process-gone + emits done event with cancel marker | feature | `pest Modules/DevMode/tests/Feature/ArtisanCancelTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T2 | 16-04 | 4 | DEVUI-02 | T-16-15 | Cross-user run inspection rejected — second developer cannot inspect first developer's run stream | feature | `pest Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php` (Test 7) | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T3 | 16-04 | 4 | DEVUI-03 | — | Missing commands beatrax:grant-dev + beatrax:regenerate-recovery-codes scaffolded if absent | feature | `pest --filter='GrantDev\|RegenerateRecoveryCodes'` | ❌ Wave 0 (16-04 creates if absent) | ⬜ |
| **16-04b/T1** | **16-04b** | **4** | **DEVUI-02** | **T-16-13** | **SAFE run's done branch invokes FinalizeRunAudit → spatie/laravel-activitylog ^5.0 audit row written with D-24 row shape + 8KB cap + Bearer/JWT redaction** | **feature** | **`pest Modules/DevMode/tests/Feature/AuditLogWriteTest.php`** | ❌ **Wave 0 (16-04b creates)** | ⬜ |
| 16-04b/T1 | 16-04b | 4 | DEVUI-03 | — | AuditEvent enum locks the audit-action taxonomy (I-5 fix — no free-form strings) | unit | `pest --filter=AuditEvent` (part of AuditLogWrite tests) | ❌ Wave 0 (16-04b creates) | ⬜ |
| 16-04b/T1 | 16-04b | 4 | DEVUI-02 | — | PruneDevAuditCommand deletes rows older than --older-than days | feature | `pest --filter=PruneDevAudit` (part of AuditLogWrite test file) | ❌ Wave 0 (16-04b creates) | ⬜ |
| **16-04b/T1** | **16-04b** | **4** | **DEVUI-04** | **T-16-W1, T-16-03** | **W-1 BASELINE fix: Monolog RedactSecretsProcessor + PushRedactProcessor installed by 16-04b; config/logging.php stack/single/daily tap slots reference PushRedactProcessor::class; `logger()->info("...Authorization: Bearer abc.def.ghi...")` writes `Authorization: Bearer [REDACTED]` to disk. Closes on-write redaction-gap window in Wave 4, not Wave 5.** | **unit + feature** | **`pest Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php`** | ❌ **Wave 0 (16-04b creates)** | ⬜ |
| 16-04b/T2 | 16-04b | 4 | DEVUI-03 | T-16-02 | DESTRUCTIVE `db:restore` rejected without all three gates (TripleGateModal + DestructiveSpawnController defense-in-depth re-validation) | feature | `pest Modules/DevMode/tests/Feature/TripleGateTest.php` | ❌ Wave 0 (16-04b creates) | ⬜ |
| 16-04b/T2 | 16-04b | 4 | DEVUI-02 | T-16-W8 | Worker heartbeat updates cache on every queue:work iteration via QueueManager::looping DI form (W-8 fix — NOT event-listener form) | feature | `pest Modules/DevMode/tests/Feature/WorkerHeartbeatTest.php` | ❌ Wave 0 (16-04b creates) | ⬜ |
| 16-04b/T2 | 16-04b | 4 | DEVUI-03 | T-16-02 | Full round-trip: triple-gate confirm → DestructiveSpawnController spawn → CommandSpawner → audit row with tier=destructive | feature | `pest Modules/DevMode/tests/Feature/DestructiveTripleGateRoundTripTest.php` | ❌ Wave 0 (16-04b creates) | ⬜ |
| 16-04b/T3 | 16-04b | 4 | DEVUI-02 | — | SAFE-tier `cache:clear` end-to-end via fallback modal → spawn → stream → audit row | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` | ❌ Wave 0 (16-04b creates) | ⬜ |
| **16-04b/T3** | **16-04b** | **4** | **DEVUI-02** | **T-16-02b** | **B-2 regression guard: fallback Flux modal lists SAFE-tier commands ONLY; no DESTRUCTIVE command name appears in rendered modal HTML** | **feature** | **`pest Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` (Test 3)** | ❌ **Wave 0 (16-04b creates)** | ⬜ |
| 16-05/T1 | 16-05 | 5 | DEVUI-04 | T-16-03 | Injected secret in log line is redacted before stream emit (on-write via tap; 16-04b installed baseline, 16-05 upgrades w/ scrub-set) | unit | `pest Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T1 | 16-05 | 5 | DEVUI-04 | T-16-03, T-16-17 | OAuth secret saved → scrub-set busted → next line `[REDACTED]` | feature | `pest Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T1 | 16-05 | 5 | DEVUI-04 | — | I-4 non-blocking regression: deleting an OAuthSecret stops scrubbing that string in future logs (intentional behavior — rotated-and-removed secrets cease to be sensitive) | feature | `pest Modules/DevMode/tests/Feature/OAuthSecretDeletionStopsScrubbingTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T2 | 16-05 | 5 | DEVUI-04 | T-16-18 | Log stream controller emits redacted lines + detects rotation (W-5: tests use per-test ephemeral channel via app(LogManager::class)->build to avoid Pest-parallel races on the shared daily log) | feature | `pest Modules/DevMode/tests/Feature/LogStreamControllerTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T2 | 16-05 | 5 | DEVUI-04 | T-16-19 | Log tailer page renders + context endpoint returns ±10 redacted lines | feature | `pest Modules/DevMode/tests/Feature/LogTailerPageTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-06/T1 | 16-06 | 5 | DEVUI-05 | T-16-23 | Queue inspector retry-failed re-dispatches + removes failed row + writes audit via AuditEvent enum (W-4: BatchRepository DI, no Bus facade carve-out) | feature | `pest Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T1 | 16-06 | 5 | DEVUI-05 | T-16-02, T-16-22 | Bulk delete requires triple-gate (reuses 16-04b's TripleGateModal) | feature | `pest Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T2 | 16-06 | 5 | DEVUI-08 | T-16-24 | Horizon iframe route absent when `app.dev_mode === false` OR `class_exists()` false | feature | `pest Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T2 | 16-06 | 5 | DEVUI-08 | T-16-25 | Dashboard /horizon/failed toast gated on $isDeveloper + retargeted via route('dev.queue.tab', ['tab' => 'failed']) (B-3 fix — no broken /queue/failed alias) | feature | `pest Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-07/T1 | 16-07 | 5 | DEVUI-06 | — | /dev overview console pane + tiles + recent runs render | feature | `pest Modules/DevMode/tests/Feature/DevOverviewPageTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T2 | 16-07 | 5 | DEVUI-06 | — | Doctor panel parses pass/warn/fail rows from beatrax:doctor | feature | `pest Modules/DevMode/tests/Feature/DoctorPanelParserTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T2 | 16-07 | 5 | DEVUI-06 | T-16-28 | /dev/system env snapshot redacts `*password*` / `*secret*` / `*key` / `*token*` keys | unit | `pest Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | SelectOnlyValidator rejects each non-SELECT shape (table-driven 7 cases) | unit | `pest Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | SelectOnlyValidator contract test (locks @internal-API rejection cases) | arch | `pest tests/Contracts/SelectOnlyValidatorContractTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | Read-only connection rejects INSERT via SQLITE_READONLY (defense-in-depth) | feature | `pest Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-29 | **W-6 fix: mockable-seam test asserts WallClockCap::apply(5) is invoked once per query (was: runaway query killed by set_time_limit(5) — that flaky wall-clock assertion is now manual-only)** | unit | `pest --filter=WallClockCap` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-29 | SQL panel writes dev_mode_audit row with rowcount + duration via AuditEvent::SqlSelect enum case | feature | `pest Modules/DevMode/tests/Feature/SqlPanelAuditTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-08/T1 | 16-08 | 6 | DEVUI-09 | T-16-33 | Palette registry contains nav + dev (SAFE only) + actions for developer; excludes DESTRUCTIVE | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` (Test 3) | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T1 | 16-08 | 6 | DEVUI-09 | T-16-34 | Palette registry excludes DEV source for non-developers | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` (Test 4) | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T2 | 16-08 | 6 | DEVUI-09 | — | Sidebar Dev block live data (queue + heartbeat) renders via wire:poll(5s) | feature | `pest Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest.php` | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T2 | 16-08 | 6 | DEVUI-09 | T-16-36 | NativePHP app-menu Developer submenu gated on is_developer; menu items use Menu::route('dev.overview', ...) (B-4 fix — not hardcoded host URL) | feature | `pest Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php` | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-01/T1 | 16-01 | 1 | 16-sidebar | T-16-01 | App-wide sidebar renders for authenticated users; Dev block server-side absent for non-devs | feature | `pest Modules/Core/tests/Feature/AppSidebarRenderTest.php` | ❌ Wave 0 (16-01 creates) | ⬜ |
| 16-01/T2 | 16-01 | 1 | 16-sidebar | — | Sidebar HTML structure snapshot (catches drift) | snapshot | `pest tests/Snapshot/SidebarTest.php` | ❌ Wave 0 (16-01 creates) | ⬜ |
| 16-02/T1 | 16-02 | 2 | 16-rename | T-16-04 | Renamed `beatrax:*` commands resolve; no `diederik:` signature remains | feature | `pest tests/Feature/BeatraxCommandsResolveTest.php` | ❌ Wave 0 (16-02 creates) | ⬜ |
| 16-02/T3 | 16-02 | 2 | 16-rename | T-16-04 | No `diederik` literal in `Modules/*` post-rename (allow-listed exceptions) | arch | `pest --filter noDiederikLiteralAfterRename` | ❌ Wave 0 (16-02 creates) | ⬜ |
| 16-02/T3 | 16-02 | 2 | Ph12-cleanup | T-16-05 | `ImpersonateUserAction` + EndImpersonationAction + ImpersonationBannerMiddleware + impersonation Blade partial absent | arch | `pest --filter impersonationSurfaceRemoved` | ❌ Wave 0 (16-02 creates) | ⬜ |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Task ID format: `{plan}/T{task-number-within-plan}`. A single Task ID may map to multiple rows (covers multiple behaviors). "Wave 0" in the File Exists column refers to the test scaffolding created by the named plan — once that plan ships, the file exists.*

---

## Wave 0 Requirements

> All Wave 0 prerequisites are landed by the early plans (16-01, 16-02, 16-03, 16-04, 16-04b). No external Wave-0 dependency is left implicit.

- [x] **16-01:** Tailwind v4 `@theme` token block landed in `resources/css/app.css`
- [x] **16-02:** All `beatrax:*` command signatures resolve (`tests/Feature/BeatraxCommandsResolveTest.php` proves)
- [x] **16-02:** Two arch invariants (`noDiederikLiteralAfterRename`, `impersonationSurfaceRemoved`) added to `BoundaryArchTest`
- [x] **16-03:** `Modules/DevMode/tests/Pest.php` + `Modules/DevMode/tests/TestCase.php` module test base
- [x] **16-03:** Module skeleton (`DevModeServiceProvider`, `Routes/web.php`, `Resources/views/` placeholder)
- [x] **16-03:** `composer.json` autoload-dev — `Modules\\DevMode\\Tests\\` PSR-4 mapping
- [x] **16-03:** `bootstrap/providers.php` — register `DevModeServiceProvider`
- [x] **16-03:** `composer.json` — add `spatie/laravel-activitylog ^5.0` (NOT `^4.12` — see RESEARCH Pitfall 3) + `doctrine/sql-formatter ^1.5`
- [x] **16-03:** `package.json` — add `fuse.js@^7.0`
- [x] **16-03:** `config/activitylog.php` — publish, set `table_name: dev_mode_audit`
- [x] **16-03:** `config/database.php` — add `readonly_select` connection (PRAGMA `query_only=1` set per-PDO in 16-07)
- [x] **16-03:** `config/logging.php` — publish, flip `single` → `daily`, add `tap: []` placeholder slot on every channel (16-04b fills the stack/single/daily tap slots with PushRedactProcessor::class AND installs the baseline RedactSecretsProcessor per W-1 fix; 16-05 upgrades the processor in place via constructor DI)
- [x] **16-03:** First arch invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` added to `BoundaryArchTest` (I-2: filter tightened to `dev/` prefix)
- [x] **16-04:** CommandRegistry + Spawner + RunRegistry + FileTailer + ArtisanStreamController (spawn-then-tail architecture b per D-16, with B-1 page-refresh-reconnect Pest test as the headline contract)
- [x] **16-04:** ArtisanStreamReconnectTest at `Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php` (B-1 / B-6 fix — the D-16 contract test)
- [x] **16-04b:** Baseline RedactSecretsProcessor (`Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php`) + PushRedactProcessor (`Modules/DevMode/Internal/Logging/PushRedactProcessor.php`) + config/logging.php tap-slot fill on stack/single/daily channels + RedactSecretsProcessorBaselineTest (W-1 fix: closes the redaction-gap window in the same Wave 4 as the runner; 16-05 upgrades the processor in place via constructor DI without touching wiring)
- [x] **16-04b:** RedactionExcerptCap (basic Bearer + JWT version; 16-05 upgrades to full scrub-set)
- [x] **16-04b:** AuditEvent enum at `Modules/DevMode/Internal/Enums/AuditEvent.php` (I-5 fix — locks audit taxonomy; 16-06/16-07 extend with their cases)
- [x] **16-04b:** SpatieAuditWriter + FinalizeRunAudit hook wired into 16-04's ArtisanStreamController
- [x] **16-04b:** TripleGateModal + DestructiveSpawnController + Queue::looping DI-form heartbeat (W-8 fix)
- [x] **16-06:** `DevSidebarItems` service registered (foundation for Wave 5 parallelism); sibling-plan slugs default to `enabled => false` (W-3 fix)
- [x] **16-07:** `SelectOnlyValidatorContractTest` at `tests/Contracts/` locking the @internal Tokenizer behavior
- [x] **16-07:** `WallClockCap` at `Modules/DevMode/Internal/Sql/WallClockCap.php` (W-6 fix — mockable seam around set_time_limit)

---

## Wave Sequence

| Wave | Plans | Notes |
|------|-------|-------|
| 1 | 16-01 | Sidebar restructure + theme tokens (foundation for all downstream UI). |
| 2 | 16-02 | diederik→beatrax rename + Phase 12 impersonation removal. |
| 3 | 16-03 | DevMode module skeleton + EnsureDeveloperMode + Settings toggle + Wave-0 infra. **DEVUI-01.** |
| 4 | 16-04 → 16-04b (SERIAL within wave) | **B-5 split: 16-04 (process pipeline + SSE spawn-then-tail per D-16 + missing commands) — 16-04b (audit pipeline + triple-gate + heartbeat + ArtisanRunnerPage + AuditLogPage + sidebar enable).** 16-04 ships first; 16-04b ships immediately after on top. Both inside Wave 4. **DEVUI-02 (16-04 + 16-04b), DEVUI-03 (16-04b).** |
| 5 | 16-05, 16-06, 16-07 (parallel) | Log tailer (16-05), queue inspector + Horizon iframe (16-06), Doctor + System + SQL + Schema-as-inner-sidebar (16-07). **DEVUI-04, DEVUI-05, DEVUI-06, DEVUI-07, DEVUI-08.** File-overlap conflicts resolved via DevSidebarItems service (16-06) + DevModeServiceProvider sequential edit. |
| 6 | 16-08 | Command palette (script in resources/js/palette.js per W-7) + sidebar Dev-block live data + app-menu Developer submenu via Menu::route('dev.overview', ...) (B-4 fix). **DEVUI-09.** |

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| ⌘K palette opens on Mac, Ctrl+K on Win/Linux | DEVUI-09 | Real-keyboard cross-platform check | macOS: `composer dev`, sign in as developer, press ⌘K — palette opens. Repeat on Win/Linux build (Phase 17 CI). |
| **I-7: ⌘K body-level handler does NOT fire inside input/textarea fields** | DEVUI-09 | Real-keyboard focus + copy-paste safety verification | Open any page with a text input or textarea (/transactions search box); click into the input; type some content; press ⌘K. The palette MUST NOT open (the body x-data handler early-returns when `document.activeElement` is INPUT/TEXTAREA/contentEditable). If the palette opens here, the keybind is stealing keystrokes from text fields and the input/textarea carve-out is broken. |
| App-menu Developer submenu appears only for `is_developer=true` | DEVUI-01 + DEVUI-09 | NativePHP app-menu binding — runtime check, no Pest equivalent; per RESEARCH Pitfall 9 menu changes need full bundle quit | Run native build, sign in as developer → menu shows; the Developer submenu items use `Menu::route('dev.overview', ...)` (B-4) so they work in both Herd dev (`http://beatrax.test`) AND shipped Electron (`http://127.0.0.1:{port}`). Toggle `is_developer=false` via Settings → quit bundle → relaunch → menu absent. |
| Settings → Developer Mode toggle persists across logout/login | DEVUI-01 | Session + DB persistence interaction | Toggle on → log out → log in → toggle still on (state survives session reset). |
| Live stdout streaming feels sub-second for `db:backup` | DEVUI-02 | Subjective UX latency | Run `db:backup` from runner UI; verify lines appear within ~500ms of process emit. |
| Triple-gate modal UX (typed-name + Esc + Cancel only — click-outside does NOT close) | DEVUI-03 | UX correctness | Open destructive command modal; click outside scrim → modal stays; press Esc → closes; type `Beatrax` (wrong case) → primary stays disabled; type `beatrax` → primary enables → click Run → spawn fires. |
| **B-2: Fallback runner modal lists ONLY SAFE-tier commands** | DEVUI-02 | Visual + click-through regression check (Pest test 16-04b/T3 covers automated; manual confirms UI matches the test) | Open /dev/artisan; open the fallback "Run command" modal; visually inspect: no `db:restore`, `migrate:fresh`, `beatrax:install`, `beatrax:reset-password`, `beatrax:grant-dev`, or `beatrax:regenerate-recovery-codes` button. Attempting to spawn a DESTRUCTIVE command from this modal MUST be impossible (the buttons are absent, not just disabled). |
| Log tailer rotation detection survives `php artisan logs:clear` or manual file rotate | DEVUI-04 | OS-dependent file-system semantics | Tail /dev/logs; delete + recreate today's log file; verify stream re-opens without crashing. |
| Horizon iframe loads correctly under NativePHP runtime | DEVUI-08 | Renders external app inside iframe | composer dev; visit /dev/horizon; verify Horizon UI loads (mouse/keyboard work inside the frame). |
| **W-6: /dev/sql runaway query is killed by set_time_limit(5)** | DEVUI-07 | Wall-clock timing assertion is flaky in automated tests; the WallClockCap mockable-seam test (16-07/T3 unit) covers the seam — this manual case covers the real-time-elapses behavior | Run a deliberate `SELECT * FROM big_table a, big_table b LIMIT 1` cross-join over a seeded large dataset; verify it dies before 6 seconds. Manual-only — the automated equivalent that asserts wall-clock elapsed time was REMOVED per W-6 because it was flaky. |
| Manual cut-over after 16-02 lands locally | 16-rename | Per D-09, the developer hand-edits .env / Herd hostname / .app bundle | Follow the Hand-off Notes in 16-02-SUMMARY.md after pulling the rename commit. |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (filled by planner)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify (Wave 0 setup tasks in 16-03 + 16-04 + 16-04b + 16-05 + 16-07 each create their own test scaffolding within the same task)
- [x] Wave 0 covers all MISSING references (per Wave 0 Requirements checklist above) — including B-1 ArtisanStreamReconnectTest, W-1 baseline RedactSecretsProcessor + PushRedactProcessor + config/logging.php tap-slot fill + RedactSecretsProcessorBaselineTest in 16-04b, I-5 AuditEvent enum in 16-04b, W-6 WallClockCap in 16-07
- [x] No watch-mode flags
- [x] Feedback latency < 30s for the per-task quick filter
- [ ] `nyquist_compliant: true` set in frontmatter (set by `/gsd:verify-work`)

**Approval:** pending verifier (REVISION PASS 1 applied — B-1 / B-2 / B-3 / B-4 / B-5 / B-6 BLOCKERS resolved; W-1 / W-2 / W-3 / W-4 / W-5 / W-6 / W-7 / W-8 WARNINGS resolved; I-1 / I-2 / I-3 / I-4 / I-5 / I-6 / I-7 INFO items applied)
