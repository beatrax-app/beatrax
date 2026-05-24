---
phase: 16
slug: developer-mode-ui
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-24
---

# Phase 16 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Derived from `16-RESEARCH.md` § Validation Architecture; planner refines per-plan during task breakdown.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 + pest-plugin-arch + pest-plugin-laravel + canvural/larastan-strict-rules + spatie/pest-plugin-snapshots |
| **Config file** | `tests/Pest.php` (root) + `Modules/DevMode/tests/Pest.php` (module — Wave 0) |
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

> Bootstrapped from RESEARCH.md § Phase Requirements → Test Map. Planner fills `Task ID` / `Plan` / `Wave` columns when emitting PLAN.md files.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | TBD | DEVUI-01 | T-16-01 | Non-developer → 404 (not 403) | feature | `pest Modules/DevMode/tests/Feature/EnsureDeveloperModeTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-01 | T-16-01 | Every `/dev/*` route applies `EnsureDeveloperMode` | arch | `pest --filter everyDevModeRouteAppliesEnsureDeveloperModeMiddleware` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-02 | — | SAFE-tier `db:backup` streams stdout + writes audit | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerSafeTierTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-02 | — | Cancel sends SIGTERM + audit records `cancelled` | feature | `pest Modules/DevMode/tests/Feature/ArtisanRunnerCancelTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-03 | T-16-02 | DESTRUCTIVE `db:restore` rejected without all three gates | feature | `pest Modules/DevMode/tests/Feature/TripleGateTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-03 | — | `dev_mode_audit` row written via `spatie/laravel-activitylog` | feature | `pest Modules/DevMode/tests/Feature/AuditLogWriteTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-04 | T-16-03 | Injected secret in log line is redacted before stream emit | unit | `pest Modules/DevMode/tests/Unit/RedactSecretsProcessorTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-04 | T-16-03 | OAuth secret saved → scrub-set busted → next line `[REDACTED]` | feature | `pest Modules/DevMode/tests/Feature/OAuthScrubSetBustTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-05 | — | Queue inspector retry-failed re-dispatches + removes failed row | feature | `pest Modules/DevMode/tests/Feature/QueueInspectorActionsTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-05 | T-16-02 | Bulk delete requires triple-gate | feature | `pest Modules/DevMode/tests/Feature/QueueBulkDeleteTripleGateTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-06 | — | Doctor panel parses pass/warn/fail rows | feature | `pest Modules/DevMode/tests/Feature/DoctorPanelParserTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-06 | T-16-03 | `/dev/system` env snapshot redacts secret-suffix keys | unit | `pest Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-07 | T-16-04 | `SelectOnlyValidator` rejects each non-SELECT shape | unit | `pest Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-07 | T-16-04 | Read-only connection rejects INSERT via SQLITE_READONLY | feature | `pest Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-07 | — | SQL panel writes audit row with rowcount + duration | feature | `pest Modules/DevMode/tests/Feature/SqlPanelAuditTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-08 | — | Horizon iframe route absent when `app.dev_mode === false` | feature | `pest Modules/DevMode/tests/Feature/HorizonIframeGatingTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-09 | — | Palette registry contains nav + dev (SAFE only) + actions for developer | feature | `pest Modules/DevMode/tests/Feature/CommandPaletteRegistryTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | DEVUI-09 | T-16-01 | Palette registry excludes DEV source for non-developers | feature | same file, second `it()` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | 16-sidebar | — | Sidebar renders for authenticated users (snapshot) | snapshot | `pest tests/Snapshot/SidebarTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | 16-rename | — | Renamed commands resolve | feature | `pest tests/Feature/BeatraxCommandsResolveTest.php` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | 16-rename | — | No `diederik` literal in `Modules/*` post-rename | arch | `pest --filter noDiederikLiteralAfterRename` | ❌ W0 | ⬜ |
| TBD | TBD | TBD | Ph12-cleanup | — | `ImpersonateUserAction` class absent | arch | `pest --filter impersonationSurfaceRemoved` | ❌ W0 | ⬜ |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/DevMode/tests/Pest.php` — module-level Pest config
- [ ] `Modules/DevMode/tests/TestCase.php` — module test base
- [ ] Module skeleton (`DevModeServiceProvider`, `Routes/web.php`, `Resources/views/` placeholder)
- [ ] `composer.json` autoload-dev — `Modules\\DevMode\\Tests\\` PSR-4 mapping
- [ ] `bootstrap/providers.php` — register `DevModeServiceProvider`
- [ ] `composer.json` — add `spatie/laravel-activitylog ^5.0` (NOT `^4.12` — see RESEARCH Pitfall 1) + `doctrine/sql-formatter ^1.5`
- [ ] `package.json` — add `fuse.js@^7.0`
- [ ] `config/activitylog.php` — publish, set `table_name: dev_mode_audit`
- [ ] `config/database.php` — add `readonly_select` connection (PRAGMA `query_only=1`)
- [ ] `config/logging.php` — flip `single` → `daily`, add `tap` array for `RedactSecretsTap` on every channel
- [ ] Test files (22) enumerated in Per-Task Verification Map above
- [ ] Arch invariants: `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`, `impersonationSurfaceRemoved`, `noDiederikLiteralAfterRename`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| ⌘K palette opens on Mac, Ctrl+K on Win/Linux | DEVUI-09 | Real-keyboard cross-platform check | macOS: `composer dev`, sign in as developer, press ⌘K — palette opens. Repeat on Win/Linux build (Phase 17 CI). |
| App-menu Developer submenu appears only for `is_developer=true` | DEVUI-01 | NativePHP app-menu binding — runtime check, no Pest equivalent | Run native build, sign in as developer → menu shows. Toggle `is_developer=false` in tinker → restart app → menu absent. |
| Settings → Developer Mode toggle persists across logout/login | DEVUI-01 | Session + DB persistence interaction | Toggle on → log out → log in → toggle still on (state survives session reset). |
| Live stdout streaming feels sub-second for `db:backup` | DEVUI-02 | Subjective UX latency | Run `db:backup` from runner UI; verify lines appear within ~500ms of process emit. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies (filled by planner)
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter (set by `/gsd:verify-work`)

**Approval:** pending
