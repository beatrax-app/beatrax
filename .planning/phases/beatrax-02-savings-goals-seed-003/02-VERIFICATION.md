---
phase: 02-savings-goals-seed-003
verified: 2026-06-08T00:00:00Z
status: passed
human_verification_resolved: 2026-06-08T00:00:00Z
human_verification_resolution: "Both items resolved during the execute-phase UAT (see 02-HUMAN-UAT.md, status passed). Item 1 (FX direction/rounding): verified by code inspection + a new inverse-direction test (USD contribution -> EUR goal, commit eb2f569); no-rate path is the documented app-wide D-07 passthrough. Item 2 (young-goal projection): a MIN_OBSERVATION_DAYS=7 guard now suppresses the projected date until enough history accrues (commit a9c3f50), with a distinct 'building a projection' card copy and a new test."
score: 12/12 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Confirm FX conversion direction and rounding for cross-currency goals"
    expected: "A goal created when base_currency=EUR (target_currency=EUR), then contributions arrive in USD — those USD contributions should be converted to EUR (the goal's target_currency) and compared against the EUR target. The fractionComplete, progressState 'reached' flip, and rendered amount should all be in EUR. Verify with a real or seeded USD transaction against an EUR goal."
    why_human: "The CR-02 fix changed convertToBase($money, $user->base_currency) to convertToBase($money, $goal->target_currency). The ExchangeRateService::convertToBase signature accepts any target currency — the naming is misleading but the call is semantically correct. The test covers EUR contribution against a USD goal but not the reverse (USD contribution against EUR goal, which is the common real case). The rate-lookup direction, rounding mode (truncation vs half-even), and the 'no rate available' passthrough path for real cross-currency goals require eyes-on validation against the exchange_rates data."
  - test: "Confirm projected-finish-date arithmetic for young goals vs mature goals"
    expected: "A goal created 5 days ago with EUR 500 in contributions should project a daily rate of ~100 EUR/day (500/5), yielding a much shorter projected finish date than the old 500/90=~5.5 EUR/day rate. A goal older than 90 days should use the 90-day window and divide by ~90. Both should produce dates that feel sensible relative to the contribution pace."
    why_human: "The WR-06 fix replaced the constant TRAILING_WINDOW_DAYS=90 divisor with max(1, diffInDays(effectiveStart, today)). For very new goals this makes the projection aggressively short (a goal created today with one large deposit projects an unrealistically imminent finish). The fix is mathematically correct but the product behaviour — show an overly optimistic projection for a 1-5 day old goal — requires human judgment on whether it matches the intended UX."
---

# Phase 2: Savings Goals (SEED-003) Verification Report

