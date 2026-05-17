---
phase: 8
slug: recurring-detection-fixed-payments-view
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-17
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Derived from `08-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.0 (on PHPUnit 11) + pest-plugin-arch 4.0 + pest-plugin-snapshots 2.0 |
| **Config file** | `phpunit.xml` (root) + `Modules/Recurring/tests/Pest.php` (inert) + root `tests/Pest.php` (load-bearing TestCase map) |
| **Quick run command** | `vendor/bin/pest --filter='Recurring' --parallel` |
| **Full suite command** | `composer test` (= `pest --parallel`) |
| **Estimated runtime** | ~5–10s (quick) · ~60s (full at current scale) |
| **Static analysis** | `composer analyse` (PHPStan level max + larastan-strict + larastan-livewire) |
| **Code style** | `composer format:check` (Pint, Laravel preset) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest --filter='Recurring' --parallel`
- **After every plan wave:** Run `composer test`
- **Before `/gsd:verify-work`:** `composer test && composer analyse && composer format:check` all green
- **Max feedback latency:** ~10 seconds (quick), 60 seconds (full)

---

## Per-Task Verification Map

> Task IDs are assigned during planning. This table is the requirement → automated-test mapping the planner must honour when emitting each task's `<automated>` block.

| Req ID | Behavior | Wave | Test Type | Automated Command | File Exists |
|--------|----------|------|-----------|-------------------|-------------|
| REC-01 | `CadenceInferrer` classifies weekly/monthly/quarterly/yearly/irregular from interval list (Pest dataset, 15–20 rows) | 0/2 | unit | `vendor/bin/pest Modules/Recurring/tests/Unit/CadenceInferenceTest.php` | ❌ W0 |
| REC-01 | `ExpenseSeriesDetector` clusters synthetic Spotify trio into ONE series | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='expense-cluster'` | ❌ W2 |
| REC-02 | Variance tolerance 25%: €9.99 + €11.49 cluster as one series | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='variance-tolerance'` | ❌ W2 |
| REC-02 | Variance tolerance violation: €9.99 + €20.00 fragment into two series | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='variance-exceeded'` | ❌ W2 |
| REC-03 | Suggestion appears in `/recurring/review` and NOT in `/recurring` until Approve | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringReviewPageTest.php --filter='suggest-not-applied'` | ❌ W2 |
| REC-03 | Approve flips state to `approved` AND writes a `recurring_series_transitions` row | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/ApproveRecurringSeriesTest.php` | ❌ W2 |
| REC-03 | Bulk Approve action approves N series in one click | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringReviewPageBulkActionsTest.php --filter='bulk-approve'` | ❌ W4 |
| REC-03 | Reject → un-reject → reappears in pending tab | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/UnRejectRecurringSeriesTest.php` | ❌ W2 |
| REC-03 | Cross-user 404 on Public Actions Approve / Reject / Snooze / EditName / UnReject | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/CrossUserRecurringSeriesIsolationTest.php` | ❌ W2 |
| REC-03 | Cross-user 404 on EditVarianceTolerance Public Action | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/EditRecurringSeriesVarianceToleranceTest.php --filter='cross-user-404'` | ❌ W4 |
| REC-04 | `/recurring` lists approved expense + income series with name + monthly equivalent + funding icon + next-expected date | 3 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringPageTest.php` | ❌ W3 |
| REC-04 | Edit-name override persists across a re-detect | 2 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/EditRecurringSeriesNameTest.php --filter='persists-across-sweep'` | ❌ W2 |
| REC-04 | Funding icon falls back to previous occurrence's chain when newest is unresolved | 3 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringPageTest.php --filter='chain-fallback'` | ❌ W3 |
| REC-04 | Dashboard "Fixed monthly payments" card renders top 6 + View-all link | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/FixedPaymentsCardTest.php` | ❌ W4 |
| REC-04 | Per-series `variance_tolerance_percent` dropdown editor persists user choice on drill-in (D-825 / D-854) | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/EditRecurringSeriesVarianceToleranceTest.php` | ❌ W4 |
| REC-05 | `/recurring/series/{id}` lists every occurrence with date + amount + transaction link | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php` | ❌ W4 |
| REC-05 | Drill-in chart receives well-formed dataset (amount-over-time JSON) | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php --filter='chart-dataset'` | ❌ W4 |
| LED-06 | `IncomeSeriesDetector` clusters salary-IBAN trio into ONE income series with `direction='income'` | 3 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='income-cluster'` | ❌ W3 |
| LED-06 | Income below `recurring_income_min_amount_minor` does NOT cluster | 3 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='income-threshold'` | ❌ W3 |
| LED-06 | Two-employer salary clusters into two distinct series | 3 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/DetectRecurringSeriesJobTest.php --filter='two-employer'` | ❌ W3 |
| (settings) | `/settings` exposes `recurring_detection_window_months` + `recurring_income_min_amount_minor` numeric inputs that validate boundaries and persist to the users row (D-802 / D-820) | 1 | feature | `vendor/bin/pest Modules/Core/tests/Feature/SettingsRecurringFieldsTest.php` | ❌ W1 |
| UI-03 | Drill-in route resolves + renders for the series owner; 404s for other users | 4 | feature | `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringSeriesDetailPageTest.php --filter='cross-user-404'` | ❌ W4 |
| (arch) | `noTransactionWritesFromRecurring` | 0 | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noTransactionWritesFromRecurring'` | ❌ W0 |
| (arch) | `no Laravel facade usage` covers `Modules\Recurring` (with `DetectRecurringSeriesJob` carve-out) | 0 | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='no Laravel facade usage'` | ❌ W0 (extend) |
| (arch) | `crossModuleAccessGoesThroughPublic` for `Modules\Recurring\Internal` → other `Internal` | 0 | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='Modules\\Recurring\\Internal'` | ❌ W0 |
| (arch) | `noSynchronousDetectionInRequestLifecycle` | 0 | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noSynchronousDetectionInRequestLifecycle'` | ❌ W0 |
| (arch) | `noOtherRecurringSeriesStateMutator` (only the state machine writes `recurring_series.state`) | 1 | contracts | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php --filter='noOtherRecurringSeriesStateMutator'` | ❌ W1 |
| (contract) | End-to-end sweep over Wave 0 fixture corpus produces expected series / states / cadences / metrics | 0+ | contracts | `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php` | ❌ W0 (populated W2/W3) |
| (idempotency) | Running the sweep twice produces the same series set | 2 | contracts | `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php --filter='idempotent-re-run'` | ❌ W2 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `Modules/Recurring/composer.json` — new module manifest (mirrors `Modules/Chains/composer.json`)
- [ ] (intentionally omitted) `Modules/Recurring/module.json` — Phase 4+ modules do not ship `module.json` (verified: `Modules/Chains/` and `Modules/Receipts/` have none). Plan 01 Task 1 documents this convention.
- [ ] `Modules/Recurring/Providers/RecurringServiceProvider.php` — registers detectors (tag `'recurring.detector'`), state-machine singleton, Public Action singletons, loads migrations + routes, registers Livewire components, registers View composers for top-nav badge + dashboard card
- [ ] `Modules/Recurring/tests/Pest.php` + `Modules/Recurring/tests/TestCase.php` — inert per project convention
- [ ] `tests/Pest.php` (root) — add `'Modules/Recurring' => Modules\Recurring\Tests\TestCase::class` to the foreach map (Phase 4 D-80b 3-step pattern)
- [ ] `phpunit.xml` — register `Modules/Recurring/tests/Unit` + `Modules/Recurring/tests/Feature` in existing testsuites; new `RecurringContracts` testsuite for `Modules/Recurring/tests/Contracts/` if needed
- [ ] `composer.json` autoload-dev — add `"Modules\\Recurring\\Tests\\": "Modules/Recurring/tests/"`
- [ ] `tests/Contracts/BoundaryArchTest.php` — add the four new invariants (D-833) + `noOtherRecurringSeriesStateMutator` + the `DetectRecurringSeriesJob` facade carve-out in the existing `ignoring([...])` array
- [ ] `tests/Contracts/RecurringDetectionContractTest.php` — new contract test scaffolded with the Wave 0 fixture corpus
- [ ] `Modules/Recurring/tests/fixtures/synthesised/` — 12-fixture corpus per D-845 (each as a PHP factory returning a list of `CanonicalTransaction` rows)
- [ ] `Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php` — anonymised real export covering ≥6 months
- [ ] `Modules/Categorization/Public/Services/MerchantMemoryQuery.php` — extend with a batch method (`forCounterpartiesNormalized(User, list<string>): array<string, MerchantMemoryDto>`) for efficient `/recurring` row decoration (D-834)
- [ ] **ApexCharts decision + install** — `npm install apexcharts` and Vite wire-up in `resources/js/app.js` (CONTEXT.md `### Drift Display + Variance Tolerance` D-827 locks ApexCharts; researcher verified it is NOT installed). **Wave 4 chart cannot ship without this.**
- [ ] Framework install: none — every PHP test dependency is already present

*Once all items are checked, set `wave_0_complete: true` in frontmatter.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual review of drift-indicator chip on `/recurring` (icon + colour + alignment with the amount column) | REC-04 / UI-03 | Visual polish — Pest can assert the chip renders but not that it looks calm | After Wave 4 ships: open `/recurring` in browser with the seeded fixture, screenshot, compare against Linear/Notion calm aesthetic |
| Drill-in ApexCharts hover + tooltip behaviour (interactive only) | REC-05 / UI-03 | ApexCharts hover state is JS-runtime and not asserted by Livewire feature tests | After Wave 4: open `/recurring/series/{id}` in browser, hover over data points, verify tooltip shows date + amount in original currency + EUR shadow |
| Dashboard "Fixed monthly payments" card visual integration with the existing dashboard layout | REC-04 | Visual integration — Pest asserts the card renders but not how it sits in the dashboard grid | After Wave 4: open `/` and check the card placement, top-6 ordering, View-all link |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
