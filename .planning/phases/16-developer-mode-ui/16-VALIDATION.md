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

> Bootstrapped from RESEARCH.md § Phase Requirements → Test Map. Planner has filled `Task ID` / `Plan` / `Wave` columns by emitting PLAN.md files 16-01 through 16-08.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 16-03/T1 | 16-03 | 3 | DEVUI-01 | T-16-01 | Non-developer → 404 (not 403) | feature | `pest Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-03/T2 | 16-03 | 3 | DEVUI-01 | T-16-01 | Every `/dev/*` route applies `EnsureDeveloperMode` | arch | `pest --filter everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-03/T3 | 16-03 | 3 | DEVUI-01 | T-16-08 | Settings Developer toggle writes users.is_developer; survives logout/login | feature | `pest Modules/Core/tests/Feature/SettingsPageDevModeToggleTest.php` | ❌ Wave 0 (16-03 creates) | ⬜ |
| 16-04/T3 | 16-04 | 4 | DEVUI-02 | — | SAFE-tier `db:backup` streams stdout + writes audit | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T3 | 16-04 | 4 | DEVUI-02 | T-16-12, T-16-16 | Cancel sends SIGTERM + audit records `cancelled` | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerCancelTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T2 | 16-04 | 4 | DEVUI-03 | T-16-02 | DESTRUCTIVE `db:restore` rejected without all three gates | feature | `pest Modules/DevMode/tests/Feature/TripleGateTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T2 | 16-04 | 4 | DEVUI-03 | T-16-13 | `dev_mode_audit` row written via `spatie/laravel-activitylog` ^5.0 | feature | `pest Modules/DevMode/tests/Feature/AuditLogWriteTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-04/T2 | 16-04 | 4 | DEVUI-02 | — | Worker heartbeat updates cache on every Queue::looping | feature | `pest Modules/DevMode/tests/Feature/WorkerHeartbeatTest.php` | ❌ Wave 0 (16-04 creates) | ⬜ |
| 16-05/T1 | 16-05 | 5 | DEVUI-04 | T-16-03 | Injected secret in log line is redacted before stream emit (on-write via tap) | unit | `pest Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T1 | 16-05 | 5 | DEVUI-04 | T-16-03, T-16-17 | OAuth secret saved → scrub-set busted → next line `[REDACTED]` | feature | `pest Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T2 | 16-05 | 5 | DEVUI-04 | T-16-18 | Log stream controller emits redacted lines + detects rotation | feature | `pest Modules/DevMode/tests/Feature/LogStreamControllerTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-05/T2 | 16-05 | 5 | DEVUI-04 | T-16-19 | Log tailer page renders + context endpoint returns ±10 redacted lines | feature | `pest Modules/DevMode/tests/Feature/LogTailerPageTest.php` | ❌ Wave 0 (16-05 creates) | ⬜ |
| 16-06/T1 | 16-06 | 5 | DEVUI-05 | T-16-23 | Queue inspector retry-failed re-dispatches + removes failed row | feature | `pest Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T1 | 16-06 | 5 | DEVUI-05 | T-16-02, T-16-22 | Bulk delete requires triple-gate | feature | `pest Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T2 | 16-06 | 5 | DEVUI-08 | T-16-24 | Horizon iframe route absent when `app.dev_mode === false` OR `class_exists()` false | feature | `pest Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-06/T2 | 16-06 | 5 | DEVUI-08 | T-16-25 | Dashboard /horizon/failed toast gated on $isDeveloper + retargeted to /dev/queue/failed | feature | `pest Modules/DevMode/tests/Feature/DashboardFailedToastGatingTest.php` | ❌ Wave 0 (16-06 creates) | ⬜ |
| 16-07/T1 | 16-07 | 5 | DEVUI-06 | — | /dev overview console pane + tiles + recent runs render | feature | `pest Modules/DevMode/tests/Feature/DevOverviewPageTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T2 | 16-07 | 5 | DEVUI-06 | — | Doctor panel parses pass/warn/fail rows from beatrax:doctor | feature | `pest Modules/DevMode/tests/Feature/DoctorPanelParserTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T2 | 16-07 | 5 | DEVUI-06 | T-16-28 | /dev/system env snapshot redacts `*password*` / `*secret*` / `*key` / `*token*` keys | unit | `pest Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | SelectOnlyValidator rejects each non-SELECT shape (table-driven 7 cases) | unit | `pest Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | SelectOnlyValidator contract test (locks @internal-API rejection cases) | arch | `pest tests/Contracts/SelectOnlyValidatorContractTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-04 | Read-only connection rejects INSERT via SQLITE_READONLY (defense-in-depth) | feature | `pest Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-07/T3 | 16-07 | 5 | DEVUI-07 | T-16-29 | SQL panel writes dev_mode_audit row with rowcount + duration | feature | `pest Modules/DevMode/tests/Feature/SqlPanelAuditTest.php` | ❌ Wave 0 (16-07 creates) | ⬜ |
| 16-08/T1 | 16-08 | 6 | DEVUI-09 | T-16-33 | Palette registry contains nav + dev (SAFE only) + actions for developer; excludes DESTRUCTIVE | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` (Test 3) | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T1 | 16-08 | 6 | DEVUI-09 | T-16-34 | Palette registry excludes DEV source for non-developers | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` (Test 4) | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T2 | 16-08 | 6 | DEVUI-09 | — | Sidebar Dev block live data (queue + heartbeat) renders via wire:poll(5s) | feature | `pest Modules/Core/tests/Feature/AppSidebarDevBlockLiveDataTest.php` | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-08/T2 | 16-08 | 6 | DEVUI-09 | T-16-36 | NativePHP app-menu Developer submenu gated on is_developer | feature | `pest Modules/DevMode/tests/Feature/AppMenuDeveloperSubmenuTest.php` | ❌ Wave 0 (16-08 creates) | ⬜ |
| 16-01/T1 | 16-01 | 1 | 16-sidebar | T-16-01 | App-wide sidebar renders for authenticated users; Dev block server-side absent for non-devs | feature | `pest Modules/Core/tests/Feature/AppSidebarRenderTest.php` | ❌ Wave 0 (16-01 creates) | ⬜ |
| 16-01/T2 | 16-01 | 1 | 16-sidebar | — | Sidebar HTML structure snapshot (catches drift) | snapshot | `pest tests/Snapshot/SidebarTest.php` | ❌ Wave 0 (16-01 creates) | ⬜ |
| 16-02/T1 | 16-02 | 2 | 16-rename | T-16-04 | Renamed `beatrax:*` commands resolve; no `diederik:` signature remains | feature | `pest tests/Feature/BeatraxCommandsResolveTest.php` | ❌ Wave 0 (16-02 creates) | ⬜ |
| 16-02/T3 | 16-02 | 2 | 16-rename | T-16-04 | No `diederik` literal in `Modules/*` post-rename (allow-listed exceptions) | arch | `pest --filter noDiederikLiteralAfterRename` | ❌ Wave 0 (16-02 creates) | ⬜ |
| 16-02/T3 | 16-02 | 2 | Ph12-cleanup | T-16-05 | `ImpersonateUserAction` + EndImpersonationAction + ImpersonationBannerMiddleware + impersonation Blade partial absent | arch | `pest --filter impersonationSurfaceRemoved` | ❌ Wave 0 (16-02 creates) | ⬜ |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Task ID format: `{plan}/T{task-number-within-plan}`. A single Task ID may map to multiple rows (covers multiple behaviors). "Wave 0" in the File Exists column refers to the test scaffolding created by the named plan — once that plan ships, the file exists.*

---

## Wave 0 Requirements

> All Wave 0 prerequisites are landed by the early plans (16-01, 16-02, 16-03). No external Wave-0 dependency is left implicit.

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
- [x] **16-03:** `config/logging.php` — publish, flip `single` → `daily`, add `tap: []` placeholder slot on every channel (16-05 fills the FQCN)
- [x] **16-03:** First arch invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` added to `BoundaryArchTest`
- [x] **16-04:** RedactionExcerptCap (basic Bearer + JWT version; 16-05 upgrades to full scrub-set)
- [x] **16-05:** `config/logging.php` tap slot filled with `PushRedactProcessor::class`
- [x] **16-06:** `DevSidebarItems` service registered (foundation for Wave 5 parallelism)
- [x] **16-07:** `SelectOnlyValidatorContractTest` at `tests/Contracts/` locking the @internal Tokenizer behavior

---

## Wave Sequence

| Wave | Plans | Notes |
|------|-------|-------|
| 1 | 16-01 | Sidebar restructure + theme tokens (foundation for all downstream UI). |
| 2 | 16-02 | diederik→beatrax rename + Phase 12 impersonation removal. |
| 3 | 16-03 | DevMode module skeleton + EnsureDeveloperMode + Settings toggle + Wave-0 infra. **DEVUI-01.** |
| 4 | 16-04 | Artisan runner + triple-gate + audit pipeline + heartbeat. **DEVUI-02, DEVUI-03.** |
| 5 | 16-05, 16-06, 16-07 (parallel) | Log tailer (16-05), queue inspector + Horizon iframe (16-06), Doctor + System + SQL + Schema (16-07). **DEVUI-04, DEVUI-05, DEVUI-06, DEVUI-07, DEVUI-08.** File-overlap conflicts resolved via DevSidebarItems service (16-06) + DevModeServiceProvider sequential edit. |
| 6 | 16-08 | Command palette + sidebar Dev-block live data + app-menu Developer submenu. **DEVUI-09.** |

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| ⌘K palette opens on Mac, Ctrl+K on Win/Linux | DEVUI-09 | Real-keyboard cross-platform check | macOS: `composer dev`, sign in as developer, press ⌘K — palette opens. Repeat on Win/Linux build (Phase 17 CI). |
| App-menu Developer submenu appears only for `is_developer=true` | DEVUI-01 + DEVUI-09 | NativePHP app-menu binding — runtime check, no Pest equivalent; per RESEARCH Pitfall 9 menu changes need full bundle quit | Run native build, sign in as developer → menu shows. Toggle `is_developer=false` via Settings → quit bundle → relaunch → menu absent. |
| Settings → Developer Mode toggle persists across logout/login | DEVUI-01 | Session + DB persistence interaction | Toggle on → log out → log in → toggle still on (state survives session reset). |
| Live stdout streaming feels sub-second for `db:backup` | DEVUI-02 | Subjective UX latency | Run `db:backup` from runner UI; verify lines appear within ~500ms of process emit. |
| Triple-gate modal UX (typed-name + Esc + Cancel only — click-outside does NOT close) | DEVUI-03 | UX correctness | Open destructive command modal; click outside scrim → modal stays; press Esc → closes; type `Beatrax` (wrong case) → primary stays disabled; type `beatrax` → primary enables → click Run → spawn fires. |
| Log tailer rotation detection survives `php artisan logs:clear` or manual file rotate | DEVUI-04 | OS-dependent file-system semantics | Tail /dev/logs; delete + recreate today's log file; verify stream re-opens without crashing. |
| Horizon iframe loads correctly under NativePHP runtime | DEVUI-08 | Renders external app inside iframe | composer dev; visit /dev/horizon; verify Horizon UI loads (mouse/keyboard work inside the frame). |
| /dev/sql runaway query is killed by set_time_limit(5) | DEVUI-07 | Wall-clock timing assertion is flaky in automated tests | Run a deliberate `SELECT * FROM big_table a, big_table b LIMIT 1` cross-join over a seeded large dataset; verify it dies before 6 seconds. |
| Manual cut-over after 16-02 lands locally | 16-rename | Per D-09, the developer hand-edits .env / Herd hostname / .app bundle | Follow the Hand-off Notes in 16-02-SUMMARY.md after pulling the rename commit. |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (filled by planner)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify (Wave 0 setup tasks in 16-03 + 16-04 + 16-05 + 16-07 each create their own test scaffolding within the same task)
- [x] Wave 0 covers all MISSING references (per Wave 0 Requirements checklist above)
- [x] No watch-mode flags
- [x] Feedback latency < 30s for the per-task quick filter
- [ ] `nyquist_compliant: true` set in frontmatter (set by `/gsd:verify-work`)

**Approval:** pending verifier