**Phase Goal:** User can set savings goals and watch real cash-flow drive measurable, forecast-backed progress toward them.
**Verified:** 2026-06-08T00:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `goals` table exists with nullable user_id, nullable account_id, BIGINT target_minor, target_currency, start_date, target_date, status | VERIFIED | Migration `2026_06_08_000001_create_goals_table.php` confirmed: `user_id` nullable FK, `account_id` nullable FK with nullOnDelete, `bigInteger('target_minor')`, `string('target_currency',3)`, `date('start_date')`, `date('target_date')`, `string('status',16)` |
| 2 | Goals module provider is registered in bootstrap/providers.php and boots after Core | VERIFIED | `bootstrap/providers.php` line 48 has `GoalsServiceProvider::class`; `module.json` priority 9 > Core priority 0 |
| 3 | For a linked goal, contributed = sum of credits (type IN transfer_in, income) on the linked account posted >= start_date, converted to goal's target_currency | VERIFIED | `GoalProgressQuery::sumContributions()` lines 185-199: raw query with `whereIn('type', ['transfer_in','income'])`, `where('posted_at','>=', $goal->start_date)`, each row converted via `$this->fx->convertToBase($money, $goal->target_currency)`; GoalProgressQueryTest 9 assertions green |
| 4 | An unlinked goal reports 0 contributed / no projected finish date | VERIFIED | `sumContributions()` returns 0 early when `$goal->account_id === null`; `GoalProjectionService::project()` guard 1 returns `['date'=>null,'beyondHorizon'=>false]`; test "it returns 0 contributed and no projection for an unlinked goal" passes |
| 5 | Multiple goals may link the same account; each counts credits independently since its own start_date | VERIFIED | Contribution query is per-goal (`where('account_id', $goal->account_id)`, `where('posted_at', '>=', $goal->start_date)`) — each goal's window is independent; matches D-02 |
| 6 | Progress reports contributed/target + fractionComplete + progressState of in_progress/reached/overdue | VERIFIED | `GoalProgressQuery::loadRows()` lines 113-120: `fractionComplete = contributedMinor / targetMinor`, `progressState` via `match(true)` on reached/overdue/in_progress; `GoalProgressRow` DTO has all fields; 3 state-bucket tests pass |
| 7 | Projected finish date is a contribution run-rate over a trailing window, validated against ForecastQuery, extrapolated beyond 90d with beyondHorizon flag | VERIFIED | `GoalProjectionService::project()` implements TRAILING_WINDOW_DAYS=90, HORIZON_LIMIT_DAYS=90 constants; calls `ForecastQuery::forUser()` with horizon in {30,60,90} only; returns beyondHorizon=true when daysToFinish > 90; GoalProjectionTest 6 tests all pass |
| 8 | No-history goals show no date; reached goals show null date (UI shows sentinel); already-reached guard before run-rate | VERIFIED | Guard 2 in `GoalProjectionService` returns null date for reached; Guard: `dailyRateMinor <= 0` returns null date; GoalProjectionTest covers both cases |
| 9 | Cross-user transactions never count toward a goal's contributions | VERIFIED | Every raw read carries `->where('user_id', $user->id)` in both `GoalProgressQuery` (line 186) and `GoalProjectionService` (line 82); GoalProgressQueryTest "it excludes another users transactions" passes; GoalsPageTest cross-user tests pass |
| 10 | A goal can be created with name, target amount (parsed to base-currency minor), target date, and optional linked account | VERIFIED | `GoalWriter::save()` parses via `parseAmount()`, validates accountId via `assertOwnedAccountOrNull()`, creates with `withoutGlobalScope(UserScope::class)`, stores `target_currency = $user->base_currency`; GoalsPageTest create flow passes with assertDatabaseHas |
| 11 | Goal lifecycle: edit/markComplete/archive/restore all work; archive is reversible; status set only by dedicated Writer methods | VERIFIED | `GoalWriter` has `update()`, `markComplete()`, `archive()`, `restore()`; each resolves via `findOwnedGoal()` (explicit user scoping via `withoutGlobalScope + where('user_id', $user->id)`); status never accepted from caller args; GoalsPageTest lifecycle tests all pass (31 total, 61 assertions) |
| 12 | /goals page lists cards with 3-state progress bar + projected date; Flux create/edit modal; archive micro-confirm + Restore toast; dashboard summary card after NetWorthCard; sidebar nav link | VERIFIED | `goals-page.blade.php` has `role="progressbar"`, `flux:modal name="goal-form"`, `font-mono` tabular numerics, exact copy strings; `dashboard.blade.php` has `@livewire('goals.summary-card')` at line 78 (after `core.net-worth-card`); `app-sidebar.blade.php` has `route('goals.index')` at line 136; Human UAT passed (2026-06-08) for all 6 steps |

