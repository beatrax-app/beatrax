---
phase: 10-cash-flow-forecasting-what-if-scenarios
reviewed: 2026-05-18T00:00:00Z
depth: standard
files_reviewed: 102
files_reviewed_list:
  - Modules/Chains/Public/Dto/NextSettlementDto.php
  - Modules/Chains/Public/Dto/SeriesFunderLink.php
  - Modules/Chains/Public/Services/CardStatementQuery.php
  - Modules/Chains/Public/Services/ChainLinkQuery.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Internal/Http/Livewire/SettingsPage.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php
  - Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php
  - Modules/Forecasting/Database/Factories/ForecastRunFactory.php
  - Modules/Forecasting/Database/Factories/ForecastScenarioFactory.php
  - Modules/Forecasting/Database/Factories/ForecastScenarioMutationFactory.php
  - Modules/Forecasting/Database/Factories/ForecastShortfallWindowFactory.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010001_create_forecast_scenarios_table.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010002_create_forecast_scenario_mutations_table.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010003_create_forecast_shortfall_windows_table.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010004_create_forecast_runs_table.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010005_add_forecast_columns_to_accounts.php
  - Modules/Forecasting/Database/Migrations/2026_05_19_010006_add_result_json_to_forecast_runs.php
  - Modules/Forecasting/Internal/Casts/ScenarioMutationPayloadCast.php
  - Modules/Forecasting/Internal/Exceptions/InvalidForecastRunTransitionException.php
  - Modules/Forecasting/Internal/Http/Livewire/AccountBufferEditor.php
  - Modules/Forecasting/Internal/Http/Livewire/ForecastHighlightsTile.php
  - Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php
  - Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php
  - Modules/Forecasting/Internal/Http/Livewire/OpeningBalanceEditor.php
  - Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php
  - Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php
  - Modules/Forecasting/Internal/Listeners/ProjectForecastOnDriftDismissed.php
  - Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php
  - Modules/Forecasting/Internal/Listeners/ProjectForecastOnScenarioChange.php
  - Modules/Forecasting/Internal/Mapping/ForecastDtoMapper.php
  - Modules/Forecasting/Internal/Pipeline/BalanceAnchorResolver.php
  - Modules/Forecasting/Internal/Pipeline/CadenceJitter.php
  - Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php
  - Modules/Forecasting/Internal/Pipeline/DailyFold.php
  - Modules/Forecasting/Internal/Pipeline/ForecastContribution.php
  - Modules/Forecasting/Internal/Pipeline/Percentile.php
  - Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php
  - Modules/Forecasting/Internal/Pipeline/RangeProjector.php
  - Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php
  - Modules/Forecasting/Internal/Pipeline/ShortfallDetector.php
  - Modules/Forecasting/Internal/StateMachines/ForecastRunStateMachine.php
  - Modules/Forecasting/Models/ForecastRun.php
  - Modules/Forecasting/Models/ForecastScenario.php
  - Modules/Forecasting/Models/ForecastScenarioMutation.php
  - Modules/Forecasting/Models/ForecastShortfallWindow.php
  - Modules/Forecasting/Providers/ForecastingServiceProvider.php
  - Modules/Forecasting/Public/Actions/AddScenarioMutation.php
  - Modules/Forecasting/Public/Actions/CreateAmountChangeScenarioForSeries.php
  - Modules/Forecasting/Public/Actions/CreateCancellationScenarioForAlert.php
  - Modules/Forecasting/Public/Actions/CreateCancellationScenarioForSeries.php
  - Modules/Forecasting/Public/Actions/CreateScenario.php
  - Modules/Forecasting/Public/Actions/DeleteScenario.php
  - Modules/Forecasting/Public/Actions/EditScenarioMutation.php
  - Modules/Forecasting/Public/Actions/RemoveScenarioMutation.php
  - Modules/Forecasting/Public/Actions/RenameScenario.php
  - Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php
  - Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php
  - Modules/Forecasting/Public/Dto/BalanceAnchorDto.php
  - Modules/Forecasting/Public/Dto/ForecastDto.php
  - Modules/Forecasting/Public/Dto/ForecastHighlightsDto.php
  - Modules/Forecasting/Public/Dto/ForecastPointDto.php
  - Modules/Forecasting/Public/Dto/ScenarioDto.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationDto.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddOneOffPayload.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddRecurringPayload.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/CancelSeriesPayload.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ChangeSeriesAmountPayload.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ScenarioMutationPayload.php
  - Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ShiftSeriesDatePayload.php
  - Modules/Forecasting/Public/Dto/SeriesConfidenceDto.php
  - Modules/Forecasting/Public/Dto/ShortfallWindowDto.php
  - Modules/Forecasting/Public/Events/ForecastShortfallDetected.php
  - Modules/Forecasting/Public/Events/ScenarioCreated.php
  - Modules/Forecasting/Public/Events/ScenarioDeleted.php
  - Modules/Forecasting/Public/Events/ScenarioMutated.php
  - Modules/Forecasting/Public/Exceptions/OpeningBalanceDivergenceWarning.php
  - Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php
  - Modules/Forecasting/Public/Services/ForecastQuery.php
  - Modules/Forecasting/Public/Services/ScenarioQuery.php
  - Modules/Forecasting/Resources/views/livewire/account-buffer-editor.blade.php
  - Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php
  - Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php
  - Modules/Forecasting/Resources/views/livewire/model-what-if-dropdown.blade.php
  - Modules/Forecasting/Resources/views/livewire/opening-balance-editor.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/net-diff-tile.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/scenario-mutation-form.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/series-confidence-row.blade.php
  - Modules/Forecasting/Resources/views/livewire/scenario-editor-sidebar.blade.php
  - Modules/Forecasting/Routes/web.php
  - Modules/Forecasting/composer.json
  - Modules/Recurring/Internal/Mapping/RecurringSeriesDtoMapper.php
  - Modules/Recurring/Public/Dto/RecurringSeriesDto.php
  - Modules/Recurring/Public/Services/RecurringSeriesQuery.php
  - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
  - bootstrap/providers.php
  - composer.json
  - phpstan.neon
  - phpunit.xml
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
findings:
  critical: 3
  warning: 12
  info: 10
  total: 25
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-05-18
**Depth:** standard
**Files Reviewed:** 102 (86 non-test runtime PHP + 16 Blade views)
**Status:** issues_found

## Summary

