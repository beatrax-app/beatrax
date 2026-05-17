---
phase: 09-subscription-drift-detection-alerts
plan: 05
subsystem: ui
tags: [livewire, livewire-popover, public-action, public-query, scheduled-job, query-time-conditional, multi-currency, settings-page]

# Dependency graph
requires:
  - phase: 09-subscription-drift-detection-alerts
    provides: "Plan 09-02 (Wave 1) — recurring_series.drift_threshold_percent column + users.drift_alert_threshold_percent column + DriftAlertStateMachine sole-mutator + CancellationImpactDto"
  - phase: 09-subscription-drift-detection-alerts
    provides: "Plan 09-03 (Wave 2) — DriftEvaluator effective-threshold rule (per-series override > user global > 5% floor)"
  - phase: 09-subscription-drift-detection-alerts
    provides: "Plan 09-04 (Wave 3) — DriftAlertQuery + DriftPage + drift-alert-row partial + dashboard tile + top-nav compound badge; the Wave 4 surfaces extend (not replace) the Wave 3 chrome"
  - phase: 08-recurring-detection-fixed-payments-view
    provides: "Phase 8 — RecurringSeriesQuery public-read surface, EditRecurringSeriesVarianceTolerance Public Action pattern (DI'd Clock + DatabaseManager + cross-user 404)"
provides:
  - "CancellationImpactQuery Public Service — Phase 10 hand-off contract: forSeries(int, User): ?CancellationImpactDto, denominated in the series's latest_currency (NOT hard-EUR), null on cross-user / missing"
  - "Per-series drift threshold popover editor (Livewire SFC `drift-alerts.drift-threshold-editor`) mounting inline on /drift grouped-by-series headers + single-alert rows AND on /recurring/series/{id}; six options (1/2/5/10/25/50) + Use global default; saves through Recurring-side SetDriftThresholdForSeries Public Action"
  - "SetDriftThresholdForSeries Recurring Public Action — null-or-whitelist({1,2,5,10,25,50}); metric-style write; idempotent same-value no-op; cross-user 404 via WHERE user_id; keeps noRecurringSeriesWritesFromDriftAlerts arch invariant green without an exemption"
  - "/settings 'Default drift alert threshold' select bound to users.drift_alert_threshold_percent; six options matching the per-series popover; anchor id='drift-threshold' lights up the existing /drift 'Adjust threshold →' deep link"
  - "RevivedExpiredDriftSnoozesJob (Internal queued job) — hourly sweep flipping snoozed → open via DriftAlertStateMachine with reason='detector_revived_snooze' actor='detector'; scheduled as 'drift-alerts.revive-snoozes' hourly in routes/console.php"
  - "DriftAlertQuery query-time compound conditional — openForUser / openCountForUser / totalOpenAnnualizedImpactForUser / groupedBySeriesForUser apply 'state=open OR (state=snoozed AND snoozed_until <= now())' so counts stay honest between sweeps"
  - "DriftDetectionContractTest now exercises all 24 corpus fixtures including the previously-deferred snooze-expiry-revival fixture"
affects: [10-forecasting]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cross-module Livewire mount via the registered component alias: /recurring/series/{id} mounts @livewire('drift-alerts.drift-threshold-editor'). The Recurring view never imports the DriftAlerts FQN; the alias indirection means BoundaryArchTest's Internal-usage rule never sees a cross-module reference."
    - "Recurring-owned Public Action for the DriftAlerts editor's write path. The cross-boundary mechanism is the Action call (DriftThresholdEditor::save → SetDriftThresholdForSeries); DriftAlerts itself contains zero `->table('recurring_series')->update/insert/delete` calls so `noRecurringSeriesWritesFromDriftAlerts` stays green without an exemption list."
    - "Query-time conditional companion to a scheduled write: openForUser widens the state filter to (state='open' OR (state='snoozed' AND snoozed_until <= now())) so the dashboard count stays honest immediately between hourly sweeps. The sweep is the durable audit-row write; the conditional is a read-side projection."
    - "Compound where + orWhere closure pattern on Laravel's query builder to nest the snooze-revival conditional inside a single grouped predicate, preserving the outer `WHERE user_id = X AND (...)` user scope."
    - "Schedule::call(closure-with-DI) entry mirroring the four pre-existing console.php schedule entries (email-scan, receipts, recurring): closure receives Bus Dispatcher via container DI and dispatches the job inside the closure, allowing the existing facade carve-out (routes/console.php is outside Modules\\) to remain the only place Schedule::call lives."

