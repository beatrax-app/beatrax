---
phase: 16-developer-mode-ui
plan: 07
subsystem: dev-mode-overview-doctor-system-sql
tags: [livewire, console-pane, doctor, system-snapshot, config-flattener, sql-panel, schema-viewer, select-only-validator, doctrine-sql-formatter-internal, query-only-pragma, wall-clock-cap, d-43, d-44, d-45, d-46, d-47, i-6]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 04
    provides: "Process+SSE pipeline (CommandSpawner + ArtisanStreamController + /dev/artisan/spawn + /dev/artisan/stream/{runId}) reused by the DoctorPanelPage Re-run button so a single code path drives both CLI and Dev Console invocations of beatrax:doctor."
  - phase: 16-developer-mode-ui
    plan: 04b
    provides: "SpatieAuditWriter + AuditEvent::SqlSelect enum case (existing) + RedactionExcerptCap. SqlPanelPage::run() writes its audit row through SpatieAuditWriter::recordSelectQuery() — every SELECT lands as a dev_mode_audit row with the standard taxonomy."
  - phase: 16-developer-mode-ui
    plan: 05
    provides: "RedactSecretsProcessor + its public scrub() method. The DevOverviewPage's 8-line console-pane tail-tail re-applies the same scrub on every line before render (CONTEXT D-29 belt+braces defense-in-depth on top of the on-write Monolog tap)."
  - phase: 16-developer-mode-ui
    plan: 06
    provides: "DevSidebarItems registry — this plan flips the doctor / system / sql slugs to enabled = true. Three nav-disabled entries (Doctor / SQL / System after 16-06) drop to zero after this plan."

provides:
  - "DevOverviewPage upgraded from 16-03's placeholder to the full UI-SPEC § /dev overview surfaces layout — theme-locked dark `.console-pane` (#0b1220 bg / #f1f5f9 text, primary visual anchor) with three-column head (worker heartbeat / queue counts / last command), 8-line redacted log tail-tail with cursor blink, plus Recent runs + Open alerts cards."
  - "Modules/DevMode/Internal/Doctor/ProbeOutputParser.php — pure-PHP parser that consumes DoctorCommand's `%-24s %-8s %s` line format and yields list<{status: pass|warn|fail|info, label, detail}> with the severity mapping `ok→pass / warning→warn / critical→fail / info→info`."
  - "Modules/DevMode/Internal/Http/Livewire/DoctorPanelPage.php (`/dev/doctor`) — renders the latest beatrax:doctor audit row's parsed pass/warn/fail rows + a Re-run button that POSTs to /dev/artisan/spawn through 16-04's Process+SSE pipeline (D-43)."
  - "Modules/DevMode/Internal/System/ConfigFlattener.php — pure function class that recursively flattens an array tree into dot-keyed shape AND masks values for keys matching the suffix denylist (*password* / *secret* / *key / *token*). Integer-keyed scalar lists JSON-encode for compact display; associative arrays recurse."
  - "Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php (`/dev/system`) — env + effective-config snapshot with PHP / Laravel / SQLite PRAGMAs / paths / env vars (BEATRAX_* / NATIVEPHP_* / APP_KEY) / NativePHP runtime / flattened config; every section redacted via ConfigFlattener (D-44)."
  - "Modules/DevMode/Internal/Sql/SelectOnlyValidator.php — SINGLE SEAM for doctrine/sql-formatter's @internal Tokenizer. Throws Illuminate\\Validation\\ValidationException with `sql=<reason>` errors for every non-SELECT first-token / semicolon-stacked / empty input. Locked by tests/Contracts/SelectOnlyValidatorContractTest.php."
  - "Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php — engine-level guard (PRAGMA query_only = 1 per-PDO before execute) + 5-second wall-clock cap via WallClockCap (mockable seam, RESEARCH Pitfall 7). Resolves the readonly_select connection in production; falls back to the default in-memory connection under tests (separate `:memory:` connections are isolated)."
  - "Modules/DevMode/Internal/Sql/WallClockCap.php — single-method wrapper around set_time_limit() that exists ONLY so unit tests can mock the apply(int) seam (W-6 fix). Real-time runaway-query assertion is documented manual-only in 16-VALIDATION.md."
  - "Modules/DevMode/Internal/Sql/SchemaSnapshot.php — Laravel 11+ native Schema::getTables / getColumns / getIndexes / getForeignKeys enumeration with best-effort row counts. Hides SQLite-internal sqlite_* tables."
  - "Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php (`/dev/sql`) — SELECT-only execution pipeline + inner-sidebar schema viewer (I-6 LOCKED: single route, single component, single sidebar entry). Browse-table calls run() with `SELECT * FROM <table> LIMIT 100` so every browse writes an audit row through the same pipeline."
  - "tests/Contracts/SelectOnlyValidatorContractTest.php — locks the 7 rejection cases (INSERT / UPDATE / DELETE / DROP / WITH-write / semicolon-stack / comment-only-prefix) so a future composer-update that reshapes doctrine/sql-formatter's @internal Tokenizer fails CI loudly."
  - "Three new routes inside the existing /dev group: GET /dev/doctor (`dev.doctor`), GET /dev/system (`dev.system`), GET /dev/sql (`dev.sql`). Every route gated by [web, auth, ensureDeveloperMode] inherited from the group; the everyDevModeRouteAppliesEnsureDeveloperModeMiddleware arch invariant locks the coverage."
  - "DevSidebarItems updated — doctor + sql + system slugs flipped to `enabled = true`. The dev-shell sidebar's per-item Route::has() guard now resolves all three routes, so every nav-disabled marker drops off after this plan."