Phase 10 delivers a structurally sound cash-flow forecasting module with strong adherence to the DI-only invariant, modular Public/Internal split, and the cross-module-public-only rule. The Pest suite passes (489 tests, 3252 assertions); Larastan level 10 strict passes on `Modules/Forecasting`; Pint passes. The five `BoundaryArchTest` invariants are wired and binding.

That said, the adversarial review surfaced **three real correctness blockers** that the existing test suite cannot catch because the unit tests substitute the wrong sentinel value for the production code path:

1. **`BalanceAnchorResolver` ASN branch is dead in production** — checks `$kind === 'asn_bank'` but the production `AccountNamer` creates rows with `kind = 'asn'`. All ASN accounts will silently fall through to the user-input opening balance or transactions-sum fallback. This breaks FCT-01's primary anchor source for the user's main account.
2. **`ScenarioEditorSidebar::parseAmountMinor` strips dots indiscriminately** — a US-style decimal "12.50" becomes 125000 minor units (€1,250 instead of €12.50). Same bug in `ModelWhatIfDropdown::parseAmountToMinor`. Even on a Dutch locale UI, pasting an Excel-default "12.50" will silently 100× the entered amount in any what-if scenario.
3. **`ChainAwareForecastRouter` synthesised-settlement dedup loop drops unrelated contributions** — any recurring series contribution whose `(funderAccountId, dueDate)` tuple collides with the synthesised ICS settlement is unconditionally dropped, even when the recurring series has nothing to do with the settlement. The deduplication should be scoped to the (synthesised → routed contribution) overlap, not all routed contributions on that date.

Twelve additional WARNING-tier findings cover schema drift (three nullable `user_id` columns where the chain_resolution_runs precedent says non-nullable), the `forecast-highlights-tile` losing the sign of an overdraft balance, missing input validation on `direction` / `cadence` / `scope` enum strings inside the Public DTOs (silent fall-through to "expense" / empty list), the `ForecastPage` rendering an empty page on an invalid `account` URL parameter instead of 404-ing or defaulting to "all", and the project's GSD-agnostic invariant being violated in 11 PHPDoc / Blade comments in Phase 10 runtime files.

Ten INFO-tier findings cover dead code (unused `Clock` DI in `ScenarioApplier`, dead `?? $now` fallback in `ScenarioQuery`), magic numbers (€500 divergence threshold), and minor style issues. All findings are auto-applicable by the `--all` flag.

## Critical Issues

### CR-01: `BalanceAnchorResolver` ASN branch never fires in production (dead code on the critical path)

**File:** `Modules/Forecasting/Internal/Pipeline/BalanceAnchorResolver.php:60`

**Issue:**
The resolver routes on `accounts.kind` and checks `$kind === 'asn_bank'`, but production code creates ASN accounts with `kind = 'asn'`. Empirically (grep over `Modules/`):

- `Modules/Import/Public/Services/AccountNamer.php:86` — `'kind' => 'asn'` (the ONLY production write to ASN account.kind)
- `Modules/Ledger/tests/**/*.php` — every Phase 1/2/3/4 test seeds with `'kind' => 'asn'`
- `Modules/Chains/Public/Services/CardStatementQuery.php:124` — `->where('kind', 'asn')` (correct, matches production)

The Forecasting unit tests use `'asn_bank'` because the resolver was coded against that string and the test author seeded what the code expects. So the unit tests pass green, but a real user's ASN account will NEVER hit `fromStatementSummaries`. The projection silently falls through to `fromUserInputOpeningBalance` (which is unset for fresh imports) and ultimately `fromTransactionsSum` with `asOfDate=1970-01-01`. This breaks FCT-01 — the verification report's claimed "ASN: latest CAMT.053 closing_balance" anchor source never resolves.

**Fix:**
Change the resolver to match production. Either:

```php
// Modules/Forecasting/Internal/Pipeline/BalanceAnchorResolver.php
if ($kind === 'asn') {
    $anchor = $this->fromStatementSummaries($accountId, $user->id);
    if ($anchor !== null) {
        return $anchor;
    }
}
```

…and update the corresponding fixtures + Pest tests in `Modules/Forecasting/tests/Unit/BalanceAnchorResolverTest.php` (lines 135, 149, 213) plus the Feature tests at `ForecastPageTest.php:39`, `ModelCancelLaunchpadTest.php:68`, `AllAccountsAggregateTabTest.php:41`, `OpeningBalanceEditorTest.php:251-256`, `ForecastCrossUser404Test.php:38`, `ConfidenceLegendTest.php:45` (all seed `'asn_bank'`) so they actually exercise the production code path. Note that `OpeningBalanceEditor.blade.php:14`'s docstring is also wrong (`'asn_bank'`) and the editor's `str_contains($accountKind, 'asn')` happens to work by accident.

Also audit the rest of the codebase: `Modules/DriftAlerts/tests/**` and `Modules/Recurring/tests/**` also seed `'asn_bank'`, suggesting this drift started earlier. Phase 10 inherited it but is the first place it's load-bearing on the production path.

---

### CR-02: Float-decimal money parsing silently 100× any dot-decimal input

**File:** `Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php:444-461`
**File:** `Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php:125-142`

**Issue:**
Both `parseAmountMinor` / `parseAmountToMinor` strip every dot before converting:

```php
$normalised = str_replace([' ', '.'], ['', ''], $raw);
$normalised = str_replace(',', '.', $normalised);
```

For a Dutch-locale input `"12,50"` this works (`"12,50"` → `"12,50"` → `"12.50"` → 1250 minor units = €12.50, correct).

For a US-locale input `"12.50"` this is broken: `"12.50"` → `"1250"` → `"1250"` (no comma to replace) → `(int) round(1250.0 * 100) = 125000` minor units = **€1,250.00**. The user's what-if scenario gets a 100× too-large mutation amount.

The same bug exists in `ModelWhatIfDropdown::parseAmountToMinor` (line 131) which redirects to `/forecast?scenarioId={id}` after creating a 100×-too-large scenario.

This breaks FCT-03 / FCT-04 for any user who pastes a value from a US-locale source (Excel default, copy from a USD-denominated subscription, copy from a US bank statement, etc.). It's also a CLAUDE.md PROJECT.md "What NOT to use" violation: "Plain floats / cents-as-int for money — Floating-point silently corrupts FX conversions; … brick/money".