**Score:** 12/12 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `Modules/Goals/Database/Migrations/2026_06_08_000001_create_goals_table.php` | goals table schema with BIGINT target_minor, nullable user_id | VERIFIED | Contains `bigInteger('target_minor')`, `nullable()->constrained('users')`, `nullOnDelete` for account FK, no `->float(` |
| `Modules/Goals/Models/Goal.php` | Goal model with BelongsToUser scope + Account relation | VERIFIED | `use BelongsToUser`, `account(): BelongsTo<Account, $this>`, full `$fillable`, `casts()` with immutable_date |
| `Modules/Goals/Public/Dto/GoalProgressRow.php` | Immutable read-model DTO for goal progress + projection | VERIFIED | `final class GoalProgressRow extends Data`, all required fields including `targetDate` (added WR-01 fix), `projectionBeyondHorizon` |
| `Modules/Goals/Public/Services/GoalProgressQuery.php` | forUser() + archivedForUser() with FX-correct contribution sum | VERIFIED | Contains `whereIn('type', ['transfer_in', 'income'])`, `convertToBase(`, `function archivedForUser(`, no `->float(` |
| `Modules/Goals/Public/Services/GoalProjectionService.php` | Run-rate + forecast-validated projected finish date | VERIFIED | Contains `TRAILING_WINDOW_DAYS`, `HORIZON_LIMIT_DAYS`, calls `ForecastQuery` only with horizons in {30,60,90}, never reads `pointMinor` as goal contribution |
| `Modules/Goals/Public/Services/GoalWriter.php` | Single write path: save/update + parseAmount + markComplete/archive/restore | VERIFIED | Contains `parseAmount`, `withoutGlobalScope(UserScope::class)`, `findOwnedGoal()` with explicit user_id guard, `markComplete`/`archive`/`restore`; no `GoalProgressQuery` constructor dependency |
| `Modules/Goals/Internal/Http/Livewire/GoalsPage.php` | /goals page component, method-param DI, full lifecycle | VERIFIED | `extends('layouts.app'` present, no `__construct`, `GoalWriter` and `GoalProgressQuery` as method params, calls `archivedForUser(` |
| `Modules/Goals/Internal/Http/Livewire/GoalsSummaryCard.php` | Dashboard summary card, inline (no layout extend) | VERIFIED | 59 lines (substantive), render() does NOT call `->extends(`, sorts by projectedFinishDate null-last, returns top 3 |
| `Modules/Goals/Resources/views/livewire/goals-page.blade.php` | Goal cards + Flux create/edit modal + archived disclosure | VERIFIED | Contains `role="progressbar"`, `flux:modal`, `font-mono` tabular numerics, "Add contributions to see a projection", "Track progress toward your savings targets." |
| `Modules/Goals/Resources/views/livewire/goals-summary-card.blade.php` | Compact dashboard goals card | VERIFIED | Contains `See all →` link to `route('goals.index')` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `bootstrap/providers.php` | `GoalsServiceProvider` | manual provider registration array | WIRED | Line 48: `GoalsServiceProvider::class,` |
| `GoalProgressQuery.php` | transactions table | raw DatabaseManager with `whereIn('type',...)` | WIRED | Lines 185-190: user_id + account_id + type + posted_at guards confirmed |
| `GoalProgressQuery.php` | `ExchangeRateService::convertToBase` | called in `sumContributions()` | WIRED | Line 195: `$this->fx->convertToBase($money, $goal->target_currency)` |
| `GoalProjectionService.php` | `ForecastQuery::forUser()` | horizon in {30,60,90} | WIRED | Line 130: `$this->forecast->forUser($accountId, $horizon, null, $user)` |
| `GoalsPage.php` | `GoalProgressQuery + GoalWriter` | method-param DI on render/actions | WIRED | Methods `createGoal(CurrentUser, GoalWriter)`, `render(CurrentUser, GoalProgressQuery, ...)` confirmed |
| `dashboard.blade.php` | `goals.summary-card` | `@livewire()` after net-worth-card | WIRED | Line 78: `@livewire('goals.summary-card')` — insertion after core.net-worth-card at line 74 confirmed |
| `app-sidebar.blade.php` | `route('goals.index')` | sidebar nav link | WIRED | Line 136: `<a href="{{ route('goals.index') }}"` |
| `GoalsServiceProvider.php` | `GoalsPage` + `GoalsSummaryCard` | `$livewire->component()` in boot() | WIRED | Lines 44-45: both Livewire components registered |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|--------|
| `goals-page.blade.php` | `$rows` (list of GoalProgressRow) | `GoalProgressQuery::forUser()` → raw DB query over `goals` + `transactions` tables | DB query in `loadRows()` joins goals + accounts, contribution sum queries transactions | FLOWING |
| `goals-page.blade.php` | `$archived` (archived rows) | `GoalProgressQuery::archivedForUser()` → same `loadRows()` machinery | Same DB path, status='archived' filter | FLOWING |
| `goals-summary-card.blade.php` | `$goals` (top 3 rows) | `GoalProgressQuery::forUser()` in `GoalsSummaryCard::render()` | Same real DB query path | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Goals module test suite: all 31 tests | `docker compose run --rm php ./vendor/bin/pest Modules/Goals` | 31 passed, 61 assertions | PASS |
| Larastan level 10 on Goals module | `docker compose run --rm php ./vendor/bin/phpstan analyse Modules/Goals --memory-limit=512M` | No errors | PASS |
| Pint style check on Goals module | `docker compose run --rm php ./vendor/bin/pint --test Modules/Goals` | PASS, 19 files, 0 violations | PASS |
| Arch suite (Boundary, MoneyColumns, NoFloatMoney, ModulePriorities, UserIdColumn, noAuthFacadeOrHelper) | `docker compose run --rm php ./vendor/bin/pest --filter=Arch` | 64 passed, 224 assertions | PASS |