key-files:
  created:
    - Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php
    - Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php
    - Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php
    - Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php
    - Modules/Recurring/Public/Actions/SetDriftThresholdForSeries.php
    - Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php
    - Modules/DriftAlerts/tests/Feature/CancellationImpactDisplayTest.php
    - Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php
    - Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest.php
    - Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php
  modified:
    - Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php
    - Modules/DriftAlerts/Public/Services/DriftAlertQuery.php
    - Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php
    - Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php
    - Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php
    - Modules/Recurring/Providers/RecurringServiceProvider.php
    - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
    - Modules/Core/Internal/Http/Livewire/SettingsPage.php
    - Modules/Core/Resources/views/livewire/settings-page.blade.php
    - routes/console.php
    - tests/Contracts/DriftDetectionContractTest.php

key-decisions:
  - "CancellationImpactDto savings are stored as positive (absolute) amounts. `recurring_series.monthly_equivalent_minor` is signed (negative for expenses); cancellation 'savings' read as positive cash flow preserved, so the query takes abs() once at the boundary. The renderer formats with the locale-aware Money formatter and the static 'Cancel this → save X/yr' copy reads naturally for the dominant expense case without any sign-juggling at the partial."
  - "Per-series threshold editor mounts inline on BOTH multi-alert /drift card headers AND single-alert /drift rows (alongside the action chips). The plan said 'on grouped-by-series headers'; in the multi-alert case the header is the flux:card; in the single-alert case the 'group' is the row itself. Two mount points keep the editor reachable wherever a series surfaces. The /recurring/series/{id} detail page also mounts the same component adjacent to Phase 8's variance-tolerance popover so the two thresholds live in the same visual cluster."
  - "DriftAlertsServiceProvider registers SetDriftThresholdForSeries from the Recurring side (in RecurringServiceProvider). DriftAlerts has no business owning the singleton binding for a Recurring-owned Public Action — the Recurring provider is the canonical lifecycle owner."
  - "Schedule::call(closure-with-DI) over Schedule::job(new Job) in routes/console.php. The four pre-existing schedule entries all use the closure pattern (with container DI on `DatabaseManager + Dispatcher`), and routes/console.php carries a documented arch-test carve-out for the Schedule facade. Mirroring the dominant pattern keeps the file consistent and the test-style harness symmetric (no new code path to test)."
  - "The DriftAlertQuery::openCountForUser + totalOpenAnnualizedImpactForUser + groupedBySeriesForUser ALL widen their state filter, not just openForUser. The dashboard tile's count + EUR-roll-up are both rendered off these aggregates; widening only openForUser would leave the headline numbers stale relative to the listing. Single-source-of-truth: applyOpenStateFilter() centralises the predicate so a future fix lives in one place."
  - "The DriftDetectionContractTest's revival branch dispatches the state-machine transition AND the revival job inside the test body rather than wiring the fixture to the evaluator's normal control flow. The fixture describes a post-revival state which the evaluator alone cannot produce (the revival requires a state-machine call). Embedding the snooze + revival sequence inside the test keeps the contract test self-contained without forcing the evaluator to know about revival."

patterns-established:
  - "Public Query layer reads cross-module via the upstream module's Public Query (CancellationImpactQuery → RecurringSeriesQuery). Read-only access stays inside the boundary; no DTO is invented for the cross-call (the Recurring DTO is consumed inline)."
  - "Recurring-owned Public Action as the write path for a cross-module Livewire editor. The editor is per-task scoped (DriftAlerts) but the write target belongs to a different module (Recurring); the carve-out is the boundary-respecting Public Action."
  - "Three-channel state-flip pattern (state machine + scheduled job + read-side conditional). The state machine is the sole writer of state; a scheduled job invokes the state machine on a timer; a read-side query-time conditional surfaces the would-have-flipped rows immediately between sweeps. Eventually-consistent at the audit layer, immediately-consistent at the read layer."
  - "Settings page composition: a new setting is a property + validate-rule + mount-hydrate + save-line + messages-line + Blade <section>. The shape is uniform across the existing settings; the new drift threshold field followed the recurring-detection-window precedent verbatim."