`AccountBufferEditor::parseInputToMinor` (lines 119-153) and `OpeningBalanceEditor::parseInputToMinor` (lines 206-240) DO handle this correctly via a `strrpos`-based last-separator-wins heuristic. The fix is to port that pattern into the two broken parsers.

**Fix:**

```php
// Replace ScenarioEditorSidebar::parseAmountMinor() with the
// "last-separator-wins" heuristic from AccountBufferEditor:

private function parseAmountMinor(string $key): int
{
    $value = $this->form[$key] ?? null;
    if (is_string($value)) {
        $raw = $value;
    } elseif (is_numeric($value)) {
        $raw = (string) $value;
    } else {
        throw new \InvalidArgumentException('Amount is required.');
    }

    $trimmed = trim($raw);
    if ($trimmed === '') {
        throw new \InvalidArgumentException('Amount is required.');
    }

    $normalised = str_replace([' '], '', $trimmed);
    $commaPos = strrpos($normalised, ',');
    $dotPos = strrpos($normalised, '.');
    if ($commaPos !== false && $dotPos !== false) {
        // Last one wins as the decimal separator.
        if ($commaPos > $dotPos) {
            $normalised = str_replace('.', '', $normalised);
            $normalised = str_replace(',', '.', $normalised);
        } else {
            $normalised = str_replace(',', '', $normalised);
        }
    } elseif ($commaPos !== false) {
        $normalised = str_replace(',', '.', $normalised);
    }

    if (! is_numeric($normalised)) {
        throw new \InvalidArgumentException('Amount must be a number.');
    }

    return (int) round(((float) $normalised) * 100);
}
```

Apply the same fix to `ModelWhatIfDropdown::parseAmountToMinor`. Add Pest unit tests for `"12.50"` → 1250, `"12,50"` → 1250, `"1,234.56"` → 123456, `"1.234,56"` → 123456.

Long-term: extract a `Modules/Ledger/Public/Services/LocaleAwareMoneyInputParser` so the four call sites stop diverging.

---

### CR-03: `ChainAwareForecastRouter` drops legitimate same-date contributions during ICS-settlement dedup

**File:** `Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php:131-141`

**Issue:**
The intent (per the doc on lines 38-41) is "de-duplicate `(funder account, date)` overlaps with a routed Phase 8 contribution. Prefer the synthesised contribution." The implementation:

```php
$dedup = [];
$dueKey = $synth->accountId.'|'.$dueDate->toDateString();
foreach ($routed as $c) {
    $cKey = $c->accountId.'|'.$c->date->toDateString();
    if ($cKey === $dueKey) {
        continue;
    }
    $dedup[] = $c;
}
$dedup[] = $synth;
```

This unconditionally drops EVERY routed contribution sharing the synthesised settlement's `(accountId, date)` tuple — not just ICS-related ones. If the user has an approved recurring series (e.g. salary inflow, mortgage payment, Netflix monthly outflow) that happens to project an occurrence on the ICS settlement date on the same ASN funder account, that occurrence gets silently deleted and replaced with the ICS settlement amount alone. The user's day-of-month-30 projection will show ONLY the ICS settlement on that day, missing the legitimate recurring inflow/outflow.

The dedup logic should match only the synthesised settlement against a corresponding chain-routed ICS bulk-settle contribution, NOT against any recurring series that happens to fall on the same date.

The mathematical impact at the daily-fold level is also wrong: the dropped contribution's signed point + spread^2 are not added to the running balance + uncertainty band on day N, so the chart materially mis-states the user's projected position on the settlement day.

**Fix:**
Drop the dedup step or scope it tightly. The cleanest fix is to identify whether the synthesised settlement is already represented as a chain-link-routed contribution on the funder account that day. If the chain-aware routing in step 1 has already moved an ICS-bulk-settle series's contribution onto the funder for the same date, that's the only case where dedup should fire. A minimal scoping:

```php
// Only dedup against the chain-routed (seriesId !== 0) contribution that
// has been rewritten onto the funder account by step 1 — never against
// independent recurring series whose occurrence happens to land on the
// settlement date.
$dedup = [];
$dueKey = $synth->accountId.'|'.$dueDate->toDateString();
$kept = false;
foreach ($routed as $c) {
    $cKey = $c->accountId.'|'.$c->date->toDateString();
    // Only drop the previously-routed contribution if it was chain-routed
    // (its seriesId points at the ICS card's expense series — already
    // moved onto the funder by step 1) AND the (account, date) tuple
    // matches the synthesised settlement. Independent recurring series
    // on the funder retain their contribution.
    if ($cKey === $dueKey && $this->isChainRoutedIcsExpense($c, $funderBySeries)) {
        continue;
    }
    $dedup[] = $c;
}
$dedup[] = $synth;
```

`isChainRoutedIcsExpense` can be a one-liner that checks the per-series cache populated in step 1: `array_key_exists($c->seriesId, $funderBySeries) && $funderBySeries[$c->seriesId] === $c->accountId`. Alternatively, accept some over-counting (one of these days will have both the per-series occurrence AND the bulk settlement — a known limitation) and remove the dedup entirely; add a deferred-items entry for a more principled chain-collision check in a later phase.

Add Pest coverage: a fixture where a non-ICS recurring series (e.g. Netflix on ASN) projects onto the same date as the ICS settlement and confirm both contributions survive into the fold.

## Warnings

### WR-01: Three Forecasting tables declare `user_id` nullable — drifts from the chain_resolution_runs precedent

**File:** `Modules/Forecasting/Database/Migrations/2026_05_19_010001_create_forecast_scenarios_table.php:35`
**File:** `Modules/Forecasting/Database/Migrations/2026_05_19_010002_create_forecast_scenario_mutations_table.php:44`
**File:** `Modules/Forecasting/Database/Migrations/2026_05_19_010003_create_forecast_shortfall_windows_table.php:36`

**Issue:**
`forecast_runs` (migration 010004) correctly declares `user_id` non-nullable, with a docstring referencing the Phase 5 `chain_resolution_runs` precedent: "every run maps to exactly one user; the run id is never shared across users — eliminates NULL-distinct-in-UNIQUE bugs at the schema layer." The other three tables (`forecast_scenarios`, `forecast_scenario_mutations`, `forecast_shortfall_windows`) declare `user_id` nullable but EVERY Public Action and pipeline that writes to them always sets it. The nullable column is a multi-user-safety footgun: a future `Schedule::call` or admin-tool path that forgets `$user->id` would silently insert a row with `user_id = NULL`, which then escapes every `where('user_id', $user->id)` filter and leaks across users.