### Probe Execution

No phase-declared probes. Behavioral spot-checks above serve as the runnable verification layer.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| GOAL-01 | Plans 01, 03, 04 | User can create a savings goal with a name, target amount, and target date | SATISFIED | `GoalWriter::save()` + Flux modal in `goals-page.blade.php`; GoalsPageTest create flow + Dutch-format parse pass |
| GOAL-02 | Plans 01, 02, 03 | User can link a goal to a savings account so contributions are tracked | SATISFIED | `GoalWriter::assertOwnedAccountOrNull()` validates account ownership; `GoalProgressQuery::sumContributions()` aggregates credits since start_date; GoalProgressQueryTest contribution sum test passes |
| GOAL-03 | Plans 01, 02, 04 | System shows progress toward each goal (contributed vs target, % complete) | SATISFIED | `GoalProgressRow` carries `contributedMinor`, `targetMinor`, `fractionComplete`, `progressState`; `goals-page.blade.php` renders progress bar + mono tabular contributed/target; GoalProgressQueryTest 3-state bucket tests pass |
| GOAL-04 | Plans 01, 02, 04 | System projects a realistic finish date from actual cash-flow via the Forecasting engine | SATISFIED | `GoalProjectionService` implements run-rate + ForecastQuery validation + beyondHorizon flag; projected-date copy in blade with 4 states; GoalProjectionTest 6 assertions pass. See human verification item for projection arithmetic semantics. |
| GOAL-05 | Plans 01, 03, 04 | User can edit, complete, or archive a goal | SATISFIED | `GoalWriter` has `update()`, `markComplete()`, `archive()`, `restore()`; GoalsPage exposes `openEdit()`/`updateGoal()`, `markComplete()`, `confirmArchive()`/`archive()`, `restore()`; archive micro-confirm + Restore toast present; GoalsPageTest lifecycle tests pass |

No orphaned requirements: REQUIREMENTS.md shows GOAL-01..05 all marked `[x]` (complete) and all are claimed by Plans 01-04.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `GoalProgressQuery.php` | 162 | Fallback value in `hydrateGoal` target_date: `CarbonImmutable::today()->addYear()` when target_date is null/unparseable | INFO | Defensive fallback; target_date is NOT NULL in the migration so this branch is unreachable in practice. Not a stub. |