requirements-completed: [REC-06, REC-07]

# Metrics
duration: 65min
completed: 2026-05-17
---

# Phase 09 Plan 05: Wave 4 polish — CancellationImpactQuery + threshold overrides + snooze revival Summary

**CancellationImpactQuery Phase-10 hand-off + inline /drift "Cancel this → save €X/yr" + per-series threshold popover (mounted on /drift + /recurring/series/{id}) saving via the new Recurring-side SetDriftThresholdForSeries Public Action + /settings global drift threshold field + hybrid snooze revival (hourly RevivedExpiredDriftSnoozesJob + query-time compound conditional on DriftAlertQuery) — all green across 25 new Pest cases, the full DriftDetectionContractTest now passes all 24 corpus fixtures including the previously-deferred snooze-expiry-revival, full project suite 1593 passed / 6 pre-existing skips, PHPStan strict + Pint clean, BoundaryArchTest DriftAlerts invariants stay green.**

## Performance

- **Duration:** ~65 minutes
- **Started:** 2026-05-17T20:41:00Z
- **Completed:** 2026-05-17T21:46:18Z
- **Tasks:** 4 (all `type="auto"` — no checkpoints in this wave)
- **Files created:** 10 (1 Public Service + 1 Recurring Public Action + 1 Internal Livewire SFC + 1 Internal queued Job + 1 Blade view + 5 Pest test files)
- **Files modified:** 11 (DriftPage SFC + drift-page Blade + drift-alert-row partial + DriftAlertQuery + 2 provider files + recurring-series-detail-page Blade + SettingsPage SFC + settings-page Blade + routes/console.php + DriftDetectionContractTest)

## Accomplishments

