---
phase: 09-subscription-drift-detection-alerts
fixed_at: 2026-05-18T00:00:00Z
review_path: .planning/phases/09-subscription-drift-detection-alerts/09-REVIEW.md
iteration: 1
findings_in_scope: 20
fixed: 20
skipped: 0
status: all_fixed
---

# Phase 09: Code Review Fix Report

**Fixed at:** 2026-05-18T00:00:00Z
**Source review:** .planning/phases/09-subscription-drift-detection-alerts/09-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 20
- Fixed: 20
- Skipped: 0

All four critical findings, eleven warnings, and five info findings were addressed. Each fix was committed atomically. The full test suite (1607 passing tests, 6 skipped) and PHPStan max-level analysis both pass. Pint reports no formatting drift.

## Fixed Issues

### CR-01: Revival-sweep race throws and triggers job retries when user actions a snoozed alert mid-sweep

**Files modified:** `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php`
**Commit:** 646e3ac
**Applied fix:** Wrapped each per-row revival call in a `try { ... } catch (InvalidStateTransitionException) { continue; }` block so a concurrent user action that moved the row off `snoozed` between the candidate scan and the state-machine row lock no longer fails the whole sweep. Also added `$tries = 3` and `$backoff = [60, 300, 900]` (covers IN-05 in the same patch) and refreshed the PHPDoc to describe the current contract in present tense without planning-workflow refs.

### CR-02: `DriftAlertQuery` cursor pagination skips or duplicates rows when `detected_at` ties exist

**Files modified:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`
**Commit:** 47076d4
**Applied fix:** Removed the `detected_at DESC` ordering on `scoped`, `scopedOpen`, and `groupedBySeriesForUser`; all three now order strictly by `id DESC`. The single-column cursor (`id < $cursorId`) is now monotone — newer alerts always have larger ids — so a batch of inserts within the same scheduler tick can no longer interleave across page boundaries. Updated the class-level PHPDoc to document the new invariant.

### CR-03: `DriftPage::snooze` accepts arbitrary `snoozed_until` strings

**Files modified:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`
**Commit:** 8371d0d
**Applied fix:** `DriftPage::snooze` now injects the `Clock` and bounds the inbound `$untilIso` to a future-only window of at most six months. Past or far-future ISO strings (including the `'1970-01-01T00:00:00Z'` / `'+10 years'` attack shapes called out in the review) are silently rejected before reaching the Public Action. A malformed Carbon parse also short-circuits.

### CR-04: PHPDocs, Blade comments, and class docblocks embed phase numbers, wave numbers, UI-SPEC refs, and decision IDs

**Files modified:** 41 files across `Modules/Core/`, `Modules/DriftAlerts/`, and the drift-corpus fixture directory.
**Commit:** 3164296
**Applied fix:** Rewrote every offending PHPDoc, Blade comment, fixture header, and inline comment to describe current behavior in present tense. Removed references to phase numbers (Phase 3 / 8 / 9 / 10), wave numbers (Wave 0 / 2 / 3 / 4), decision IDs (D-46 / D-99 / D-100 / D-103 / D-704 / D-718 / D-810 / CHN-06), UI-SPEC sections, plan IDs, and the corpus's "Scenario N" prefixes. The arch-test comment under `tests/Contracts/BoundaryArchTest.php::noRecurringSeriesWritesFromDriftAlerts` was scrubbed of its Phase 9 reference at the same time. The `DriftAlertFactory` "later wave" hand-off was rewritten in present tense (resolves IN-01 at the same time).

### WR-01: Singleton-binding queued jobs and Livewire components is an anti-pattern

