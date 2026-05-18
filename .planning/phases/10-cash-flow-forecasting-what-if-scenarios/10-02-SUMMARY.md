---
phase: 10-cash-flow-forecasting-what-if-scenarios
plan: 02
subsystem: forecasting
tags: [laravel, eloquent, spatie-data, custom-cast, state-machine, di-only, larastan-max, pest]

requires:
  - phase: 10-cash-flow-forecasting-what-if-scenarios
    plan: 01
    provides: Modules/Forecasting skeleton, five BoundaryArchTest invariants, ten-fixture corpus, listener scaffolds
  - phase: 05-chain-resolution-and-forecasts
    provides: card_statements + chain_links substrate, ThisPeriodAtAGlanceQuery::nextIcsSettlement legacy tile path
  - phase: 09-recurring-payment-drift-alerts
    provides: DriftAlertStateMachine sole-mutator precedent + InvalidStateTransitionException shape
provides:
  - Five Phase 10 schema migrations (forecast_scenarios, forecast_scenario_mutations, forecast_shortfall_windows, forecast_runs, add_forecast_columns_to_accounts)
  - Four Eloquent models with BelongsToUser + explicit casts + factories (ForecastScenario, ForecastScenarioMutation, ForecastShortfallWindow, ForecastRun)
  - Eight Public DTOs as final-readonly Spatie LaravelData records (ForecastDto, ForecastPointDto, ScenarioDto, ScenarioMutationDto, ForecastHighlightsDto, ShortfallWindowDto, BalanceAnchorDto, SeriesConfidenceDto)
  - Typed-union ScenarioMutationPayload (abstract base + five concrete subclasses) + ScenarioMutationPayloadCast routing the JSON column
  - ForecastRunStateMachine sole mutator with snapshot-locked transition map + InvalidForecastRunTransitionException
  - Modules/Chains/Public/Dto/NextSettlementDto + CardStatementQuery::nextSettlementForUser(User) — funder-aware Chains Public surface Wave 3 consumes
affects: [10-03, 10-04, 10-05, 10-06]

tech-stack:
  added: []
  patterns:
    - Custom Eloquent cast (CastsAttributes) routing a JSON column to a typed-per-kind Spatie LaravelData subclass via match($kind) — caught cross-kind property access at Larastan level 10 strict
    - Sole-mutator state machine pattern mirroring DriftAlertStateMachine (DI-only constructor with DatabaseManager + Clock; PRAGMA busy_timeout + lockForUpdate inside a transaction)
    - Funder resolution via two-step lookup (most-recent confirmed ics_bulk_settle chain_link → first ASN account fallback) with user_id filter preceding every join
    - Cross-module DTO file added in Task 2 to keep Larastan green when ForecastHighlightsDto references the Chains NextSettlementDto FQN — service method + tests land in Task 4