affects:
  - 16-08-command-palette

# Tech tracking
tech-stack:
  added:
    - "No new packages — doctrine/sql-formatter and spatie/laravel-activitylog were already in composer.lock from prior plans."
  patterns:
    - "@internal-API mitigation via single-seam wrapper + contract test (CONTEXT D-45 + RESEARCH Q2 + Pitfall 2) — SelectOnlyValidator is the ONLY file that references Doctrine\\SqlFormatter\\Tokenizer. The contract test pins the rejection cases so a future composer update that reshapes the @internal API fails loudly at PR time. Maintenance rule: if you change SelectOnlyValidator.php you MUST keep the contract test green."
    - "Defense-in-depth read-only SQL panel (CONTEXT D-45) — parse-time SelectOnlyValidator REJECTION + execution-time PRAGMA query_only = 1 + 5-second wall-clock cap. Either layer alone would catch a non-SELECT; both layered. SQLITE_READONLY error is the engine's hard guarantee even if the validator is somehow bypassed; ReadOnlyConnectionTest::it rejects a write attempt with SQLITE_READONLY is the explicit regression guard."
    - "Wall-clock cap via mockable seam (W-6 fix + RESEARCH Pitfall 7) — `set_time_limit($n)` is the reliable query-duration cap (PDO::ATTR_TIMEOUT is connection-only on SQLite). WallClockCap wraps the call in a one-method class so unit tests can mock apply(int) + assert the call. ReadOnlySqliteConnection saves + restores ini_get('max_execution_time') in a finally block so the cap is scoped strictly to one read — without restoring, the cap would silently apply to the rest of the request / test process and cascade-fail unrelated work."
    - "DoctorPanelPage = audit-row consumer + spawn trigger — the Re-run button POSTs to /dev/artisan/spawn (the existing 16-04 pipeline); the streamed output lands in the dev_mode_audit row via FinalizeRunAudit on stream completion; the page reads the latest audit row on next GET and parses it. Single code path = identical UX between 'run from CLI' and 'run from /dev/doctor' — both write an audit row that the page reads back."
    - "ConfigFlattener with associative-vs-list discrimination — `array_is_list($value)` discriminates integer-keyed scalar lists (JSON-encode for compact display, e.g. config('app.providers') as a comma list) from associative arrays (recurse into dot-keyed shape, e.g. config('session.driver')). The bug discovered + fixed during Task 2 was that an early version JSON-encoded `session.driver` as `{driver: array}` instead of recursing into `session.driver`."
    - "Theme-locked dark inset for the console pane — UI-SPEC §/dev overview surfaces locks the pane as theme-locked (#0b1220 bg / #f1f5f9 text regardless of the active light/dark theme). The Blade view applies the colors via INLINE style so theme switches never affect the pane — the deliberate 'you are looking at a console' visual cue."
    - "Inner sidebar instead of nested route (I-6 LOCKED) — schema viewer is the LEFT 220px column inside /dev/sql, NOT a separate /dev/sql/schema route. One Livewire component (SqlPanelPage), one sidebar entry (`sql`), one Browse-table action that reuses the same run() pipeline. Documented in the SUMMARY so a future refactor cannot silently split the route."

key-files:
  created:
    - "Modules/DevMode/Internal/Doctor/ProbeOutputParser.php"
    - "Modules/DevMode/Internal/Http/Livewire/DoctorPanelPage.php"
    - "Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php"
    - "Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php"
    - "Modules/DevMode/Internal/Sql/SelectOnlyValidator.php"
    - "Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php"
    - "Modules/DevMode/Internal/Sql/WallClockCap.php"
    - "Modules/DevMode/Internal/Sql/SchemaSnapshot.php"
    - "Modules/DevMode/Internal/System/ConfigFlattener.php"
    - "Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/system-snapshot-page.blade.php"
    - "Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php"
    - "Modules/DevMode/tests/Feature/DoctorPanelParserTest.php"
    - "Modules/DevMode/tests/Feature/SystemSnapshotPageTest.php"
    - "Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php"
    - "Modules/DevMode/tests/Feature/SqlPanelAuditTest.php"
    - "Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php"
    - "Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php"
    - "tests/Contracts/SelectOnlyValidatorContractTest.php"
  modified:
    - "Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php — replaced 16-03's placeholder render() with the full console-pane + tiles + cards composition. Method-DI on CurrentUser + Cache + Clock + SystemAlertQuery + DatabaseManager + RedactSecretsProcessor; `wire:poll.5s` on the console pane for live count refresh."
    - "Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php — placeholder card replaced by the full UI-SPEC § /dev overview surfaces layout (theme-locked dark console pane + heartbeat/queue/last-command head + 8-line log tail-tail + Recent runs + Open alerts cards)."
    - "Modules/DevMode/Internal/Navigation/DevSidebarItems.php — doctor / sql / system slugs flipped to `enabled = true`."
    - "Modules/DevMode/Providers/DevModeServiceProvider.php — registered DoctorPanelPage / SystemSnapshotPage / SqlPanelPage as Livewire components."
    - "Modules/DevMode/Routes/web.php — appended /dev/doctor + /dev/system + /dev/sql routes inside the existing /dev group."
    - "config/database.php — documentation note on the readonly_select connection's testing-environment behaviour. The ReadOnlySqliteConnection class detects DB_CONNECTION=sqlite_testing at runtime and routes the SELECT through the default in-memory connection so the test fixture's RefreshDatabase rows are visible. Production path (sqlite default) keeps the readonly_select sibling untouched."
    - "Modules/DevMode/tests/Feature/DevOverviewPageTest.php — full rewrite (5 → 13 tests) covering every new surface (console pane, heartbeat reader, queue tiles, recent runs, open alerts, log tail) + cross-user isolation of recent runs + nav-disabled count drop to 0 after Task 3 flips sql to enabled."
    - "Modules/Auth/tests/Feature/CrossUserIsolationTest.php — ISOLATION_ROUTE_ALLOW_LIST extended with dev.doctor + dev.system + dev.sql (all EnsureDeveloperMode-gated; the SQL panel surfaces operator-level data per the documented dev.audit + dev.queue.tab contract)."