No `TBD`, `FIXME`, or `XXX` markers found across modified files. No `return null`/`return []`/`return {}` stubs found in the rendering path. No hardcoded empty props passed to rendering components.

The D-05 context decision says "Target amount is stored and tracked in User->base_currency" and contributions "convert to base". The CR-02 fix changed the contribution conversion to `goal->target_currency` rather than `user->base_currency`. This is a **deliberate correctness fix**, not a deviation that contradicts intent: goal creation always sets `target_currency = user->base_currency` (GoalWriter line 82), so the two values are identical at creation time and only diverge if the user changes base_currency later. Converting to `target_currency` is more correct (numerator and denominator share units), and the code comment at GoalProgressQuery line 175 documents why. The FX conversion direction is flagged for human verification below.

### Human Verification Required

### 1. FX Conversion Direction and Rounding for Cross-Currency Goals

**Test:** Create a goal when `base_currency = EUR` (so `target_currency = EUR`). On the linked account, have a USD transaction (e.g. a USD income/transfer_in). Verify that the USD amount is correctly converted to EUR using the `exchange_rates` table, and that `contributedMinor` (displayed on the card) is in EUR, the `fractionComplete` ratio is computed in EUR/EUR, and the "reached" threshold compares EUR figures.

**Expected:** The USD contribution appears as its EUR equivalent. The progress bar advances correctly. The currency label on the goal card shows EUR (the goal's `target_currency`). No currency mismatch in the rendered figure.

**Why human:** The `ExchangeRateService::convertToBase` parameter is named `$targetCurrency` generically; calling it with `$goal->target_currency` is semantically correct. However, the rate-lookup direction (does the service look up USD→EUR or EUR→USD?), the rounding mode (truncation vs half-even), and the "no rate available" passthrough behaviour for real cross-currency contributions require eyes-on testing with real or seeded exchange rate data. The added test in GoalProgressQueryTest covers EUR contribution against a USD goal (with a seeded EUR→USD rate of 1.1), but the typical real case (USD contribution against EUR goal) is exercised only implicitly.

### 2. Projected Finish Date Arithmetic for Young Goals

**Test:** Create a goal with a EUR 1,000 target. Add a EUR 500 transfer_in today (goal age: 1 day). Observe the projected finish date. Then create another goal, add EUR 500/month for 3 months (goal age: ~90 days), and observe its projected finish date. Both should yield sensible, proportionate finish dates.

**Expected:** The 1-day-old goal (500/1 = 500 EUR/day rate) should show a projected finish date of roughly 1-2 days out (remaining ~500 EUR at 500 EUR/day). The 90-day-old goal (1,500 total / 90 days = ~16.7 EUR/day) should show a longer projection appropriate to its pace. Neither should feel absurdly optimistic or pessimistic.

**Why human:** The WR-06 fix replaced a fixed 90-day denominator with `max(1, diffInDays(effectiveStart, today))`. For a goal created today with a single large deposit, the divisor is 1 — making the daily rate equal to the total deposit, which produces an extremely short (and arguably misleading) projected finish. This is mathematically correct but may surprise users who expect a more conservative forward projection early in a goal's life. Whether this product behaviour is acceptable requires a judgment call from the developer.

---

## Gaps Summary

No technical gaps found. All 12 must-haves are verified in code. All GOAL-01..05 requirements are satisfied by substantive, wired, data-flowing implementations. The 31-test suite is fully green (61 assertions), Larastan level 10 clean, Pint clean, and all 64 arch tests pass.

Two items require human sign-off before this phase can be considered production-ready: the FX conversion direction/rounding semantics for cross-currency contributions (CR-02 fix), and the projected-finish-date arithmetic for recently-created goals (WR-06 fix). Both were flagged explicitly in the code-review fix report as "requires human verification" and represent correctness-sensitive behaviours that automated tests cover only partially.

---

_Verified: 2026-06-08T00:00:00Z_
_Verifier: Claude (gsd-verifier)_