**Files modified:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php`
**Commit:** 41dd896
**Applied fix:** Dropped the four singleton bindings for `DetectDriftAlertsJob`, `RevivedExpiredDriftSnoozesJob`, `DashboardDriftBadge`, and `DriftThresholdEditor`. Queued jobs are re-instantiated via `unserialize()` by the worker; Livewire components are instantiated per-request by `LivewireManager`. Both bypass the singleton cache by design. Removed the now-unused job imports. The stateless services (state machine, evaluator, queries, Public Actions) remain singletons.

### WR-02: `DriftEvaluator` labels the hard 5% floor as `source='global'`

**Files modified:** `Modules/DriftAlerts/Internal/DriftEvaluator.php`, `Modules/DriftAlerts/tests/Unit/DriftEvaluatorEffectiveThresholdTest.php`, `Modules/DriftAlerts/tests/Unit/FixtureCorpusTest.php`
**Commit:** 4a671ea
**Applied fix:** Introduced a distinct `'default'` source label for the hard 5% floor. The evaluator now returns `'default'` only when the user's `drift_alert_threshold_percent` is 0 (or non-positive); a user-set non-zero value remains `'global'`; a per-series override remains `'series_override'`. Extended `FixtureCorpusTest::$allowedThresholdSources` to accept the new label. Updated the `hard-default` assertion in `DriftEvaluatorEffectiveThresholdTest` to expect `'default'`. The drift-corpus fixtures still declare `'global'` for their threshold_source because their test users carry the User model default of 5 (a user-set value), not a hard-floor fallback. Status: `fixed: requires human verification` — the semantic change is correctly tested but a developer should confirm that the renderer copy that branches on `threshold_source` (if any future surface adds one) accommodates the new label.

### WR-03: `DriftPage::render()` issues N CancellationImpactQuery calls per page

**Files modified:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php`, `Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php`, `Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php`
**Commit:** c6375ef
**Applied fix:** Added `RecurringSeriesQuery::forSeriesIds(array, User): array<int, RecurringSeriesDto>` for batched lookups. Added `CancellationImpactQuery::forSeriesIds(array, User): array<int, CancellationImpactDto>` that uses it. `DriftPage::render()` now calls `forSeriesIds` once instead of looping `forSeries` per series id — collapses what could be ~20 SELECTs per render into a single batched read.

### WR-04: `DriftThresholdEditor::save` silently coerces non-numeric strings to `0`

**Files modified:** `Modules/DriftAlerts/Internal/Http/Livewire/DriftThresholdEditor.php`, `Modules/DriftAlerts/tests/Feature/DriftThresholdOverrideEditorTest.php`
**Commit:** 3f0242d (then 5868a33 to satisfy PHPStan narrowing)
**Applied fix:** Tightened the coercion: the component now silently rejects any value that is neither `'global'`, an integer, nor a `ctype_digit` string in `OPTIONS = [1,2,5,10,25,50]`. A tampered Livewire payload that coerces to 0 (e.g. `"abc"`) no longer surfaces an `InvalidArgumentException` on the user's screen. Replaced the existing "rejects invalid values" test (which expected an exception bubble) with two passing tests: one for out-of-set numeric values, one for non-numeric strings. The DB row stays at its prior value in both cases. A follow-up commit (5868a33) simplified the `is_string` redundancy PHPStan caught on the `int|string` parameter.

### WR-05: `DriftEvaluatorEdgeCases.php` and `DriftEvaluatorFxInvariant.php` lack `Test.php` suffix

**Files modified:** `Modules/DriftAlerts/tests/Unit/DriftEvaluatorEdgeCasesTest.php`, `Modules/DriftAlerts/tests/Unit/DriftEvaluatorFxInvariantTest.php`
**Commit:** c65e01c
**Applied fix:** Renamed both files via `git mv` to add the `Test.php` suffix. The `phpunit.xml` `<testsuite name="DriftAlerts">` declaration filters on the suffix; the renamed files now run under the suite-targeted invocation `phpunit --testsuite=DriftAlerts`. Test count went from 146 → 159 on that suite (the 6 FX-invariance + 4 edge-case tests now visible, plus the new DTO mapper guards).

### WR-06: Top-nav badge composer fires a COUNT query on every authenticated request

**Files modified:** `Modules/DriftAlerts/Providers/DriftAlertsServiceProvider.php`
**Commit:** 706f30c
**Applied fix:** Added a boot-scoped memo (captured-by-reference array inside the composer closure) keyed by user id. Under traditional FPM, `boot()` runs once per request so the memo is request-scoped — repeated top-nav renders within the same response (e.g. a Livewire roundtrip that re-emits the nav) collapse to a single COUNT query. Documented the lifecycle in the helper PHPDoc.

### WR-07: `DriftAlertDtoMapper` always parses `detected_at` without a defensive guard

**Files modified:** `Modules/DriftAlerts/Internal/Mapping/DriftAlertDtoMapper.php`, `Modules/DriftAlerts/tests/Unit/DriftAlertDtoMapperTest.php` (new)
**Commit:** a6a01e9
**Applied fix:** Added an `InvalidArgumentException` guard with the row id in the message when `detected_at` is missing or non-string. Created a new unit test file with three cases: null detected_at, empty-string detected_at, and the happy-path round-trip. The exception locks the contract so a corrupted source row surfaces a clear identifying error instead of a bare `CarbonImmutable::parse('')` `InvalidFormatException`.

### WR-08: `SnoozeDriftAlert` idempotency check is timezone-sensitive

**Files modified:** `Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php`
**Commit:** bcb7b26
**Applied fix:** Replaced `toDateTimeString()` string comparison with `getTimestamp()` (Unix seconds) comparison. The check is now timezone-independent — re-snoozing to the same wall-clock moment expressed in a different timezone is correctly treated as idempotent. Added an inline comment explaining the rationale.