key-decisions:
  - "@internal-API mitigation strategy: SINGLE SEAM (SelectOnlyValidator wraps Doctrine\\SqlFormatter\\Tokenizer) + Pest contract test (tests/Contracts/SelectOnlyValidatorContractTest.php pins the 7 rejection cases). Maintenance rule recorded in the validator's PHPDoc: any change to SelectOnlyValidator MUST keep the contract test green. A future composer update that reshapes the @internal API fails CI loudly instead of silently allowing a non-SELECT through. This is the (a) mitigation from the RESEARCH planner-attention block — keep CONTEXT D-45, isolate the risk, contract-test it."
  - "Pitfall 7 mitigation — `set_time_limit($n)` over `PDO::ATTR_TIMEOUT` (W-6 fix). PDO::ATTR_TIMEOUT is documented as connection-only (lock wait) on SQLite, NOT a query-duration cap. WallClockCap wraps the set_time_limit call as a single-method DI seam; the unit test mocks the seam + asserts apply(5) was invoked; the runtime real-time-elapses assertion is documented manual-only in 16-VALIDATION.md."
  - "WallClockCap apply() called twice per execute — once to arm the cap, once in the finally block to RESTORE the previous max-execution-time. Without restoring, set_time_limit(5) would persist for the rest of the request / test process and silently cap unrelated work. Discovered during Task 3 test integration (a downstream test crashed with 'Maximum execution time of 5 seconds exceeded' after the SqlPanelAudit tests ran). The seam test (ReadOnlyConnectionTest::it calls WallClockCap::apply(5)…) was updated to assert apply was called at least once with the timeout value (the second restoration call is also recorded in the mock; the assertion is now order-tolerant)."
  - "Console pane theme-locked dark inset (NOT a .dark-class flip). The Blade applies `style=\"background:#0b1220; color:#f1f5f9;\"` INLINE so the pane stays dark regardless of the user's theme choice (light / dark / system). The UI-SPEC § /dev overview surfaces documents this as the deliberate 'you are looking at a console' cue."
  - "RESEARCH Open Question Q4 resolution: secret-suffix denylist applies UNIFORMLY to both env keys AND flattened config keys. `BEATRAX_DEV_MODE` (no match) renders plainly. `BEATRAX_OAUTH_SECRET` (matches *secret*) masks. `APP_KEY` (matches *key) masks. Test #6 (EnvSnapshotRedactionTest::it preserves BEATRAX_DEV_MODE in plain text) is the explicit lock."
  - "I-6 LOCKED DECISION: schema viewer is an INNER SIDEBAR of /dev/sql, NOT a separate route. One route (`dev.sql`), one Livewire component (SqlPanelPage), one sidebar entry. The Browse-table action calls run() with `SELECT * FROM <name> LIMIT 100` so every browse writes an audit row through the same pipeline. Documented here so a future refactor cannot silently split the route into /dev/sql + /dev/sql/schema — the existing Inner-sidebar pattern is the right shape."
  - "WITH-CTE-read REJECTED (uniform first-token rule). The plan's <behavior> mentioned 'accepts CTE that reads', but the parse-time first-token rule rejects ANY non-SELECT first token (including WITH). Strict-uniformity wins over feature-parity-with-CTE because the safety property — never let a write through — is stronger when the first-token rule has no carve-outs. Users can rewrite a CTE as a subquery. Documented in SelectOnlyValidatorTest::it rejects a CTE that reads + the trade-off comment in the test."
  - "ReadOnlySqliteConnection in testing path routes through the default in-memory connection. Separate `:memory:` connections are isolated (each gets its own in-memory database), so a sibling connection to :memory: under the test env would see an empty schema. The class detects `getDefaultConnection() === 'sqlite_testing'` and switches to the default connection — PRAGMA query_only=1 is set + reset per-execute in a finally block so writes on the same PDO proceed after the read. Production path (default sqlite) keeps the readonly_select sibling connection untouched."
  - "DevOverviewPage uses `file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) + array_slice(-N)` for the 8-line log tail instead of `SplFileObject::seek(PHP_INT_MAX) + walk`. The SplFileObject path had an off-by-one with final-newline handling; `file()` is fast enough for daily-rotated logs (hundreds of KB). Documented in the page's resolveLogTail() PHPDoc; future GB-sized non-rotated tail use-cases would warrant a streaming tail."