`forecast_scenarios.user_id` also participates in the UNIQUE `(user_id, name)` constraint (migration line 40). SQLite's NULL-distinct-in-UNIQUE semantics mean two NULL-user-id scenarios with the same name CAN coexist, while two user-id-set duplicates cannot — exactly the schema-layer bug the `forecast_runs` docstring calls out.

**Fix:**
Change the three migrations to non-nullable. Because the migrations have already shipped, the fixer should add a follow-up migration that re-creates the columns as `NOT NULL` (SQLite requires table rebuild for ALTER COLUMN — Laravel's doctrine-driven `change()` handles this transparently):

```php
// New migration: 2026_05_20_010001_tighten_forecast_user_id_columns.php
return new class extends Migration {
    public function up(): void {
        $this->schema()->table('forecast_scenarios', function (Blueprint $t): void {
            $t->foreignId('user_id')->nullable(false)->change();
        });
        $this->schema()->table('forecast_scenario_mutations', function (Blueprint $t): void {
            $t->foreignId('user_id')->nullable(false)->change();
        });
        $this->schema()->table('forecast_shortfall_windows', function (Blueprint $t): void {
            $t->foreignId('user_id')->nullable(false)->change();
        });
    }
    // ...
};
```

Also update the matching `@property int|null $user_id` PHPDocs on the four model files to `@property int $user_id` after the column changes.

---

### WR-02: `forecast-highlights-tile.blade.php` strips the sign from the lowest projected balance

**File:** `Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php:19-25,50,57`

**Issue:**
The dashboard tile formats the lowest projected balance via `abs($minor)`:

```php
$fmtMinor = static function (?int $minor, string $currency = 'EUR'): string {
    if ($minor === null) {
        return '';
    }
    return Money::ofMinor(abs($minor), $currency)->format('nl_NL');
};
```

For a user whose projection dips below zero (overdraft), `$dto->lowestProjectedBalanceMinor` is negative, but the tile renders `€100` instead of `-€100` / `€100 overdrawn`. The "dips to" copy implies a number that drops, but the user cannot distinguish "the account is at €100 and dips no further" from "the account hits −€100" — a material difference for cash-flow decisions.

FCT-01's whole point is to surface "this is when you actually have no money." Stripping the sign defeats that surface.

**Fix:**
Drop the `abs()` and let `Money::format('nl_NL')` render the negative sign per locale convention:

```php
$fmtMinor = static function (?int $minor, string $currency = 'EUR'): string {
    if ($minor === null) {
        return '';
    }
    return Money::ofMinor($minor, $currency)->format('nl_NL');
};
```

Verify `Money::ofMinor(-10000, 'EUR')->format('nl_NL')` outputs `"€ -100,00"` or similar — the `Phase 3 D-46` snapshot confirms this is the EUR/nl_NL convention.

Add a Pest test seeding a negative `lowestProjectedBalanceMinor` and asserting the rendered HTML contains the minus sign.

---

### WR-03: `ForecastPage::render` renders empty page on tampered `?account=` URL parameter

**File:** `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php:246-265`

**Issue:**
The render method handles three account-tab cases:

```php
$isAllAccountsView = $this->account === 'all';
if ($isAllAccountsView) {
    $selectedAccountId = null;
} else {
    $selectedAccountId = is_numeric($this->account) ? (int) $this->account : null;
    if ($selectedAccountId !== null) {
        // ... cross-user 404 guard
    }
}
```

When a user (or a tampered link) supplies `?account=garbage`, `$this->account === 'all'` is false AND `is_numeric('garbage')` is false → `$selectedAccountId = null` AND `$isAllAccountsView = false`. The page then renders the tab bar but skips both the All-accounts aggregate block (gated by `isAllAccountsView`) and the per-account block (gated by `$selectedAccountId !== null`). The user sees a blank page with no error or fallback.

The intent of the `#[Url(as: 'account', except: 'all')]` attribute is clearly "default to All accounts when unset" — the same default should apply to unrecognised values.

**Fix:**

```php
$selectedAccountId = null;
if ($this->account !== 'all') {
    if (! is_numeric($this->account)) {
        // Tampered or stale URL — fall back to the All-accounts tab.
        $this->account = 'all';
    } else {
        $selectedAccountId = (int) $this->account;
        // ... existing cross-user 404 guard
    }
}
$isAllAccountsView = $this->account === 'all';
```

Add a Pest assertion that mounting with `?account=garbage` defaults to the All-accounts view rather than a blank page.

---

### WR-04: `ScenarioApplier::applyAddOneOff` returns `accountId=0` when baseline is empty (silent drop)

**File:** `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php:215-227`

**Issue:**
`pickAccountIdForOneOff` returns `0` when the baseline contributions list is empty:

```php
private function pickAccountIdForOneOff(array $contributions): int
{
    $counts = [];
    foreach ($contributions as $c) {
        $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
    }
    if ($counts === []) {
        return 0;
    }
    // ...
}
```

The comment says the daily fold's "bucket-or-skip behaviour handles that gracefully" — but the consequence is that on a fresh-import user with no approved recurring series, a one-off scenario mutation produces a `ForecastContribution(accountId=0)` that lands in `$byAccount[0]` in `ProjectionPipeline::computeResult`, where the per-account `foreach ($accounts as $account)` loop never inspects accountId=0. The contribution silently disappears, and the user's scenario chart looks identical to baseline.

This breaks the second half of FCT-03 — "add a planned transaction… and see the impact." On a fresh-import user, the impact is silently zero.

The same bug applies to `applyAddRecurring` since it reuses `pickAccountIdForOneOff`.

**Fix:**
Take the first owned account as the fallback. `pickAccountIdForOneOff` already has the contribution buckets — passing a User + DatabaseManager is heavy DI for this helper. The cleanest fix is to:

1. Add a `targetAccountId` field to `AddOneOffPayload` and `AddRecurringPayload` (the user already picks an account in the form via the inferred-from-baseline logic; making it explicit removes the ambiguity).
2. OR: pass a `?int $fallbackAccountId` parameter to `ScenarioApplier::apply()`, computed by `ProjectionPipeline::computeResult` as "the user's alphabetically-first account by name, id."

Short-term hotfix without an API change: validate non-zero in `pickAccountIdForOneOff` and throw an `InvalidArgumentException` so the contribution-lost case surfaces instead of silently disappearing. The `AddScenarioMutation` Action can catch the exception and surface "Please approve at least one recurring series before adding a one-off scenario mutation" to the UI.

Add a Pest test for the empty-baseline case asserting the one-off scenario produces a visible chart delta.

---

### WR-05: Eleven GSD-references leak into Forecasting + Core runtime PHPDoc / Blade comments

**File:** `Modules/Forecasting/Providers/ForecastingServiceProvider.php:101`
**File:** `Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php:4`
**File:** `Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php:155`
**File:** `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php:21,33`
**File:** `Modules/Forecasting/Internal/Pipeline/ShortfallDetector.php:21`
**File:** `Modules/Forecasting/Internal/Pipeline/ChainAwareForecastRouter.php:24`
**File:** `Modules/Forecasting/Internal/Http/Livewire/ForecastHighlightsTile.php:19`
**File:** `Modules/Forecasting/Public/Actions/AddScenarioMutation.php:33`
**File:** `Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php:31`
**File:** `Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php:23`
**File:** `Modules/Core/Resources/views/livewire/top-nav.blade.php:138`

**Issue:**
Project invariant per `CLAUDE.md` + user memory `feedback_codebase_gsd_agnostic.md`: "No `D-numbered` references (like `D-1002`, `D-1015`); no requirement IDs (like `FCT-03`, `CHN-01`); no `.planning/` paths in runtime PHP / PHPDoc / Blade." Eleven runtime references remain:

- `D-1002` — `ChainAwareForecastRouter.php:24`
- `D-1011` — `ShortfallDetector.php:21`, `SetAccountForecastBuffer.php:31`
- `D-1013` — `ForecastHighlightsQuery.php:23`, `forecast-highlights-tile.blade.php:4`
- `D-1025` — `top-nav.blade.php:138`
- `D-1026` — `ForecastHighlightsTile.php:19`
- `FCT-03` — `ForecastingServiceProvider.php:101`, `ProjectionPipeline.php:155`, `ScenarioApplier.php:21,33`, `AddScenarioMutation.php:33`

The verifier noted these are part of a project-wide pre-existing pattern (46 occurrences in earlier modules). The user's memory explicitly classifies this as a violation to fix, and the `--all` flag is active.

**Fix:**
Rewrite each comment to express the technical rationale in plain language. Concrete example for `ChainAwareForecastRouter.php:24`:

Before:
```
 * Algorithm (D-1002):
```

After:
```
 * Algorithm — chain-aware contribution routing:
```

For `ScenarioApplier.php:21,33`:

Before:
```
 * The load-bearing FCT-03 in-memory transform.
 ...
 * the FCT-03 boundary, and Wave 5's ScenarioIsolationContractTest will
```

After:
```
 * The load-bearing scenario in-memory transform. Walls scenario
 * mutations off from any transaction-substrate JOIN.
 ...
 * the scenario-isolation boundary, and ScenarioIsolationContractTest
```

Apply the same plain-language rewrite to every flagged line. The Pest arch test from a future cleanup pass would enforce this; for now, manual sweep.

---

### WR-06: `ScenarioMutationPayload` subclasses accept arbitrary `direction`/`cadence`/`scope` strings

**File:** `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddOneOffPayload.php:19`
**File:** `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddRecurringPayload.php:22-23`
**File:** `Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ShiftSeriesDatePayload.php:20`

**Issue:**
Each payload accepts these as `public readonly string`:

- `AddOneOffPayload::$direction` — expected `'expense' | 'income'`, no validation
- `AddRecurringPayload::$direction`, `$cadence` — expected `'expense' | 'income'` and `'weekly' | 'monthly' | 'quarterly' | 'yearly'`, no validation
- `ShiftSeriesDatePayload::$scope` — expected `'next' | 'all_subsequent'`, no validation

Spatie LaravelData's `::from()` cast does not enforce enum constraints on plain string properties. A tampered payload (or a future migration that mistypes the JSON) yields a string the downstream applier silently mishandles:

- `ScenarioApplier::applyAddOneOff` line 185 treats any non-`'income'` value as expense: `$sign = $payload->direction === 'income' ? 1 : -1`. A user typo `Income` (capitalized) silently becomes an expense.
- `ScenarioApplier::applyAddRecurring` line 245-247 validates cadence inline and returns empty contributions on mismatch — the entire add-recurring mutation silently disappears.
- `ScenarioApplier::applyShiftSeriesDate` line 387 treats any non-`'all_subsequent'` value as `'next'` — typo `next_only` silently shifts only the first occurrence even when the user picked "all subsequent".

The cast layer (`ScenarioMutationPayloadCast`) doesn't validate either — it routes by `kind`, then calls `::from($decoded)` which only checks PHP type compatibility.

**Fix:**
Tighten the constructor with assertions, or convert the fields to `enum` types. The lowest-friction fix is in-constructor validation:

```php
// AddOneOffPayload.php
public function __construct(
    public readonly string $date,
    public readonly int $amountMinor,
    public readonly string $currency,
    public readonly string $direction,
    public readonly ?string $note = null,
) {
    if (! in_array($direction, ['expense', 'income'], true)) {
        throw new InvalidArgumentException(
            "AddOneOffPayload.direction must be 'expense' or 'income'; got '{$direction}'."
        );
    }
}
```

Same pattern for `AddRecurringPayload` (validate `direction` AND `cadence`) and `ShiftSeriesDatePayload` (validate `scope`). The throw is caught by `AddScenarioMutation` / `EditScenarioMutation` via the existing `InvalidArgumentException` catch blocks, so the UI surfaces the error correctly. The cast layer also catches it on `get()`, so a corrupted DB row surfaces a loud error instead of silently mis-rendering.

Alternative: convert to native PHP enums (`enum MutationDirection: string { case Expense = 'expense'; case Income = 'income'; }`). Spatie LaravelData supports enums in `Data` subclasses; the JSON round-trip preserves the enum value. This is cleaner long-term.

Add Pest unit tests for each invalid value.

---

### WR-07: `ForecastHighlightsQuery::forUser` ignores account name when `$run` has no points; sets `lowestProjectedBalanceDate=''`

**File:** `Modules/Forecasting/Public/Services/ForecastHighlightsQuery.php:117-129`

**Issue:**
The inner loop over points has:

```php
$pointDate = isset($point['date']) && is_string($point['date']) ? $point['date'] : '';
if ($lowestMinor === null || $pointMinor < $lowestMinor) {
    $lowestMinor = $pointMinor;
    $lowestDate = $pointDate;
    // ...
}
```

When a point row lacks a `date` field (corrupt `result_json`), `$lowestDate` is set to the empty string. The `ForecastHighlightsDto::lowestProjectedBalanceDate` then carries `''`, which the dashboard tile renders as the string sentinel `' on '` becoming missing in the output. Cleaner UX:

```php
if (! is_string($point['date'] ?? null) || $point['date'] === '') {
    continue; // skip malformed point
}
```

Also: the inner `$run` query is re-executed once per account in the foreach loop (lines 78-130), even though the run JSON contains all accounts. This is a performance issue (out of v1 scope) but worth fixing while editing the method since the function is being touched anyway. Lift the query above the loop:

```php
$run = $this->db->connection()->table('forecast_runs')
    ->where('user_id', $user->id)
    ->whereNull('scenario_id')
    ->where('horizon_days', 30)
    ->where('status', 'complete')
    ->orderByDesc('id')
    ->first(['result_json']);
if ($run === null) { /* return early */ }
// ... decode once, then iterate accounts
```

**Fix:**
Hoist the `forecast_runs` lookup out of the per-account loop and `continue` on malformed point rows (no `date` string).

---

### WR-08: `aggregate-line-chart.blade.php` data-options attribute uses single-quoted `@json` — breaks on any quote in payload

**File:** `Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php:72`

**Issue:**

```html
data-options='@json($options)'
```

`@json` outputs `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT` by default — so single-quote and double-quote inside the payload would be escaped to `'` and `"`. But the attribute itself is single-quoted, so any literal single quote in a string value of `$options` (e.g. an account name with an apostrophe inserted via a future change) WOULD break the attribute. The sibling `range-area-chart.blade.php:52` uses `data-options="{{ $optionsJson }}"` with double quotes which is the safer pattern.

Currently `$options` for the aggregate chart contains only numeric data + hard-coded English strings (`'Total balance'`, `'#0F172A'`, etc.) so no live exploit, but the asymmetry is a future-defect waiting to happen.

**Fix:**
Match the range-area-chart pattern verbatim:

```php
@php
    $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($optionsJson === false) {
        $optionsJson = '{}';
    }
@endphp

<div
    id="{{ $chartElementId }}"
    data-testid="all-accounts-aggregate-chart"
    data-chart-variant="line"
    data-options="{{ $optionsJson }}"
    ...
></div>
```

The double-quoted attribute + Blade's `{{ }}` escaping handles HTML-attribute encoding correctly.

---

### WR-09: `CreateCancellationScenarioForAlert/ForSeries` + `CreateAmountChangeScenarioForSeries` propagate `InvalidArgumentException` (duplicate name) without UI handling

**File:** `Modules/Forecasting/Public/Actions/CreateCancellationScenarioForAlert.php:61-66`
**File:** `Modules/Forecasting/Public/Actions/CreateCancellationScenarioForSeries.php:46-51`
**File:** `Modules/Forecasting/Public/Actions/CreateAmountChangeScenarioForSeries.php:46-56`
**Caller:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php:101-110`
**Caller:** `Modules/Forecasting/Internal/Http/Livewire/ModelWhatIfDropdown.php:83-110`

**Issue:**
The three launchpad Actions invoke `CreateScenario` with `"Cancel {seriesName}"` / `"Change {seriesName} amount"`. `CreateScenario` enforces a `UNIQUE(user_id, name)` constraint via `looksLikeUniqueViolation` → `InvalidArgumentException("A scenario with that name already exists.")`. If the user has already clicked "Model cancel" for the same series once before (e.g. opened the drift alert and started modelling, then went back without deleting the scenario), the second click throws `InvalidArgumentException`.

The two Livewire callers don't catch this:

- `DriftPage::modelCancelInForecast` directly invokes the action and returns the redirect. An uncaught exception bubbles to the Livewire kernel as a 500 error.
- `ModelWhatIfDropdown::modelCancellation` and `saveAmountChange` similarly don't catch — an uncaught exception again.

The user sees a server error instead of the calm UX the launchpad is supposed to deliver. The calmer behavior is to redirect to the existing scenario when the name collides.

**Fix:**
Either:

1. **In the launchpad Action:** Catch `InvalidArgumentException` from the inner `CreateScenario` call. Look up the existing scenario by `(user_id, name)` and return its id without modifying it.

```php
// CreateCancellationScenarioForAlert.php
try {
    $scenarioId = ($this->createScenario)($user, "Cancel {$name}");
    ($this->addMutation)($scenarioId, $user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));
    return $scenarioId;
} catch (\InvalidArgumentException $e) {
    // Existing scenario with same name — fetch and return its id.
    $existing = $this->db->connection()->table('forecast_scenarios')
        ->where('user_id', $user->id)
        ->where('name', "Cancel {$name}")
        ->value('id');
    if (is_numeric($existing)) {
        return (int) $existing;
    }
    throw $e;
}
```

2. **In the caller:** Catch the exception in `DriftPage::modelCancelInForecast` and dispatch a toast + redirect to `/forecast` (no scenarioId).

Option 1 is cleaner (no error UI for a repeat-click); option 2 is mechanically simpler. Either way, add a Pest test that double-clicks the launchpad and asserts the second invocation yields the existing scenario id, not a 500.

---

### WR-10: `routes/console.php` daily forecast sweep loads ALL users into memory; injected `$db` is unused

**File:** `routes/console.php:185-189`

**Issue:**

```php
Schedule::call(function (DatabaseManager $db, Dispatcher $bus, ScenarioQuery $scenarioQuery): void {
    $users = User::query()->get();
    foreach ($users as $user) {
        // ...
    }
    unset($db);
})->name('forecasting.daily-sweep')->daily()->withoutOverlapping(30);
```

Two issues:

1. `User::query()->get()` loads every user row at once. Single-user v1 is fine; FND-03's "multi-user-ready" preference would benefit from `->lazyById(100)` so a partner-deployment with N users does not balloon memory.
2. `$db` is injected but unused (the `unset($db)` is a Larastan-level-10 workaround for an unused parameter). Drop the parameter from the closure signature.

Neither is a blocker, but both are easy cleanup.

**Fix:**

```php
Schedule::call(function (Dispatcher $bus, ScenarioQuery $scenarioQuery): void {
    User::query()->lazyById(100)->each(function (User $user) use ($bus, $scenarioQuery): void {
        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $bus->dispatch(new ProjectForecastJob(
                userId: (int) $user->id,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
        foreach ($scenarioQuery->forUser($user) as $scenario) {
            foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
                $bus->dispatch(new ProjectForecastJob(
                    userId: (int) $user->id,
                    scenarioId: $scenario->id,
                    horizonDays: $horizon,
                ));
            }
        }
    });
})->name('forecasting.daily-sweep')->daily()->withoutOverlapping(30);
```

---

### WR-11: `ScenarioApplier::pickAccountIdForOneOff` uses `arsort` which preserves keys but the return uses `array_key_first` — silently picks alphabetically-first id on ties

**File:** `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php:215-227`

**Issue:**
```php
$counts = [];
foreach ($contributions as $c) {
    $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
}
if ($counts === []) {
    return 0;
}
arsort($counts);

return array_key_first($counts);
```

`arsort` preserves keys and sorts by value descending. When two accounts have equal contribution counts (common when the baseline has multiple recurring series — e.g. salary and Spotify on ASN both fire), the tie-breaker is the original insertion order, NOT a deterministic property like account id. The applied scenario's chart will depend on the iteration order of `$baselineContributions` which is the order they were emitted from `RangeProjector::project()` inside `ProjectionPipeline::computeResult` — a fairly opaque dependency.

Worse: this means an `add_one_off` mutation can land on a DIFFERENT account between two scenarios with the same baseline if a recurring series was added/removed between them. The net diff tile reads scenario-vs-baseline at days 30/60/90, but the baseline and scenario use DIFFERENT account choices because the contribution count tie-break is order-dependent.

**Fix:**
Tie-break deterministically:

```php
private function pickAccountIdForOneOff(array $contributions): int
{
    $counts = [];
    foreach ($contributions as $c) {
        $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
    }
    if ($counts === []) {
        return 0; // WR-04 covers this case
    }
    // Sort by count DESC, then by accountId ASC for stable tie-break.
    uksort($counts, static function (int $a, int $b) use ($counts): int {
        return $counts[$b] <=> $counts[$a] ?: $a <=> $b;
    });

    return array_key_first($counts);
}
```

Add a Pest unit test with a tied-count baseline and assert the deterministic account.

---

### WR-12: `ScenarioApplier::applyChangeSeriesAmount` ignores `$user`-mismatched series silently (no defensive check)

**File:** `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php:292-311`

**Issue:**

```php
private function applyChangeSeriesAmount(array $contributions, ChangeSeriesAmountPayload $payload, User $user): array
{
    $series = $this->seriesQuery->forSeries($payload->seriesId, $user);
    if ($series === null) {
        return $contributions; // referenced series gone — skip silently.
    }
    // ...
}
```

The "silently skip" comment matches the doc on `apply` (line 38) — "silently skips mutations whose referenced series has since been deleted." That's reasonable for a deleted series. But `forSeries` ALSO returns null for a cross-user series id. If a tampered scenario_mutations row carries `seriesId` belonging to user B, when user A's `ScenarioApplier` runs it silently treats it as "deleted" and proceeds with the baseline contribution untouched.

This isn't a security boundary breach (the row had to be persisted in the first place, and `AddScenarioMutation::assertSeriesOwnedByUser` blocks cross-user writes there) BUT a downstream bug surfaces if a future code path skips the action layer (e.g. a future seeder, an Artisan command, or an admin tool). Logging a warning when `$series === null` AND the referenced row exists would surface this. Simpler: just keep the silent skip but extend it with a defensive `$this->db->table('recurring_series')->where('id', ...)->exists()` check — if the row exists but `forSeries` returned null, the user_id mismatch is real and the applier should refuse the mutation entirely (return empty contributions list).

**Fix:**
Either:

1. **Defensive logging:** Inject `Psr\Log\LoggerInterface` into `ScenarioApplier`, log a warning when the series is null but the underlying row exists (cross-user mismatch detected).
2. **Hard fail:** Throw `RuntimeException` when the series id refers to a row that exists but belongs to another user; the `noScenarioMutationsJoinedToTransactionQueries` invariant is preserved (the check uses `recurring_series.id` only, no JOIN to occurrences/transactions).

Option 1 is calmer. Apply the same pattern to `applyShiftSeriesDate` (line 354-419).

## Info

### IN-01: Unused `Clock` injection in `ScenarioApplier`

**File:** `Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php:73,91-92`

**Issue:**
`Clock` is constructor-injected, then `$now = $this->clock->now(); unset($now);` is the only usage in `apply()` (line 91-92). The accompanying comment ("Clock is constructor-injected for parity with sibling pipeline stages; the static-analysis visibility is preserved via the following touch so phpstan-strict-rules accepts the unused promoted property until a later wave uses it") explicitly flags it as dead. Drop the injection until a real use lands.

**Fix:** Remove the `Clock` parameter from the constructor and update `ForecastingServiceProvider` (no service-provider change needed since `singleton(ScenarioApplier::class)` uses container autowiring).

---

### IN-02: Unused `Clock` injection / dead `?? $now` fallback in `ScenarioQuery::mutationsFor`

**File:** `Modules/Forecasting/Public/Services/ScenarioQuery.php:122,135`

**Issue:**

```php
$now = $this->clock->now();
// ...
$createdAt = $rawCreated instanceof CarbonImmutable ? $rawCreated : $now;
```

The model casts `created_at` to `immutable_datetime`, which is always a `CarbonImmutable`. The `?? $now` fallback never fires. Dead code path; `Clock` becomes dead injection.

**Fix:** Drop both the `$now` variable and the fallback; rely on the model cast. Drop the `Clock` parameter from the constructor.

---

### IN-03: Magic number — €500 divergence threshold in `SetAccountOpeningBalance`

**File:** `Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php:87`

**Issue:**
`if (abs($diff) > 50000)` — 50000 minor units = €500. The docstring (line 32-33) references the figure but the code uses a magic integer. Extract to a class constant:

```php
private const DIVERGENCE_WARNING_THRESHOLD_MINOR = 50_000; // €500.00
```

---

### IN-04: Redundant composer.json autoload entry

**File:** `composer.json:51`

**Issue:**
The new entry `"Modules\\Forecasting\\": "Modules/Forecasting/"` is fully shadowed by the broader `"Modules\\": "Modules/"` entry one line above. PSR-4 resolution is prefix-longest first, so the entry has no functional effect.

**Fix:** Remove the line.

---

### IN-05: Singleton-bound `ForecastHighlightsTile` Livewire SFC may surface stale view state across requests

**File:** `Modules/Forecasting/Providers/ForecastingServiceProvider.php:128`

**Issue:**
`$this->app->singleton(ForecastHighlightsTile::class);` — Livewire `Component` instances normally do not need container singleton registration; Livewire's `mount`/`render` lifecycle resolves a fresh instance per request via `LivewireManager::component(...)` already. The singleton binding adds nothing and risks surfacing stale property state across requests in long-running processes (Octane / Reverb — not in scope today, but the comment "(singleton-bound so it resolves once per request)" is inaccurate; Livewire components are NOT request singletons by default).

**Fix:** Drop the singleton registration; rely on `$livewire->component('forecasting.forecast-highlights-tile', ForecastHighlightsTile::class)` alone (already at line 150).

---

### IN-06: `ForecastDtoMapper::mapForecast` returns hard-coded `seriesConfidence: []` then `ForecastQuery` overwrites it

**File:** `Modules/Forecasting/Internal/Mapping/ForecastDtoMapper.php:66`
**File:** `Modules/Forecasting/Public/Services/ForecastQuery.php:115-137`

**Issue:**
The mapper constructs a `ForecastDto` with `seriesConfidence: []`, then `ForecastQuery::forUser` immediately constructs a NEW `ForecastDto` copying every field from the mapped one and replacing `seriesConfidence` with the real value from `resolveSeriesConfidenceForAccount`. The second construction is wasteful and reads awkward.

**Fix:** Either pass the confidence list into `mapForecast` as a parameter, or expose a setter / `withSeriesConfidence` builder on `ForecastDto`. Or: have `ForecastQuery::forUser` build the DTO once with all fields resolved.

---

### IN-07: `forecast_scenario_mutations.payload` JSON column has no schema-layer validation of `kind` enum

**File:** `Modules/Forecasting/Database/Migrations/2026_05_19_010002_create_forecast_scenario_mutations_table.php:46-47`

**Issue:**
`$table->string('kind', 40);` — no `CHECK` constraint at the SQLite level. The five-value enum is enforced at the ORM cast boundary (`ScenarioMutationPayloadCast::get` throws on unknown), but a raw INSERT (a future Artisan command, a manual SQL fix) could land an invalid value. The Phase 8 `recurring_series.state` precedent uses a SQLite trigger to enforce this; the same pattern would apply here.

**Fix:** Optional v2 hardening. Either add a CHECK constraint via raw SQL in the migration's `up()` (SQLite supports `CHECK (kind IN ('cancel_series', ...))`), OR add a defensive `BEFORE INSERT/UPDATE` trigger. Lower priority than the typed-cast which already covers ORM writes.

---

### IN-08: `ForecastPage::pointAtIndex` returns the LAST point when `dayOffset` exceeds available days — silent off-by-one risk

**File:** `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php:535-544`

**Issue:**

```php
private function pointAtIndex(ForecastDto $dto, int $dayOffset): int
{
    $points = $dto->points;
    if ($points === []) {
        return 0;
    }
    $idx = min(count($points) - 1, $dayOffset);

    return $points[$idx]->pointMinor;
}
```

The `$horizonKey > $dto->horizonDays` guard in `computeNetDiff` (line 524-526) covers the documented case ("horizonKey > baseline.horizonDays → skip"). But if `points` is shorter than `horizonDays + 1` for any reason (e.g. a malformed `result_json` row), this silently returns the last available point's value as if it were the day-60 or day-90 value. This is a defensive-coding smell — the function should throw or return `null` so the caller (which already returns 0 on `points === []`) sees a clear signal.

**Fix:** Return `?int` and have `computeNetDiff` skip rather than substitute when the point is missing.

---

### IN-09: `ProjectForecastJob::HORIZON_DAYS` constant value used identically in 7 places — could become a domain enum

**File:** `Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php:60`

**Issue:**
The `public const HORIZON_DAYS = [30, 60, 90];` is referenced in 7 places:

- `ForecastingServiceProvider` (not yet — would be via listener)
- `ProjectForecastJob::__construct` constructor-time validation
- `ProjectForecastOnRecurringChange::handle`
- `ProjectForecastOnDriftDismissed::handle`
- `ProjectForecastOnScenarioChange::handle`
- `routes/console.php` daily sweep
- `ForecastPage::setHorizon` validation (`[30, 60, 90]` inline — INCONSISTENT! line 101)
- `ForecastPage::computeNetDiff` (`[30, 60, 90]` inline — INCONSISTENT! line 523)

`ForecastPage` lines 101 + 523 inline the same array literal `[30, 60, 90]` instead of referencing `ProjectForecastJob::HORIZON_DAYS`. A future planner adding a 180-day horizon would update the constant but miss the two inline literals.

**Fix:** Replace the inline `[30, 60, 90]` arrays in `ForecastPage` with `ProjectForecastJob::HORIZON_DAYS`. The `net-diff-tile` Blade also hard-codes `[30, 60, 90]` (line 30) — consider passing it as a parameter from `ForecastPage::render` instead.

---

### IN-10: Mutation summaries in `ScenarioEditorSidebar::summaryFor` embed raw series_id — user-hostile

**File:** `Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php:466,483,488`

**Issue:**

```php
return 'Cancel series #'.$payload->seriesId;
return "Series #{$payload->seriesId}: new amount {$amount}";
return "Series #{$payload->seriesId}: shift {$scope} to {$payload->newNextDate}";
```

The mutation summary displays `series #123` instead of the series's display name. The sidebar already has `$this->availableSeries` populated by `render()` (line 316-322) carrying `[id, name]` pairs — wire it through:

**Fix:**

```php
private function summaryFor(string $kind, ScenarioMutationPayload $payload): string
{
    $resolveName = function (int $seriesId): string {
        foreach ($this->availableSeries as $entry) {
            if ($entry['id'] === $seriesId) {
                return $entry['name'];
            }
        }
        return 'series #'.$seriesId;
    };

    if ($payload instanceof CancelSeriesPayload) {
        return 'Cancel '.$resolveName($payload->seriesId);
    }
    // ... etc
}
```

---

_Reviewed: 2026-05-18_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