- **CancellationImpactQuery (Phase 10 hand-off contract).** `forSeries(int $seriesId, User $user): ?CancellationImpactDto` reads `recurring_series` exclusively via `RecurringSeriesQuery` (cross-module Public Query layer); returned DTO carries `monthlySavings` + `annualSavings` denominated in the series's `latest_currency` (NOT hard-EUR — Pitfall 1 honored); null on cross-user invocation or missing series. The PHPStan baseline error from Plan 09-04 (the `class.notFound` for this file) is resolved.
- **Inline "Cancel this → save €X/yr" display on /drift.** `DriftPage::render()` resolves a `CancellationImpactQuery` collaborator via method-parameter DI, builds an `impactBySeriesId` map for the rendered alerts, and passes it to the partial. Each row's meta-line appends "Cancel this → save {amount}/yr" via the locale-aware Money formatter — €X,YZ for EUR series, $X.YZ for USD series.
- **Per-series drift threshold override popover editor.** `DriftThresholdEditor` Livewire SFC + `drift-threshold-editor.blade.php` ship the 6-option popover (±1% / ±2% / ±5% / ±10% / ±25% / ±50% + "Use global default"). The editor mounts on /drift grouped-by-series card headers AND on /drift single-alert rows AND on /recurring/series/{id} drill-in. Save delegates to the new `SetDriftThresholdForSeries` Recurring-side Public Action — DriftAlerts retains zero direct writes to `recurring_series`, keeping the `noRecurringSeriesWritesFromDriftAlerts` arch invariant green without an exemption list.
- **SetDriftThresholdForSeries Recurring Public Action.** Constructor DI on `DatabaseManager + Clock`; whitelist `{1, 2, 5, 10, 25, 50}` or null; idempotent same-value no-op; cross-user 404 via WHERE user_id predicate. Registered as a singleton in `RecurringServiceProvider::register()`.
- **/settings 'Default drift alert threshold' field.** `SettingsPage` gains a `public int $driftAlertThresholdPercent` property hydrated from `$user->drift_alert_threshold_percent` on `mount()`, validated `required|integer|in:1,2,5,10,25,50`, persisted via `$user->save()` (the User model already carries the column in `$fillable` + `$casts` with a default of 5 — shipped in Plan 09-02). The Blade view adds a new `<section>` with anchor id="drift-threshold" so the /drift page's existing "Adjust threshold →" deep link lands at the right place.
- **Hybrid snooze revival mechanism.** `RevivedExpiredDriftSnoozesJob` (ShouldQueue) sweeps `drift_alerts WHERE state='snoozed' AND snoozed_until <= now()` and invokes `DriftAlertStateMachine::transition()` with `from_state='snoozed', to_state='open', transition_reason='detector_revived_snooze', actor='detector'`; the state machine's allowed-transitions table already permits this (Plan 09-02 ALLOWED_TRANSITIONS). Scheduled via `routes/console.php` as `drift-alerts.revive-snoozes` hourly. The companion query-time conditional widens the open-state filter on `DriftAlertQuery::openForUser` + `openCountForUser` + `totalOpenAnnualizedImpactForUser` + `groupedBySeriesForUser` so snoozed-but-expired rows surface immediately between sweeps. The state machine remains the sole audit-row writer; the read-side conditional never mutates.
- **DriftDetectionContractTest: all 24 fixtures now pass.** The previously-deferred `snooze-expiry-revival` fixture is no longer skipped; the contract test detects the drift, transitions through `snoozed` (with a past `snoozed_until`), dispatches `RevivedExpiredDriftSnoozesJob`, and asserts the post-revival state + the `detector_revived_snooze` audit transition. The fixture's `expected.alerts` + `expected.transitions` are both exercised end-to-end.
- **25 new Pest cases across 5 new test files:** `CancellationImpactQueryTest` (4 unit cases — EUR primary, USD preservation, cross-user null, missing null), `CancellationImpactDisplayTest` (2 feature cases — EUR inline display, USD primary line), `DriftThresholdOverrideEditorTest` (6 feature cases — mount null, mount 50, save to value, save to null, reject invalid, Public-Action cross-user 404), `GlobalDriftThresholdSettingTest` (6 feature cases — pre-selection, save, invalid validation error, low bound, high bound, integration with DriftEvaluator), `SnoozedAlertRevivalTest` (7 feature cases — sweep flips past, sweep ignores future, sweep no-op on open, query-time conditional surfaces, openCountForUser sums, openCountForUser excludes future, corpus fixture).
- **`php artisan schedule:list` confirms the new entry:** `0 * * * *  drift-alerts.revive-snoozes  Next Due: …`. Mirrors the existing `recurring.detect` (daily) entry shape.
- **Full project test suite green:** 1593 passed (18362 assertions), 6 pre-existing skips. PHPStan level=max strict + larastan-strict-rules + larastan-livewire all green across `Modules/`, `app/`, `bootstrap/app.php`. Pint --test green across every Wave 4 file plus the related tests/contracts file. BoundaryArchTest DriftAlerts invariants (5 cases) stay green; the `noRecurringSeriesWritesFromDriftAlerts` rule never fires because the per-series threshold write lives entirely on the Recurring side.

## Task Commits

Each task was committed atomically:

1. **Task 1: CancellationImpactQuery Public service + inline /drift display + 6 test cases** — `a62ca84` (feat)
2. **Task 2: per-series drift threshold override popover editor + SetDriftThresholdForSeries Public Action + 6 test cases** — `ca18f50` (feat)
3. **Task 3: /settings global drift threshold field + 6 test cases (incl. integration)** — `992f8e2` (feat)
4. **Task 4: hybrid drift-alert snooze revival (hourly job + query-time conditional) + 7 test cases + DriftDetectionContractTest fixture re-enabled** — `0fe8abb` (feat)

## Files Created/Modified

### Public Services + Actions (new)

- `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php` — Phase 10 hand-off; `forSeries(int, User): ?CancellationImpactDto`; reads via RecurringSeriesQuery.
- `Modules/Recurring/Public/Actions/SetDriftThresholdForSeries.php` — Recurring-side write path for the per-series drift threshold override; null-or-whitelist({1,2,5,10,25,50}); idempotent same-value no-op; cross-user 404.

### Internal Livewire SFCs + Blade views (new)