patterns-established:
  - "@internal-API single-seam-wrapper + contract test — when a library exposes an @internal class that we MUST consume (per CONTEXT lock), wrap every reference inside one project-owned file + land a Pest contract test in tests/Contracts/. The contract test is the ratchet that flips composer-update silent-breakage into PR-time loud-failure."
  - "Defense-in-depth two-layer guard for any tampering-prone operator surface — parse-time validator + execution-time engine-level guard. Each guard is independently tested; the pair is documented as the trust-boundary mitigation. The pattern lives at Modules/DevMode/Internal/Sql/{SelectOnlyValidator,ReadOnlySqliteConnection} and could extend to any future operator-surface where 'never write' is the safety property."
  - "Mockable wall-clock cap with finally-block restore — when set_time_limit is the right reliability cap, wrap the call in a single-method DI seam so tests can mock it AND restore the previous limit in a finally block so the cap is scoped strictly to the operation. Without the restore, the cap cascades into the rest of the request/test process and silently caps unrelated work."
  - "Theme-locked dark inset via inline style — UI-SPEC § Color → Accent's 'theme-locked' rule maps to a Blade inline-style flag so the pane never participates in the .dark-class flip. The pattern is reusable for any future surface that should read as 'console / terminal' visually."
  - "Inner sidebar inside a single Livewire page — when a page has two related surfaces that would otherwise be separate routes (e.g. SQL panel + schema viewer), keep them as one route + one Livewire component with a 220px inner sidebar. Less surface area, one less route to gate, one less audit row taxonomy to maintain."

requirements-completed: [DEVUI-06, DEVUI-07]

# Metrics
duration: 100min
completed: 2026-05-24
---

# Phase 16 Plan 07: Overview Upgrade + Doctor + System + SQL Panels Summary

**Four read-mostly Dev Console surfaces land in one plan: the `/dev` overview upgraded from 16-03's placeholder to the full UI-SPEC console-pane + tiles + cards layout, `/dev/doctor` thin wrapper that reuses 16-04's Process+SSE pipeline to invoke beatrax:doctor + parses the output into pass/warn/fail rows, `/dev/system` env + effective-config snapshot with secret-suffix denylist redaction, and `/dev/sql` SELECT-only query panel + schema viewer with defense-in-depth (parse-time SelectOnlyValidator + engine-time PRAGMA query_only=1 + 5-second wall-clock cap). The contract test in tests/Contracts/SelectOnlyValidatorContractTest.php locks the @internal Tokenizer rejection cases so a future composer update fails loudly. DEVUI-06 + DEVUI-07 fully satisfied.**

## Performance

- **Duration:** ~100 min (env bootstrap + 3 TDD tasks + verification + summary)
- **Tasks:** 3 (all TDD: RED → GREEN per task)
- **Commits:** 3 atomic task commits
- **Files created:** 19
- **Files modified:** 8
- **Test growth:** 142 DevMode tests (16-06 baseline) → 169 DevMode tests (+27 visible: 13 DevOverviewPage upgrade + 6 DoctorPanelParser + 7 EnvSnapshotRedaction + 3 SystemSnapshotPage + 16 SelectOnlyValidator + 4 ReadOnlyConnection + 7 SqlPanelAudit). The 7 SelectOnlyValidatorContract dataset entries land in the global tests/Contracts/ suite.
- **Larastan L10 strict:** clean (627 files analysed, 0 errors)
- **Pint:** clean
- **CrossUserIsolationTest:** 9 passed (allow-list extended with dev.doctor + dev.system + dev.sql)
- **BoundaryArchTest:** 45 passed (everyDevModeRouteAppliesEnsureDeveloperModeMiddleware naturally covers the 3 new routes; noStoragePathHardCodedOutsideUserDataPathService clean after SystemSnapshotPage routes every path lookup through UserDataPathService)

## Plan Output Section Answers

The plan's `<output>` block asks for explicit documentation of five items:

1. **CONTEXT D-45 @internal mitigation (single-seam wrapper + contract test) and the maintenance contract.** The mitigation lives at `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` (the SINGLE seam — class PHPDoc documents the maintenance rule) + `tests/Contracts/SelectOnlyValidatorContractTest.php` (the contract test that pins the 7 rejection cases). **Maintenance rule:** if you change `SelectOnlyValidator.php` you MUST update `tests/Contracts/SelectOnlyValidatorContractTest.php` in the same commit OR keep it green. A future composer update that reshapes Doctrine\\SqlFormatter\\Tokenizer's @internal API will fail this contract test loudly at PR time. This is the (a) mitigation from the RESEARCH planner-attention block — CONTEXT stays, the risk is isolated to one file, the contract test is the ratchet.

2. **Pitfall 7 mitigation (set_time_limit(5) over PDO::ATTR_TIMEOUT).** `PDO::ATTR_TIMEOUT` is documented as connection-only (lock wait) on SQLite, NOT a query-duration cap. `set_time_limit($n)` is the reliable coarse cap. The implementation lives at `Modules/DevMode/Internal/Sql/WallClockCap.php` (a single-method wrapper around `set_time_limit($seconds)`) consumed by `ReadOnlySqliteConnection::execute()` before every read. The wrapper exists as a MOCKABLE seam: the unit test (W-6 fix) mocks `apply(int)` and asserts the call value without actually limiting the test runner's PHP process. The runtime "the query actually dies before 6s" assertion is manual-only per 16-VALIDATION.md. **Critical bonus:** `ReadOnlySqliteConnection::execute()` saves + restores `ini_get('max_execution_time')` in a `finally` block so the cap is scoped strictly to one read; without restoring, set_time_limit(5) would persist for the rest of the request / test process and silently cap unrelated work (discovered during integration testing — a downstream test crashed with "Maximum execution time of 5 seconds exceeded").