key-files:
  created:
    - Modules/Forecasting/Database/Migrations/2026_05_19_010001_create_forecast_scenarios_table.php
    - Modules/Forecasting/Database/Migrations/2026_05_19_010002_create_forecast_scenario_mutations_table.php
    - Modules/Forecasting/Database/Migrations/2026_05_19_010003_create_forecast_shortfall_windows_table.php
    - Modules/Forecasting/Database/Migrations/2026_05_19_010004_create_forecast_runs_table.php
    - Modules/Forecasting/Database/Migrations/2026_05_19_010005_add_forecast_columns_to_accounts.php
    - Modules/Forecasting/Models/ForecastScenario.php
    - Modules/Forecasting/Models/ForecastScenarioMutation.php
    - Modules/Forecasting/Models/ForecastShortfallWindow.php
    - Modules/Forecasting/Models/ForecastRun.php
    - Modules/Forecasting/Database/Factories/ForecastScenarioFactory.php
    - Modules/Forecasting/Database/Factories/ForecastScenarioMutationFactory.php
    - Modules/Forecasting/Database/Factories/ForecastShortfallWindowFactory.php
    - Modules/Forecasting/Database/Factories/ForecastRunFactory.php
    - Modules/Forecasting/Public/Dto/ForecastDto.php
    - Modules/Forecasting/Public/Dto/ForecastPointDto.php
    - Modules/Forecasting/Public/Dto/ScenarioDto.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationDto.php
    - Modules/Forecasting/Public/Dto/ForecastHighlightsDto.php
    - Modules/Forecasting/Public/Dto/ShortfallWindowDto.php
    - Modules/Forecasting/Public/Dto/BalanceAnchorDto.php
    - Modules/Forecasting/Public/Dto/SeriesConfidenceDto.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ScenarioMutationPayload.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/CancelSeriesPayload.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddOneOffPayload.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddRecurringPayload.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ChangeSeriesAmountPayload.php
    - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ShiftSeriesDatePayload.php
    - Modules/Forecasting/Internal/Casts/ScenarioMutationPayloadCast.php
    - Modules/Forecasting/Internal/StateMachines/ForecastRunStateMachine.php
    - Modules/Forecasting/Internal/Exceptions/InvalidForecastRunTransitionException.php
    - Modules/Forecasting/tests/Unit/MigrationsTest.php
    - Modules/Forecasting/tests/Unit/ScenarioMutationPayloadCastTest.php
    - Modules/Forecasting/tests/Unit/ForecastRunStateMachineTest.php
    - Modules/Chains/Public/Dto/NextSettlementDto.php
    - Modules/Chains/tests/Feature/CardStatementQueryNextSettlementForUserTest.php
  modified:
    - Modules/Chains/Public/Services/CardStatementQuery.php (extended with nextSettlementForUser; existing openForAccount untouched)

key-decisions:
  - "ScenarioMutationPayload abstract base + five concrete subclasses chosen over a single generic Data class with mixed properties — Larastan level 10 strict only catches cross-kind property access when the union narrowest-common surface is empty (only kind() is shared), proving the static-analysis guard fires in practice via a temp fixture file."
  - "Modules/Chains/Public/Dto/NextSettlementDto file lands in Task 2 even though the service method + feature test belong to Task 4 — committing the bare DTO file in Task 2 lets ForecastHighlightsDto reference the typed FQN and keep Larastan green at the Task 2 boundary. Task 4 then adds the populated service method + tests."
  - "ScenarioMutationPayloadCast enforces the kind/payload contract at attribute-assignment time, not at save() — this is the Eloquent cast lifecycle. Tests therefore wrap the assignment in a closure and assert the throw on `$mutation->payload = ...`, not on `$mutation->save()`."
  - "ForecastRun.user_id is non-nullable (mirrors chain_resolution_runs); every other user_id column on Wave 1 tables stays nullable to inherit the FND-03 carry-forward. MigrationsTest locks the NOT-NULL constraint with a synthetic insert that expects QueryException."

patterns-established:
  - "Typed-JSON-cast pattern for typed-per-kind union: abstract Spatie LaravelData base + concrete subclasses, custom CastsAttributes implementation routing via match($kind), throwing on unknown kind and on kind/payload mismatch — extends Phase 5's generic-JSON-as-array cast pattern (ChainLink.evidence)."
  - "ForecastRunStateMachine static transitionMap() method exposes the locked map for tests + future contributors without reflection — the snapshot-by-equality test asserts the shape so future edits are intentional."
  - "Funder-resolution two-step lookup: most-recent confirmed ics_bulk_settle chain_link → first accounts.kind='asn' fallback. user_id filter precedes every join in all three reads to preserve cross-user safety."

requirements-completed:
  - FCT-01
  - FCT-02
  - FCT-03
  - FCT-04
  - FCT-05

duration: 23min
completed: 2026-05-18
---

# Phase 10 Plan 02: Wave 1 — Schema + Public DTOs + State Machine + Chains Extension Summary