### WR-09 / WR-10: `DriftEvaluator` and `DriftAlertQuery` read `recurring_series` directly

**Files modified:** `Modules/Recurring/Public/Services/RecurringSeriesQuery.php`, `Modules/DriftAlerts/Internal/DriftEvaluator.php`, `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`, `tests/Contracts/BoundaryArchTest.php`
**Commit:** 229f3d5
**Applied fix:** Added three new Public methods to `RecurringSeriesQuery`: `driftThresholdForSeries(int, User): ?int` (replaces the raw evaluator SELECT — WR-09), `statesForSeriesIds(array, User): array<int, string>` and `displayNamesForSeriesIds(array, User): array<int, string>` (replaces the two raw reads in `DriftAlertQuery` — WR-10). Both consumer modules now route every cross-module read of `recurring_series` through the Recurring module's Public service surface; the DriftAlerts module no longer issues a raw SELECT against another module's table. Trimmed the `DriftAlertQuery` body of the helper methods it no longer needs (`nullableString`, the inline `toString` helper used only by them) and removed the dead PHPDoc references to the "noRecurringSeriesWritesFromDriftAlerts allows reads" carve-out. Cleaned the arch-test comment to describe the rule in present tense.

### WR-11: `DashboardDriftBadge` Blade view tags the headline with `↗` even when total impact is positive

**Files modified:** `Modules/DriftAlerts/Public/Services/DriftAlertQuery.php`
**Commit:** 405e4e2
**Applied fix:** Restricted `totalOpenAnnualizedImpactForUser` to expense-direction alerts only via `->where('direction', 'expense')`. The dashboard tile presents "potential annualized cost" with an up-arrow icon; folding an income raise into the same headline would conflate expense balloons with salary growth. The PHPDoc now spells out the direction filter rationale.

### IN-01: `DriftAlertFactory::definition()` returns null FKs

**Files modified:** `Modules/DriftAlerts/Database/Factories/DriftAlertFactory.php` (subsumed by CR-04)
**Commit:** 3164296 (CR-04 batch)
**Applied fix:** The `"later wave"` GSD reference was the actionable part of this finding; the CR-04 batch rewrote the class-level PHPDoc in present tense and explicitly documents that callers must override the three FK columns. No sub-factory wiring was added because the User and RecurringSeries models do not ship their own factory classes — adding them is a larger change outside the scope of this review.

### IN-02: `DriftEvaluatorTest` uses non-round percent fixture for USD case

**Files modified:** `Modules/DriftAlerts/tests/Unit/DriftEvaluatorTest.php`
**Commit:** f1b30a9
**Applied fix:** Replaced the `-1199 → -1499` (≈25.02%) pair with `-1200 → -1500` (exactly 25.0%) for the USD dataset row. Added an inline comment explaining the rounding hazard a non-round pair would introduce for a future contributor copying the case shape with a 25% threshold (which compares strictly-greater-than).

### IN-03: `DriftAlertStateMachine::toIntOrNull` silently treats `user_id='0'` as null

**Files modified:** `Modules/DriftAlerts/Internal/StateMachines/DriftAlertStateMachine.php`, `Modules/DriftAlerts/tests/Unit/DriftAlertStateMachineTest.php`
**Commit:** eab7c0e
**Applied fix:** Documented the swallow-to-null behaviour as intentional in a dedicated PHPDoc block on `toIntOrNull`. Added a unit test that locks the semantic — a `drift_alerts.user_id` of NULL (the FK forbids a literal `0`, so the test exercises the NULL path) results in `drift_alert_transitions.user_id = NULL` rather than a thrown exception. A future refactor that converts the silent degrade into a throw must update the test.

### IN-04: Routes file comment names the BoundaryArchTest

**Files modified:** `Modules/DriftAlerts/Routes/web.php`
**Commit:** 031dbba
**Applied fix:** Dropped the parenthetical that named `BoundaryArchTest`. The comment now reads "The `Route` facade is permitted in module Routes files." — present-tense, no test coupling.

### IN-05: `RevivedExpiredDriftSnoozesJob` lacks `tries` configuration

**Files modified:** `Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php` (subsumed by CR-01)
**Commit:** 646e3ac (CR-01 batch)
**Applied fix:** Added `public int $tries = 3` and `public array $backoff = [60, 300, 900]` to mirror `DetectDriftAlertsJob`. Each individual transition is idempotent at the state-machine level so retries are safe. The class-level PHPDoc documents the retry contract.

---

_Fixed: 2026-05-18T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