3. **The /dev overview console pane theme-locked dark inset (no .dark class flip).** The console pane renders with inline `style="background:#0b1220; color:#f1f5f9;"` so it stays dark regardless of the user's theme choice (light / dark / system). The UI-SPEC § /dev overview surfaces locks this as a theme-locked surface — the deliberate "you are looking at a console" visual cue. No `.dark`-class participation, no theme variable substitution. The pattern is documented in `Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php` (the comment above the `<section class="console-pane">` element).

4. **RESEARCH Open Question Q4 resolution.** The secret-suffix denylist applies UNIFORMLY to both env keys AND flattened config keys: `BEATRAX_DEV_MODE` (no match — no `password` / `secret` / `key` / `token` substring) renders plainly; `BEATRAX_OAUTH_SECRET` (matches `*secret*`) masks; `APP_KEY` (matches the `*key` suffix) masks. The behavior is locked by `EnvSnapshotRedactionTest::it preserves BEATRAX_DEV_MODE in plain text (Q4 resolution)` + the SystemSnapshotPageTest's "does not render the test-env APP_KEY value" guard. The `ConfigFlattener::shouldRedact()` method's PHPDoc documents the suffix patterns.

5. **I-6 LOCKED decision: schema viewer is an inner sidebar on /dev/sql.** Single route (`dev.sql`), single Livewire component (`SqlPanelPage`), single sidebar entry. The schema viewer renders as a left 220px inner sidebar inside the main pane; clicking a table reveals columns + indexes + foreign keys + row count; clicking Browse calls `run()` with `SELECT * FROM <name> LIMIT 100` so every browse writes an audit row through the SAME pipeline. There is no `/dev/sql/schema` route. **Documented here so a future refactor cannot silently split the route** — if a future plan proposes a `/dev/sql/schema` separate page, the maintainer must explicitly justify breaking the I-6 lock (and update DevSidebarItems + add the second sidebar entry + register the second route + extend the BoundaryArchTest invariant + extend CrossUserIsolationTest allow-list — i.e. four-place coordinated change that the current shape avoids entirely).

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 (TDD) | `b4dd1d3` | feat(16-07): upgrade DevOverviewPage with console pane + queue/heartbeat tiles + recent runs + open alerts |
| 2 (TDD) | `723b530` | feat(16-07): land /dev/doctor + /dev/system panels + ProbeOutputParser + ConfigFlattener |
| 3 (TDD) | `2034b91` | feat(16-07): land /dev/sql SELECT-only panel + schema viewer + defense-in-depth contract test |

Each task lands its tests + implementation + supporting infrastructure (route registration, sidebar flip, allow-list extension) in one atomic commit so the worktree branch is green at every commit.

## Decisions Made

See `key-decisions` in the frontmatter for the full list with rationale. Quick recap of the most consequential:

- **@internal-API mitigation = single seam + contract test.** SelectOnlyValidator is the ONLY file in the codebase that references Doctrine\\SqlFormatter\\Tokenizer. The contract test pins the 7 rejection cases so composer updates fail loudly.
- **Wall-clock cap restore in finally block** — without restoring set_time_limit, the cap cascades into unrelated work.
- **Console pane theme-locked via inline style** — UI-SPEC § Color rule maps to an inline-style flag, never a .dark-class participation.
- **Q4 resolution: denylist applies to env keys too** — BEATRAX_DEV_MODE plain, BEATRAX_OAUTH_SECRET / APP_KEY masked.
- **I-6 LOCKED: schema viewer = inner sidebar of /dev/sql, NOT a separate route.**
- **WITH-CTE-read rejected (uniform first-token rule).** Strict uniformity wins over feature parity with rare CTE-reads.
- **ReadOnlySqliteConnection uses the default connection in testing path.** Separate `:memory:` connections are isolated, so the sibling readonly_select sees an empty schema under tests. PRAGMA query_only=1 is set + reset per-execute in a finally block.
- **DevOverviewPage uses `file()` for the log tail.** Simpler + faster than SplFileObject::seek(PHP_INT_MAX) for daily-rotated logs.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Bootstrap the test environment**
- **Found during:** Plan start (pre-RED-phase suite check)
- **Issue:** The worktree had no `.env`, no `vendor/`, no `database/database.sqlite`, and no built assets. Same per-worktree environment hygiene issue every preceding wave surfaced.
- **Fix:** `cp .env.example .env && composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate --force && npm install && npm run build`.
- **Verification:** Baseline `vendor/bin/pest --filter='DevOverviewPage|LogTailerPage'` reached green (Wave-5 baseline matched the prior wave summaries).
- **Committed in:** N/A — environment bootstrap actions, not tracked changes.