- `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php` — popover Livewire SFC, mount() hydrates from recurring_series; save() delegates to SetDriftThresholdForSeries.
- `Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php` — popover chrome (6 options + Use global default) with Alpine `x-data="{ open: false }"`.

### Internal Jobs (new)

- `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php` — hourly scheduled sweep; flips snoozed → open via DriftAlertStateMachine with `transition_reason='detector_revived_snooze'`.

### Tests (new — 5 files, 25 cases)

- `Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php` — 4 unit cases
- `Modules/DriftAlerts/tests/Feature/CancellationImpactDisplayTest.php` — 2 feature cases
- `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php` — 6 feature cases
- `Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest.php` — 6 feature cases
- `Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php` — 7 feature cases

### Modified

- `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php` — render() resolves CancellationImpactQuery via method-parameter DI; builds impactBySeriesId map for the rendered alerts.
- `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php` — Clock added to constructor DI; openCountForUser + totalOpenAnnualizedImpactForUser + groupedBySeriesForUser + a new scopedOpen() helper apply applyOpenStateFilter (state='open' OR (state='snoozed' AND snoozed_until <= now())); shared materialise() helper extracted.
- `Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php` — passes `cancellationImpact` per alert to the partial; mounts the threshold editor on multi-alert flux:card headers; sets `showThresholdEditor` for single-alert rows.
- `Modules/DriftAlerts/Resources/views/livewire/partials/drift-alert-row.blade.php` — adds inline "Cancel this → save {amount}/yr" meta-line span and optional inline threshold-editor mount for open-tab rows.
- `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php` — singleton bindings for RevivedExpiredDriftSnoozesJob + DriftThresholdEditor; Livewire alias `drift-alerts.drift-threshold-editor`.
- `Modules/Recurring/Providers/RecurringServiceProvider.php` — singleton binding for SetDriftThresholdForSeries.
- `Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php` — mounts `@livewire('drift-alerts.drift-threshold-editor', ...)` adjacent to the existing variance-tolerance popover.
- `Modules/Core/Internal/Http/Livewire/SettingsPage.php` — adds `driftAlertThresholdPercent` property + Validate attribute + mount-hydrate + save-line + messages.
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` — adds a new "Drift alerts" `<section>` with anchor id='drift-threshold'.
- `routes/console.php` — adds `Schedule::call(closure)->name('drift-alerts.revive-snoozes')->hourly()->withoutOverlapping(30)` mirroring the existing `recurring.detect` entry.
- `tests/Contracts/DriftDetectionContractTest.php` — the snooze-expiry-revival fixture switches from `'skip'` to `'revival'`; the new revival branch transitions through snoozed-with-past-timestamp and asserts post-revival state + audit row.

## Decisions Made

(Captured in `key-decisions` above — six load-bearing choices: signed-savings handling, dual mount points for the threshold editor, where the SetDriftThresholdForSeries singleton lives, Schedule::call over Schedule::job, widening every open-state aggregate, and how the contract test exercises the revival fixture without coupling DriftEvaluator to revival.)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree bootstrap (vendor / .env / sqlite / Vite manifest)**

- **Found during:** Task 1 — the worktree's first test run (`vendor/bin/pest CancellationImpact*.php`) failed twice: first because `vendor/` was empty, second because `public/build/manifest.json` was missing (the /drift page extends `layouts.app` which uses `@vite(...)`).
- **Issue:** Same bootstrap issue documented across every prior plan in this worktree. The agent's worktree spins up without `vendor/`, `.env`, `database/database.sqlite`, OR `public/build/manifest.json`.
- **Fix:** `composer install`, `cp .env.example .env`, `touch database/database.sqlite`, `php artisan key:generate --force`, `php artisan migrate --force`, `npm install && npm run build`.
- **Files modified:** None (bootstrap-only; `package-lock.json` is left untracked).
- **Verification:** All Wave 4 feature tests pass against the fully booted Laravel app.
- **Committed in:** No commit — bootstrap noise.

**2. [Rule 3 — Blocking] DriftDetectionContractTest's `'snooze-expiry-revival' => 'skip'` marker contradicted the plan's success criteria**

- **Found during:** Task 4 — the plan's `<success_criteria>` says "all 24 fixture corpus scenarios now pass in DriftDetectionContractTest (including the previously-deferred snooze-expiry-revival)". The contract test's existing `'skip'` marker would have left the fixture inert.
- **Issue:** The plan's existing contract-test branch was inadequate: it short-circuited on `'skip'` and returned without running anything against the fixture. Wave 4 is supposed to light up the fixture.
- **Fix:** Replaced the `'skip'` enum value with `'revival'` and added a new branch in the test runner that transitions the detected drift alert through `snoozed` (with `snoozed_until = 2026-05-19 06:00:00`, well in the past relative to `setTestNow('2026-05-19 12:00:00')`), dispatches `RevivedExpiredDriftSnoozesJob`, then asserts post-revival state + the `detector_revived_snooze` audit transition row.
- **Files modified:** `tests/Contracts/DriftDetectionContractTest.php`
- **Verification:** All 24 fixtures pass (`vendor/bin/pest tests/Contracts/DriftDetectionContractTest.php` reports 24 passed, 58 assertions).
- **Committed in:** `0fe8abb` (Task 4)

**3. [Rule 1 — Bug] Initial cross-user 404 test asserted via the Livewire harness, which swallows action-method exceptions**

- **Found during:** Task 2 — the 6th `DriftThresholdOverrideEditorTest` case ("Public Action raises NotFoundHttpException...") originally invoked the editor's `save(25)` method through the Livewire test harness with an intruder user. The expectation was that the Public Action's `NotFoundHttpException` would propagate up to the test's `try`/`catch`. It did not — the Livewire harness captures action-method exceptions internally and surfaces them as test-component errors, not bubble exceptions.
- **Issue:** The Livewire test API does not bubble `\Symfony\Component\HttpKernel\Exception\NotFoundHttpException` to PHP-level try/catch.
- **Fix:** Switched the cross-user-404 case to the established codebase pattern: invoke the Public Action directly via `$this->app->make(SetDriftThresholdForSeries::class)` and assert via Pest's `expect(fn () => ...)->toThrow(NotFoundHttpException::class)`. This pattern is the canonical one across Phase 8's `EditRecurringSeriesVarianceToleranceTest`, `RejectRecurringSeriesTest`, `SnoozeRecurringSeriesTest`, and DriftAlerts's own `SnoozeDriftAlertTest` cross-user tests.
- **Files modified:** `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php`
- **Verification:** All 6 cases pass (`vendor/bin/pest DriftThresholdOverrideEditorTest.php` reports 6 passed, 13 assertions).
- **Committed in:** `ca18f50` (Task 2)

**4. [Rule 1 — Bug] CancellationImpactDto plan-spec API mismatch (`monthlySavings minor` semantics)**

- **Found during:** Task 1 — the plan's task spec said `monthlyMinor = $series->monthlyEquivalent->minorUnits()` then `Money::ofMinor($monthlyMinor, $currency)`. The Ledger Money VO exposes `toMinor()` not `minorUnits()`; and Money's `currency()` returns a string directly, not an object with `getCurrencyCode()`.
- **Issue:** The plan's pseudo-code reflected an older Money VO API. Using `minorUnits()` or `->currency()->getCurrencyCode()` would have produced a method-not-found fatal at runtime.
- **Fix:** Used the actual Ledger Money VO API (`->toMinor()`, `->currency()`), and additionally took `abs()` of the minor value so the savings DTO is positive-valued (cancellation reduces outflow; the magnitude is what the user keeps). Documented the sign choice in the CancellationImpactQuery class PHPDoc.
- **Files modified:** `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php`
- **Verification:** All 4 unit cases pass and the 2 feature cases verify the inline display renders correctly for both EUR and USD currencies.
- **Committed in:** `a62ca84` (Task 1)

**5. [Rule 1 — Bug] Pint auto-fix on multiple files (fully_qualified_strict_types, ordered_imports, etc.)**

- **Found during:** Task 2 + Task 4 — `vendor/bin/pint --test` flagged formatter drift on the new tests files (Pint's `fully_qualified_strict_types` normalises `\Throwable` ↔ `Throwable` and `ordered_imports` re-sorts the `use` block).
- **Issue:** Initial code passes the test logic but doesn't match Pint's preset.
- **Fix:** Ran `vendor/bin/pint` (without `--test`) on the affected files to apply the formatter fix.
- **Files modified:** `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php`, `Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php`, `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`
- **Verification:** `vendor/bin/pint --test` reports `"result":"passed"` on every Wave 4 file.
- **Committed in:** `ca18f50` + `0fe8abb` (rolled into the respective task commits)

---

**Total deviations:** 5 auto-fixed (1 blocking bootstrap, 1 blocking contract-test re-enable, 1 cross-user-404 pattern correction, 1 Money VO API correction, 1 Pint formatter pass)

**Impact on plan:** Every fix was required for the plan's acceptance criteria to pass. The contract-test re-enable is the only fix that touches the architectural surface; it closes a deliberate Wave-2 deferral (the `'skip'` marker carried a TODO comment saying "covered by Plan 09-05"), so re-enabling is the intended next step rather than scope creep. None of the fixes added unplanned functionality.

## Issues Encountered

- **Worktree bootstrap re-applied.** Same as Plan 09-04: `composer install` + `.env` + `key:generate` + `migrate` + `npm install && npm run build` is required before tests can run. The 09-02 `deferred-items.md` note already documents this as a known parallel-worktree-bootstrap issue.
- **`php artisan schedule:list` initially errored on missing `cache_locks` table.** Running `php artisan cache:table && php artisan migrate` produced the table — but the migration is bootstrap-only (a fresh Laravel-12 install would emit it), so the migration file was deleted before commit and not tracked. `schedule:list` works once cache_locks exists; this is an environmental concern, not a Wave-4 bug.

## User Setup Required

None — no external service configuration touched in this plan.

## Next Phase Readiness

Phase 9 is feature-complete. After this wave:

- **Phase 10 (Forecasting) hand-off is in place.** `CancellationImpactQuery::forSeries` is the documented contract for "what if I cancelled this series?" — Phase 10 can compose it with its forecasting math to surface "Cancel Spotify → next month's bills drop by €X" without re-deriving the math.
- **REC-06 (configurable threshold per series + per user) is complete.** Per-series override editor mounts on /drift + /recurring/series/{id}; global threshold field on /settings. DriftEvaluator's effective-threshold rule (Plan 09-03) was already wired to read both.
- **REC-07 (snoozed alerts cannot be silently missed) is complete.** Hourly RevivedExpiredDriftSnoozesJob flips state and writes the audit transition; query-time conditional surfaces the same set immediately between sweeps.
- **The full /drift surface is feature-complete:** tabs, action chips, snooze popover, dashboard tile, top-nav badge, threshold editor, cancellation projection, revival. The remaining Phase 9 work is in 09-PHASE-PLAN.md's wave 5 (phase-level integration tests + documentation pass).

No blockers from Plan 09-05 itself. The pre-existing Plan 09-04 PHPStan baseline error (`CancellationImpactQuery class.notFound`) is resolved as a side effect of Task 1.

## Self-Check: PASSED

**Verified files exist:**

- `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php` — FOUND
- `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php` — FOUND
- `Modules/DriftAlerts/Resources/views/livewire/drift-threshold-editor.blade.php` — FOUND
- `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php` — FOUND
- `Modules/Recurring/Public/Actions/SetDriftThresholdForSeries.php` — FOUND
- `Modules/DriftAlerts/tests/Unit/CancellationImpactQueryTest.php` — FOUND
- `Modules/DriftAlerts/tests/Feature/CancellationImpactDisplayTest.php` — FOUND
- `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php` — FOUND
- `Modules/DriftAlerts/tests/Feature/GlobalDriftThresholdSettingTest.php` — FOUND
- `Modules/DriftAlerts/tests/Feature/SnoozedAlertRevivalTest.php` — FOUND

**Verified commits exist:**

- `a62ca84` (Task 1) — FOUND
- `ca18f50` (Task 2) — FOUND
- `992f8e2` (Task 3) — FOUND
- `0fe8abb` (Task 4) — FOUND

---
*Phase: 09-subscription-drift-detection-alerts*
*Completed: 2026-05-17*