**Lands the FIVE Phase 10 schema migrations (four new tables + one column-add on Ledger's `accounts` table), the FOUR Eloquent models with explicit casts + BelongsToUser scoping, the FOUR model factories with state methods per kind / lifecycle, the EIGHT Public DTOs as final-readonly Spatie LaravelData records (including the five typed `ScenarioMutationPayload` subclasses), the Internal `ScenarioMutationPayloadCast` Eloquent custom cast that maps the JSON payload column to the typed-per-kind DTO, the `ForecastRunStateMachine` sole mutator with snapshot-locked transition map, AND the new `Modules\Chains\Public\Dto\NextSettlementDto` + `CardStatementQuery::nextSettlementForUser(User)` Public surface Wave 3's chain-aware forecasting router consumes.**

## Performance

- **Duration:** ~23 min
- **Started:** 2026-05-18T15:31Z
- **Completed:** 2026-05-18T15:55Z (approximate)
- **Tasks:** 4 (atomically committed)
- **Files created:** 35
- **Files modified:** 1 (Modules/Chains/Public/Services/CardStatementQuery.php)
- **New tests:** 35+ (11 migrations, 10 cast tests, 15 state-machine tests, 9 Chains feature tests)
- **New assertions:** 71 + 42 + 30 + 20 = 163 across the four task suites

## Accomplishments

- **Five schema migrations on disk** under `Modules/Forecasting/Database/Migrations/` numbered `2026_05_19_010001` through `2026_05_19_010005`. Every migration uses the DatabaseManager lazy-resolver shape from `DriftAlerts` (no `Schema` facade, no `db()` helper). The cross-module column-add migration extends Ledger's `accounts` table with three nullable forecast / opening-balance columns without breaking any existing Phase 1-9 insert (asserted by the test that inserts a row with the historical minimal column set).
- **Four Eloquent models** with `BelongsToUser` global scope (current-user filter implicit via UserScope), explicit `$casts` arrays using `immutable_datetime` + `immutable_date`, and explicit BelongsTo / HasMany relations. Every model declares `final class` and exposes `newFactory()` for `Model::factory()`.
- **Four factories** with state methods per kind / lifecycle. `ForecastScenarioMutationFactory` carries five state methods (`cancelSeries`, `addOneOff`, `addRecurring`, `changeSeriesAmount`, `shiftSeriesDate`) and each one round-trips through the typed cast cleanly. `ForecastRunFactory` carries the four lifecycle states (`pending`, `running`, `complete`, `failed`).
- **Eight Public DTOs + five `ScenarioMutationPayload` subclasses + abstract base** as immutable Spatie LaravelData records. Cross-kind property access on the abstract base type is caught by Larastan level 10 strict — verified with a temporary fixture file dropped under `Modules/Forecasting/Internal/`, which produced two static-analysis errors (`Access to undefined property` + the downstream `return.type` mismatch) before being cleaned up.
- **`ScenarioMutationPayloadCast`** routes the JSON `payload` column through `match($kind)` to the correct typed subclass, throws `InvalidArgumentException` on unknown kind, refuses kind/payload mismatch at assignment time, and defends against corrupted (non-array JSON / non-string) payloads.
- **`ForecastRunStateMachine`** is the sole mutator of `forecast_runs.status`, with a snapshot-locked transition map (`pending → running | failed`, `running → complete | failed`, `complete` / `failed` terminal). Every transition runs inside a transaction with `PRAGMA busy_timeout = 5000` and `lockForUpdate()`. Illegal transitions raise `InvalidForecastRunTransitionException` and leave the row untouched.
- **`Modules\Chains\Public\Dto\NextSettlementDto`** carries the funder ASN account id (NOT the ICS card account id), the `Money` amount, the `CarbonImmutable` due date, the statement id, and the lifecycle state. `CardStatementQuery::nextSettlementForUser(User)` reads the most-recent open / partially_settled card_statement and resolves the funder via the most-recent confirmed `ics_bulk_settle` chain_link with a graceful ASN-fallback. Returns null when the user has zero ASN accounts.

## Task Commits

Each task was committed atomically:

1. **Task 1: Five Phase 10 schema migrations + MigrationsTest** — `6898053` (feat)
2. **Task 2: Eloquent models + factories + Public DTOs + typed-union payload cast** — `fac4968` (feat)
3. **Task 3: ForecastRunStateMachine — sole mutator of forecast_runs.status** — `2b6cc00` (feat)
4. **Task 4: CardStatementQuery::nextSettlementForUser + Chains feature test** — `bc50f5f` (feat)

## Files Created/Modified

### Created (35)

**Migrations (5)**

- `Modules/Forecasting/Database/Migrations/2026_05_19_010001_create_forecast_scenarios_table.php`
- `Modules/Forecasting/Database/Migrations/2026_05_19_010002_create_forecast_scenario_mutations_table.php`
- `Modules/Forecasting/Database/Migrations/2026_05_19_010003_create_forecast_shortfall_windows_table.php`
- `Modules/Forecasting/Database/Migrations/2026_05_19_010004_create_forecast_runs_table.php`
- `Modules/Forecasting/Database/Migrations/2026_05_19_010005_add_forecast_columns_to_accounts.php`

**Models (4)**

- `Modules/Forecasting/Models/ForecastScenario.php`
- `Modules/Forecasting/Models/ForecastScenarioMutation.php`
- `Modules/Forecasting/Models/ForecastShortfallWindow.php`
- `Modules/Forecasting/Models/ForecastRun.php`

**Factories (4)**

- `Modules/Forecasting/Database/Factories/ForecastScenarioFactory.php`
- `Modules/Forecasting/Database/Factories/ForecastScenarioMutationFactory.php`
- `Modules/Forecasting/Database/Factories/ForecastShortfallWindowFactory.php`
- `Modules/Forecasting/Database/Factories/ForecastRunFactory.php`

**Public DTOs (8 + 6 union surface)**

- `Modules/Forecasting/Public/Dto/ForecastDto.php`
- `Modules/Forecasting/Public/Dto/ForecastPointDto.php`
- `Modules/Forecasting/Public/Dto/ScenarioDto.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationDto.php`
- `Modules/Forecasting/Public/Dto/ForecastHighlightsDto.php`
- `Modules/Forecasting/Public/Dto/ShortfallWindowDto.php`
- `Modules/Forecasting/Public/Dto/BalanceAnchorDto.php`
- `Modules/Forecasting/Public/Dto/SeriesConfidenceDto.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ScenarioMutationPayload.php` (abstract base)
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/CancelSeriesPayload.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddOneOffPayload.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddRecurringPayload.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ChangeSeriesAmountPayload.php`
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ShiftSeriesDatePayload.php`

**Internal (3)**

- `Modules/Forecasting/Internal/Casts/ScenarioMutationPayloadCast.php`
- `Modules/Forecasting/Internal/StateMachines/ForecastRunStateMachine.php`
- `Modules/Forecasting/Internal/Exceptions/InvalidForecastRunTransitionException.php`

**Tests (4)**

- `Modules/Forecasting/tests/Unit/MigrationsTest.php`
- `Modules/Forecasting/tests/Unit/ScenarioMutationPayloadCastTest.php`
- `Modules/Forecasting/tests/Unit/ForecastRunStateMachineTest.php`
- `Modules/Chains/tests/Feature/CardStatementQueryNextSettlementForUserTest.php`

**Chains Public surface (1)**

- `Modules/Chains/Public/Dto/NextSettlementDto.php`

### Modified (1)

- `Modules/Chains/Public/Services/CardStatementQuery.php` — extended with `nextSettlementForUser(User)`. The existing `openForAccount` method is preserved untouched; the new method adds funder resolution via raw query builder reads filtered on `user_id` before every join.

## Decisions Made

- **ScenarioMutationPayload union shape: abstract base + five concrete subclasses.** The alternative (a single generic Spatie LaravelData class with all kind-specific properties mixed in) would not let Larastan catch cross-kind property access. The abstract base only exposes `kind()`; subclasses each expose only the fields their kind requires. Verified by dropping a temp fixture that accesses `$payload->newAmountMinor` on the abstract base — Larastan level 10 strict reported two errors (`Access to undefined property` + the downstream `mixed → int` return-type mismatch).
- **Chains/Public/Dto/NextSettlementDto landed in Task 2 (not Task 4).** Task 2's `ForecastHighlightsDto` references the FQN `?\Modules\Chains\Public\Dto\NextSettlementDto $nextIcsSettlement`. If the DTO file did not yet exist, Larastan would fail at the Task 2 acceptance boundary. Creating the DTO file early (Rule 3 — Blocking, auto-fix) keeps each task's Larastan gate green without expanding the plan's overall surface. Task 4 added the populated service method + feature tests on top.
- **Custom cast's kind/payload validation runs at assignment, not save.** Eloquent's `setClassCastableAttribute` invokes `Cast::set()` at the moment `$model->payload = $value` is executed. The cast's `set()` therefore reads the row's `kind` from `$this->attributes` (which already holds the previously-assigned kind value). The "kind/payload mismatch" tests wrap the assignment in a closure (`$assign = fn () => $mutation->payload = ...; expect($assign)->toThrow(...)`) instead of expecting the throw on `save()`.
- **ForecastRunStateMachine exposes `transitionMap()` as a public static method instead of a private const.** The Pest snapshot-by-equality test (`expect(ForecastRunStateMachine::transitionMap())->toBe([...])`) needs to read the map from the outside; making it a static method instead of a private const keeps the assertion ergonomic without reaching for reflection.
- **`forecast_runs.user_id` is non-nullable (mirrors chain_resolution_runs)** while every other Wave 1 table keeps the FND-03 nullable carry-forward. The plan's interface guidance documented this asymmetry and the MigrationsTest explicitly locks it via a synthetic insert that expects `QueryException`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Created `Modules/Chains/Public/Dto/NextSettlementDto` in Task 2 instead of Task 4**

- **Found during:** Task 2 (Larastan check against `Modules/Forecasting/Public/Dto`)
- **Issue:** `ForecastHighlightsDto` declares a typed property `?\Modules\Chains\Public\Dto\NextSettlementDto $nextIcsSettlement`. Without the Chains DTO file existing, Larastan level max reports a missing-class error at the Task 2 acceptance boundary. Creating the populated service method + feature tests on top in Task 4 keeps the deferral; only the bare DTO file landed early.
- **Fix:** Created `Modules/Chains/Public/Dto/NextSettlementDto.php` (5-field final-readonly Spatie LaravelData record) as part of Task 2. Task 4 then added the `CardStatementQuery::nextSettlementForUser` service method + the feature test on top.
- **Files modified:** `Modules/Chains/Public/Dto/NextSettlementDto.php` (created in Task 2 commit `fac4968`).
- **Committed in:** `fac4968` (Task 2).

**2. [Rule 1 — Bug] Migration test for the payload non-string defensive branch rewritten to a non-array JSON value**

- **Found during:** Task 2 (running ScenarioMutationPayloadCastTest)
- **Issue:** The initial test for the "payload column is non-string at read time" branch tried to UPDATE the row's `payload` column to NULL, which fails the schema's NOT NULL constraint. The cast's defensive branch is unreachable via that path.
- **Fix:** Rewrote the test to UPDATE the column to a JSON-encoded scalar (`json_encode('not-an-object')`), which preserves the NOT NULL constraint but exercises the cast's `is_array($decoded)` defensive branch.
- **Files modified:** `Modules/Forecasting/tests/Unit/ScenarioMutationPayloadCastTest.php`.
- **Committed in:** `fac4968` (Task 2).

**3. [Rule 1 — Bug] Kind/payload mismatch test moved from `save()` to assignment**

- **Found during:** Task 2 (running ScenarioMutationPayloadCastTest)
- **Issue:** Eloquent's `setClassCastableAttribute` invokes `Cast::set()` at attribute-assignment time, not at `save()`. The original test wrapped `$mutation->save()` in the expect-throws closure, which fired earlier on the `$mutation->payload = ...` line.
- **Fix:** Wrapped the assignment in a closure and asserted the throw on that closure; added a second test for the "payload assigned before kind" defensive branch.
- **Files modified:** `Modules/Forecasting/tests/Unit/ScenarioMutationPayloadCastTest.php`.
- **Committed in:** `fac4968` (Task 2).

**4. [Rule 2 — Critical Functionality] Composer install on a fresh worktree**

- **Found during:** Pre-Task-1 environment check
- **Issue:** The worktree was a fresh checkout without a `vendor/` directory. None of the quality gates (Pest, Larastan, Pint) could run.
- **Fix:** Ran `composer install --no-interaction --prefer-dist`. The PSR-4 warnings for test fixture classes are pre-existing and not introduced by this plan.
- **Files modified:** `vendor/` (gitignored).
- **Committed in:** Not committed — runtime state only.

**5. [Rule 3 — Blocking] Created `database/database.sqlite` (gitignored)**

- **Found during:** Pre-Task-1 environment check (Phase 9 / Plan 10-01 SUMMARY noted the same fix)
- **Issue:** Fresh worktree had no SQLite file; `SqliteOptimizationsProvider` boot failed on `PRAGMA journal_mode = WAL` against a missing file.
- **Fix:** `touch database/database.sqlite`. Gitignored per `.gitignore`'s `/database/*.sqlite` rule.
- **Files modified:** `database/database.sqlite` (untracked).
- **Committed in:** Not committed — runtime state only.

---

**Total deviations:** 5 auto-fixed (1 missing-critical, 2 test-method bugs, 2 blocking environment fixes).
**Impact on plan:** Zero scope expansion. Deviation 1 is the cleanest possible decoupling — the bare DTO file moves from Task 4 to Task 2 to keep Larastan green at each task boundary. Deviations 2-3 corrected tests that asserted the throw at the wrong call site. Deviations 4-5 mirror the Plan 10-01 environment baseline.

## Issues Encountered

- **Global stash leak (#3542) almost contaminated the worktree.** Early in Task 2 I attempted `git stash` to temporarily verify a baseline behaviour. `git stash` reported "No local changes to save" — but a subsequent `git stash pop` (which I issued reflexively) pulled WIP from a sibling worktree's prior session (`gsd-reviewfix/07-iter1: parallel-agent-categorization-changes`), producing merge conflicts in two Categorization files unrelated to Plan 10-02. Recovered immediately by running `git checkout HEAD -- Modules/Categorization/...` on the three affected files; no commits were tainted. The lesson is reinforced: **never use `git stash` inside a Claude Code worktree** — the stash list is shared globally across `.git/worktrees/`. From the moment of recovery onward I used commit-to-throwaway-branch for any "set aside" need. The prior agents' stash entries remain on `stash@{0..4}` and were not touched.
- **Pest "WARN" status is not a failure.** Pest reports tests as "warnings" rather than "passing" in default output when no risky-test designation is in place. All 51 Forecasting tests + 9 Chains feature tests + 33 arch invariants exit 0 with the warning indicator — same convention noted in the Plan 10-01 SUMMARY.

## User Setup Required

None — Wave 1 lands schema + read/write types + state machine + cross-module Chains DTO extension; no new packages, no environment variables, no external services.

## Next Phase Readiness

- **Wave 2 (Plan 10-03)** can now land `BalanceAnchorResolver`, `RangeProjector` (envelope tier), `ProjectForecastJob`, and the `/forecast` page skeleton on top of:
  - The four Eloquent models + factories (write target + fixture source).
  - The eight Public DTOs (read API payload shape).
  - The `ScenarioMutationPayloadCast` (the projector reads typed payloads).
  - The `ForecastRunStateMachine` (the projector wraps every run in `start()` / `complete()` / `fail()`).
- **Wave 3 (Plan 10-04)** can consume `Modules\Chains\Public\Services\CardStatementQuery::nextSettlementForUser(User)` for the chain-aware forecasting router + `ForecastHighlightsQuery`. The DTO's `accountId` field is the funder ASN account id Wave 3 needs (NOT the ICS card account id). Phase 5's `ThisPeriodAtAGlanceQuery::nextIcsSettlement` + `CardStatementForecastTile` are preserved untouched so the legacy dashboard tile path stays functional during the overlap period.
- **All five Wave 0 arch invariants** (`crossModuleAccessGoesThroughPublic`, `noSynchronousForecastingInRequestLifecycle`, `noTransactionWritesFromForecasting`, `noScenarioMutationsJoinedToTransactionQueries`, and the existing Phase 5-9 invariants) stay green after Wave 1. The new substrate writes go to forecast_* tables only — no Phase 1-9 substrate is mutated from Forecasting code.

## Self-Check: PASSED

- `Modules/Forecasting/Database/Migrations/*.php`: 5 files FOUND
- `Modules/Forecasting/Models/{ForecastScenario,ForecastScenarioMutation,ForecastShortfallWindow,ForecastRun}.php`: FOUND
- `Modules/Forecasting/Database/Factories/Forecast*Factory.php`: 4 files FOUND
- `Modules/Forecasting/Public/Dto/{ForecastDto,ForecastPointDto,ScenarioDto,ScenarioMutationDto,ForecastHighlightsDto,ShortfallWindowDto,BalanceAnchorDto,SeriesConfidenceDto}.php`: FOUND
- `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/*.php`: 6 files FOUND (abstract base + five subclasses)
- `Modules/Forecasting/Internal/Casts/ScenarioMutationPayloadCast.php`: FOUND
- `Modules/Forecasting/Internal/StateMachines/ForecastRunStateMachine.php`: FOUND
- `Modules/Forecasting/Internal/Exceptions/InvalidForecastRunTransitionException.php`: FOUND
- `Modules/Forecasting/tests/Unit/{MigrationsTest,ScenarioMutationPayloadCastTest,ForecastRunStateMachineTest}.php`: FOUND
- `Modules/Chains/Public/Dto/NextSettlementDto.php`: FOUND
- `Modules/Chains/Public/Services/CardStatementQuery.php`: contains `public function nextSettlementForUser` (verified by grep)
- `Modules/Chains/tests/Feature/CardStatementQueryNextSettlementForUserTest.php`: FOUND
- Commit `6898053` (Task 1): FOUND
- Commit `fac4968` (Task 2): FOUND
- Commit `2b6cc00` (Task 3): FOUND
- Commit `bc50f5f` (Task 4): FOUND
- `vendor/bin/pest --testsuite=Forecasting`: exit 0, 51 warnings, 1199 assertions
- `vendor/bin/pest Modules/Chains/tests/Feature/CardStatementQueryNextSettlementForUserTest.php`: exit 0, 9 warnings, 20 assertions
- `vendor/bin/pest tests/Contracts/BoundaryArchTest.php`: exit 0, 33 warnings, 65 assertions
- `vendor/bin/phpstan analyse Modules/Forecasting Modules/Chains --memory-limit=2G`: OK No errors
- `vendor/bin/pint --test Modules/Forecasting/ Modules/Chains/Public/Services/CardStatementQuery.php Modules/Chains/Public/Dto/NextSettlementDto.php Modules/Chains/tests/Feature/CardStatementQueryNextSettlementForUserTest.php`: passed
- Cross-kind property access on the abstract `ScenarioMutationPayload` base type: caught by Larastan level max with `Access to undefined property` error (verified via temp fixture, then cleaned up)

---
*Phase: 10-cash-flow-forecasting-what-if-scenarios*
*Completed: 2026-05-18*