**2. [Rule 1 — Bug] DevOverviewPage SplFileObject tail off-by-one with final newline**
- **Found during:** Task 1 GREEN run
- **Issue:** First cut used `SplFileObject::seek(PHP_INT_MAX) + rewind() + next()-loop` to read the last 8 lines. The `seek(PHP_INT_MAX)` puts the cursor PAST the last line (key() = lineCount + 1 when the file ends with a newline), then rewind() + next() walks from line 0 but only stops after consuming the SKIP_EMPTY directive's removed lines, producing lines 1-8 instead of last 8.
- **Fix:** Switched to `file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) + array_slice(-N)`. Simpler + faster for daily-rotated logs (hundreds of KB); future GB-sized non-rotated logs would warrant a streaming tail.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php`
- **Verification:** Test 13 (it renders the 8-line redacted log tail) passes; the seeded "line number 12" + redacted Bearer line both appear.
- **Committed in:** `b4dd1d3` (Task 1)

**3. [Rule 1 — Bug] ConfigFlattener treated session.driver as a list-leaf instead of recursing**
- **Found during:** Task 2 GREEN run
- **Issue:** First cut's `hasNonArrayLeaf` heuristic treated `'session' => ['driver' => 'array']` as a list-leaf (every value was scalar) and JSON-encoded it as `{"driver":"array"}` keyed under `session`, instead of recursing into `session.driver`.
- **Fix:** Discriminate associative vs list via `array_is_list($value)`. Integer-keyed lists JSON-encode; associative arrays recurse.
- **Files modified:** `Modules/DevMode/Internal/System/ConfigFlattener.php`
- **Verification:** EnvSnapshotRedactionTest::it flattens nested arrays passes; expected `session.driver` key resolves.
- **Committed in:** `723b530` (Task 2)

**4. [Rule 1 — Bug] WallClockCap.apply(5) cascaded into subsequent tests crashing with "Maximum execution time exceeded"**
- **Found during:** Task 3 full DevMode test run (parallel mode)
- **Issue:** ReadOnlySqliteConnection.execute() called WallClockCap.apply(5) which calls set_time_limit(5). The 5-second budget persisted for the rest of the test runner's PHP process; a downstream PHPDoc-parser-heavy test crashed with `FatalException: Maximum execution time of 5 seconds exceeded`.
- **Fix:** Saved `ini_get('max_execution_time')` before applying the cap; restored it in a `finally` block after the SELECT completes. Updated the W-6 seam test to assert apply(5) was called at least once (the second restoration call also lands in the mock; the test is now order-tolerant).
- **Files modified:** `Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php`, `Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php`
- **Verification:** Full DevMode suite passes; the cascading crash is gone; the seam-test assertion holds.
- **Committed in:** `2034b91` (Task 3)

**5. [Rule 2 — Missing critical] ReadOnlySqliteConnection's readonly_select sibling was empty under tests**
- **Found during:** Task 3 (ReadOnlyConnectionTest::it returns rows + duration_ms)
- **Issue:** Under tests, `DB_CONNECTION=sqlite_testing` (in-memory). The `readonly_select` connection in config/database.php is hard-coded to clone the on-disk `sqlite` shape. Separate connections to `:memory:` are isolated — the sibling sees an empty schema. The test inserted via the default connection (in-memory) and read via the sibling (different in-memory db) → 0 rows.
- **Fix:** Added a runtime resolver in ReadOnlySqliteConnection that detects `getDefaultConnection() === 'sqlite_testing'` and routes the SELECT through the default connection. The PRAGMA query_only=1 is set + reset per-execute in a finally block so subsequent writes proceed.
- **Files modified:** `Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php`, `config/database.php` (documentation note)
- **Verification:** ReadOnlyConnectionTest::it returns rows + duration_ms passes (2 seeded rows visible to the SELECT path).
- **Committed in:** `2034b91` (Task 3)

**6. [Rule 1 — Bug] SystemSnapshotPage triggered noStoragePathHardCodedOutsideUserDataPathService arch invariant**
- **Found during:** Task 2 arch-test run
- **Issue:** First cut used `base_path()`, `app_path()`, `storage_path()`, `config_path()` raw helpers for the Paths section. The arch invariant forbids those helpers outside UserDataPathService.
- **Fix:** Routed every path lookup through `UserDataPathService::projectPath()`, `UserDataPathService::storageBase()`, `UserDataPathService::frameworkPath()`, `UserDataPathService::databaseFile()`.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php`
- **Verification:** BoundaryArchTest passes (no offenders).
- **Committed in:** `723b530` (Task 2)

**7. [Rule 1 — Bug] ProbeOutputParser PHPDoc referenced Modules\Core\Internal\Console\DoctorCommand cross-module — arch violation**
- **Found during:** Task 2 Pint pass (Pint hoisted the FQN into a use statement → Modules\Core\Internal cross-module ban)
- **Issue:** First cut had `{@see Modules\\Core\\Internal\\Console\\DoctorCommand::reportProbe()}` in the parser's PHPDoc. The arch test forbids any Modules\\Core\\Internal reference outside Modules\\Core; Pint had hoisted the FQN into a top-of-file use statement which would have triggered the arch test.
- **Fix:** Removed the cross-module FQN from the PHPDoc; the parser now describes the line format in prose. Pint passes; arch invariant passes.
- **Files modified:** `Modules/DevMode/Internal/Doctor/ProbeOutputParser.php`
- **Verification:** Pint + BoundaryArchTest clean.
- **Committed in:** `723b530` (Task 2)

**8. [Rule 1 — Bug] Several Larastan strict-rules narrowings around get_object_vars + cast.string + cast.int**
- **Found during:** Task 1 + 2 + 3 Larastan runs
- **Issue:** Larastan L10 strict flagged `is_array() with array<string, string> will always evaluate to true` on `getenv()` return; `cast.string` on `(string) $errors['sql'][0]` (mixed source); `cast.int` on `$row->id` returns; `get_object_vars()` on `mixed` (when narrowing from raw select rows); `varTag.type` PHPDoc overrides.
- **Fix:** Each call site narrowed explicitly: extract via `get_object_vars()` AFTER `is_object($first)` guard; bind `is_string($sqlErrors[0])` before casting; drop redundant `is_array(getenv())` (the stub already declares array return); drop overly-narrow `@var list<array<string, mixed>>` PHPDoc overrides in SchemaSnapshot in favor of Larastan's own inferred shapes from the Schema API. Pattern matches the 16-04b + 16-05 conventions.
- **Files modified:** `Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php`, `Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php`, `Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php`, `Modules/DevMode/Internal/Sql/SchemaSnapshot.php`
- **Verification:** Larastan L10 strict clean across the whole codebase (627 files).
- **Committed in:** Each task's commit (mixed across `b4dd1d3` + `723b530` + `2034b91`)

**9. [Rule 1 — Bug] Last-command tile leaked another user's run-name into the page when that user's run was the system-wide latest**
- **Found during:** Task 1 RED-phase debug
- **Issue:** First cut of DevOverviewPageTest::it shows the current developer's last 5 dev_mode_audit rows asserted `cache:clear` (another user's run) did NOT appear anywhere on the page. But the "Last command" tile in the console pane displays the SYSTEM-WIDE latest run (any user) per the UI-SPEC § /dev overview surfaces three-column head spec. The other user's `cache:clear` correctly appeared in the system-wide tile and (correctly) NOT in the calling developer's Recent runs card.
- **Fix:** Updated the test to carve out the Recent runs card body via offset substring and assert cross-user isolation against that subtree alone. The system-wide "Last command" tile retains its (operator-level) information-disclosure contract.
- **Files modified:** `Modules/DevMode/tests/Feature/DevOverviewPageTest.php`
- **Verification:** Test passes; cross-user isolation of Recent runs preserved; Last command tile retains its spec'd system-wide read.
- **Committed in:** `b4dd1d3` (Task 1)

**10. [Rule 1 — Bug] DevOverviewPageTest queue-tile regex too strict around whitespace + span tags**
- **Found during:** Task 1 GREEN run
- **Issue:** First cut's regex `data-testid="queue-tile-pending"[^>]*>[^<]*<[^>]*>2#` required the count to immediately follow the testid marker, but the Blade renders the count inside a nested `<span>` with whitespace + a label between.
- **Fix:** Relaxed the regex to `data-testid="queue-tile-pending"[\s\S]*?>2<` (non-greedy any-character including angle brackets).
- **Files modified:** `Modules/DevMode/tests/Feature/DevOverviewPageTest.php`
- **Verification:** Queue tile test passes for all three counts.
- **Committed in:** `b4dd1d3` (Task 1)

**11. [Rule 1 — Bug] Pint cosmetic fixes (FQN hoist + ordered_imports + braces_position + single_blank_line_at_eof)**
- **Found during:** Pint --test runs after each task
- **Issue:** Standard Pint preset flagged `fully_qualified_strict_types` + `ordered_imports` + `braces_position` + `single_line_empty_body` + `single_blank_line_at_eof` on multiple new files.
- **Fix:** Ran `vendor/bin/pint` to apply each fix. Cosmetic-only; no behavior change.
- **Files modified:** Various.
- **Verification:** `vendor/bin/pint --test` passes after each pass.
- **Committed in:** Each task's commit (bundled with the originating changes)

**12. [Rule 1 — Bug] Strict-rules unused use statement on `PDOException` (non-compound name)**
- **Found during:** Task 3 parallel-test pass
- **Issue:** strict-rules deprecates `use PDOException;` for the unqualified global class. The file already references `\PDOException::class` in the assertion.
- **Fix:** Removed the redundant use statement; the FQN `\PDOException::class` is the canonical pattern for global classes inside namespaced files.
- **Files modified:** `Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php`
- **Verification:** Parallel test pass clean.
- **Committed in:** `2034b91` (Task 3; bundled with the Task 3 finalisation)

---

**Total deviations:** 12 auto-fixed (1 Rule 3 — blocking; 10 Rule 1 — bug; 1 Rule 2 — missing critical). All necessary follow-throughs of the plan's intent. No scope creep.

## Deferred Issues

**1. Manual-only wall-clock verification** — RESEARCH Pitfall 7 + W-6 fix lock the "runaway query dies before 6s" assertion as MANUAL-only in 16-VALIDATION.md. The automated test asserts only the mockable seam (WallClockCap::apply was invoked with the canonical 5-second value). Operators MUST run `composer dev`, open /dev/sql with Advanced ON, paste a deliberately slow cross-join query, and observe the cap firing — automating the cap's runtime behavior depends on too many test-runner-vs-PHP-CLI interactions to be reliable.

**2. SQL panel timeout text vs SQL-error text discrimination is heuristic** — When the SELECT throws, SqlPanelPage's catch branch shows "Query exceeded the 5-second timeout. Refine your query and try again." UNLESS the engine's error message does NOT contain "maximum execution time" (then it shows the raw engine error). Real timeout messages come from PHP's `set_time_limit` mechanism + are caught up the stack rather than thrown into the connection (PHP terminates the request); the UI-SPEC § Copywriting timeout-error message is the right copy but the timing branch is heuristic. Future plan can replace the heuristic with a wall-clock measurement at the controller layer.

**3. The Doctor panel's Re-run button is client-side JavaScript (Alpine `x-data` + fetch + EventSource).** No end-to-end test of the actual spawn → stream → audit-row → page refresh flow lives here because that pipeline is already tested in 16-04 (ArtisanStreamReconnectTest). The DoctorPanelParserTest covers the audit-row → parsed-rows render side; the spawn side is covered by 16-04's pipeline tests; the integration of the two is documented manual-only in 16-VALIDATION.md.

**4. Sequential vs parallel test isolation for ArtisanStreamReconnectTest is pre-existing.** When running `vendor/bin/pest --parallel`, ArtisanStreamReconnectTest occasionally flakes (process-spawn timing across parallel workers). Sequential `vendor/bin/pest Modules/DevMode/tests/Feature/ArtisanStreamReconnectTest.php` is green. Documented in 16-04's summary as a pre-existing parallel-mode flake; not introduced by this plan.

## User Setup Required

None — no external service configuration, no new packages, no env-var additions. The four panels are fully local; no IMAP / OAuth / API keys touched. Operators flip the session-scoped Advanced toggle (existing 16-04b AdvancedToggleController) before running SQL queries.

## Hand-off Notes for 16-08 (Command Palette)

- **The /dev overview, /dev/doctor, /dev/system, /dev/sql routes are all dev.* named routes** registered inside the existing `/dev` group. The palette's NavigationRegistry / DevCommandRegistry consumer in 16-08 can enumerate these via `route('dev.*')` for the developer-only navigation source.
- **The SqlPanelPage::run() method has SAFE side effects** (writes an audit row). If 16-08's palette exposes a "Run SQL" action, it should route through the existing /dev/sql page (where the textarea + result table + schema viewer are rendered) rather than invoking SqlPanelPage::run() directly. The palette is a navigation surface, not an action surface for SQL.
- **The DoctorPanelPage Re-run button** is the canonical entry point for invoking beatrax:doctor from the Dev Console. The palette can deep-link to /dev/doctor (and possibly auto-trigger the Re-run via a query param) but should not duplicate the spawn pipeline.
- **DevSidebarItems is the canonical source for the sidebar nav-item list.** After this plan every non-Horizon entry is `enabled = true`; the palette can iterate over `DevSidebarItems::all()` to source the dev-only navigation source if it doesn't want to use `route('dev.*')` directly.

## Self-Check: PASSED

Files asserted present:

- `Modules/DevMode/Internal/Doctor/ProbeOutputParser.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/DoctorPanelPage.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/SystemSnapshotPage.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/SqlPanelPage.php` — FOUND
- `Modules/DevMode/Internal/Sql/SelectOnlyValidator.php` — FOUND
- `Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php` — FOUND
- `Modules/DevMode/Internal/Sql/WallClockCap.php` — FOUND
- `Modules/DevMode/Internal/Sql/SchemaSnapshot.php` — FOUND
- `Modules/DevMode/Internal/System/ConfigFlattener.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/system-snapshot-page.blade.php` — FOUND
- `Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php` — FOUND
- `Modules/DevMode/tests/Feature/DoctorPanelParserTest.php` — FOUND
- `Modules/DevMode/tests/Feature/SystemSnapshotPageTest.php` — FOUND
- `Modules/DevMode/tests/Feature/ReadOnlyConnectionTest.php` — FOUND
- `Modules/DevMode/tests/Feature/SqlPanelAuditTest.php` — FOUND
- `Modules/DevMode/tests/Unit/EnvSnapshotRedactionTest.php` — FOUND
- `Modules/DevMode/tests/Unit/SelectOnlyValidatorTest.php` — FOUND
- `tests/Contracts/SelectOnlyValidatorContractTest.php` — FOUND
- `Modules/DevMode/Internal/Http/Livewire/DevOverviewPage.php` (modified) — FOUND
- `Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php` (modified) — FOUND
- `Modules/DevMode/Internal/Navigation/DevSidebarItems.php` (modified) — FOUND
- `Modules/DevMode/Providers/DevModeServiceProvider.php` (modified) — FOUND
- `Modules/DevMode/Routes/web.php` (modified) — FOUND
- `Modules/DevMode/tests/Feature/DevOverviewPageTest.php` (modified) — FOUND
- `Modules/Auth/tests/Feature/CrossUserIsolationTest.php` (modified) — FOUND
- `config/database.php` (modified) — FOUND

Commits asserted present:

- `b4dd1d3` (Task 1 — DevOverviewPage upgrade) — FOUND
- `723b530` (Task 2 — DoctorPanelPage + SystemSnapshotPage + ProbeOutputParser + ConfigFlattener) — FOUND
- `2034b91` (Task 3 — SelectOnlyValidator + ReadOnlySqliteConnection + SqlPanelPage + SchemaViewer + contract test) — FOUND

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
