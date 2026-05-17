---
phase: 08-recurring-detection-fixed-payments-view
plan: "01"
subsystem: recurring-detection
tags: [recurring, module-skeleton, arch-tests, fixtures, apexcharts]
requires: []
provides:
  - "Modules/Recurring/ bounded module shell"
  - "Public/Internal split scaffolded under Modules/Recurring/"
  - "Wave 0 fixture corpus (11 synthesised + 1 real-export stub)"
  - "MerchantMemoryQuery::forCounterpartiesNormalized batch read API"
  - "Four BoundaryArchTest invariants for Recurring + DetectRecurringSeriesJob facade carve-out"
  - "RecurringDetectionContractTest scaffold (skipped placeholder)"
  - "ApexCharts ^3 on window.ApexCharts via the Vite bundle"
affects:
  - "tests/Contracts/BoundaryArchTest.php (5 new arch invariants)"
  - "tests/Contracts/RecurringDetectionContractTest.php (new)"
  - "tests/Pest.php (per-module TestCase map)"
  - "composer.json (autoload-dev)"
  - "phpunit.xml (Unit/Feature testsuites)"
  - "bootstrap/providers.php (provider registration)"
  - "package.json + package-lock.json (apexcharts ^3)"
  - "vite.config.js (resources/js/app.js input)"
  - "resources/js/app.js (new)"
  - "resources/views/components/apex-chart-smoke.blade.php (new)"
tech-stack:
  added:
    - "apexcharts ^3 (npm devDependency, resolved 3.54.1)"
  patterns:
    - "Per-module Pest.php + TestCase.php bootstrap matching Receipts/Chains shape"
    - "Public/Internal split scaffolded with empty placeholder directories tracked via .gitkeep"
    - "BoundaryArchTest invariants gated by `is_dir()` early-return so they're trivially satisfied while module code is empty"
key-files:
  created:
    - "Modules/Recurring/composer.json"
    - "Modules/Recurring/Providers/RecurringServiceProvider.php"
    - "Modules/Recurring/tests/Pest.php"
    - "Modules/Recurring/tests/TestCase.php"
    - "Modules/Recurring/tests/Unit/Wave0FixtureCorpusTest.php"
    - "Modules/Recurring/tests/fixtures/synthesised/stable-monthly-spotify.php"
    - "Modules/Recurring/tests/fixtures/synthesised/drifting-monthly-spotify.php"
    - "Modules/Recurring/tests/fixtures/synthesised/quarterly-insurance.php"
    - "Modules/Recurring/tests/fixtures/synthesised/yearly-domain.php"
    - "Modules/Recurring/tests/fixtures/synthesised/weekly-streaming.php"
    - "Modules/Recurring/tests/fixtures/synthesised/monthly-salary.php"
    - "Modules/Recurring/tests/fixtures/synthesised/two-employer-salary.php"
    - "Modules/Recurring/tests/fixtures/synthesised/irregular-gym-must-not-cluster.php"
    - "Modules/Recurring/tests/fixtures/synthesised/missing-month-subscription.php"
    - "Modules/Recurring/tests/fixtures/synthesised/mixed-currency-netflix-usd.php"
    - "Modules/Recurring/tests/fixtures/synthesised/variable-amount-beyond-tolerance-bills.php"
    - "Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php"
    - "Modules/Categorization/tests/Unit/MerchantMemoryQueryBatchTest.php"
    - "tests/Contracts/RecurringDetectionContractTest.php"
    - "resources/js/app.js"
    - "resources/views/components/apex-chart-smoke.blade.php"
  modified:
    - "Modules/Categorization/Public/Services/MerchantMemoryQuery.php"
    - "tests/Contracts/BoundaryArchTest.php"
    - "tests/Pest.php"
    - "composer.json"
    - "phpunit.xml"
    - "bootstrap/providers.php"
    - "package.json"
    - "package-lock.json"
    - "vite.config.js"
decisions:
  - "ApexCharts pinned to ^3 (resolved 3.54.1) — npm registry currently lists 5.x as latest, but the plan/research locked ^3; the lower bound is valid and the resolved version is the latest 3.x; future major upgrades are a separate decision."
  - "Container-tag wiring deferred to Wave 1+ — RecurringServiceProvider ships with an empty register() so the empty skeleton boots cleanly; service singletons + the 'recurring.detector' tag bind once the implementations land."
  - "Vite input list extended with resources/js/app.js so the bundle (and its manifest entry) is reachable from Blade @vite directives once any chart component lands."
metrics:
  duration: "~30 min"
  tasks_total: 3
  tasks_completed: 3
  files_created: 22
  files_modified: 9
  tests_added: 14
  commits: 6
  completed: 2026-05-17
---

# Phase 8 Plan 01: Wave 0 — Recurring Module Skeleton + Fixture Corpus + ApexCharts Summary

Stand up an empty-but-bootable `Modules/Recurring/` bounded module, lock the four boundary
invariants that protect later waves, install ApexCharts ^3 as a `window.ApexCharts` global
on the Vite bundle, and drop the 11 synthesised + 1 real-export-stub fixture corpus so the
contract test in Plans 03/04 can become a one-line `pest --filter` smoke test rather than a
setup epic.

## What Shipped

- **`Modules/Recurring/` skeleton** — composer.json, RecurringServiceProvider, per-module
  Pest.php + TestCase.php matching the Receipts/Chains shape. Provider conditionally
  loads migrations, routes, and views so the empty skeleton boots without forcing any
  subtree to exist yet. Provider is registered in `bootstrap/providers.php`; the test
  namespace is wired into root composer.json autoload-dev, phpunit.xml Unit/Feature
  suites, and the per-module TestCase map in `tests/Pest.php`.
- **MerchantMemoryQuery batch read API** — `forCounterpartiesNormalized(User, list<string>)`
  returns a `array<string, MerchantMemoryDto>` map keyed by normalized counterparty name
  in a single SQL query. Scopes by `user_id` on both the merchants JOIN and the
  merchant_memories WHERE clause; empty input list short-circuits to `[]`; empty-string
  entries are skipped; missing names are omitted (no nulled placeholder). The existing
  `latestForCounterpartyNormalized` signature is byte-for-byte unchanged.
- **Four boundary invariants on `tests/Contracts/BoundaryArchTest.php`:**
  - `Modules\Recurring\Internal is only used inside Modules\Recurring` (line 44)
  - `noSynchronousDetectionInRequestLifecycle` — SeriesDetector contract may not be
    used in `Modules\Recurring\Internal\Http` or `Modules\Recurring\Resources` (line 109)
  - `noTransactionWritesFromRecurring` it() block — filesystem grep blocks any
    UPDATE/INSERT/DELETE on the transactions table from module code (line 522)
  - `noOtherRecurringSeriesStateMutator` it() block — only
    `Modules/Recurring/Internal/StateMachines/RecurringSeriesStateMachine.php` may
    UPDATE the state column on recurring_series (line 571)
  - The forward-declared FQN
    `Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob` was added to the existing
    facade carve-out array (line 86) so the Cache::driver('redis') call inside
    `uniqueVia()` stays legal the moment the job file lands in a later wave.
- **`tests/Contracts/RecurringDetectionContractTest.php` scaffold** — single skipped test
  that holds the spot for the end-to-end sweep helper triple (assertSeriesSet /
  assertCadences / assertMetrics) that Plans 03/04 will populate.
- **ApexCharts ^3 (resolved 3.54.1)** — installed as a devDependency, exposed on
  `window.ApexCharts` from `resources/js/app.js`. Vite production build emits a chunk
  that includes the ApexCharts module; `public/build/manifest.json` references the new
  app.js entry alongside the existing app.css.
- **Smoke Blade component `<x-apex-chart-smoke />`** — single-line Alpine x-init canary
  that throws if `window.ApexCharts` is not defined. Lets a future feature test verify
  the bundle without spinning up the full drill-in page.
- **Wave 0 fixture corpus** — 11 synthesised PHP files + 1 anonymised real-export stub
  under `Modules/Recurring/tests/fixtures/`. Each synthesised fixture returns a
  `['transactions' => list<array>, 'expected' => array]` pair with a CanonicalTransaction-
  shaped row set and an `expected` block documenting series count, cadence, direction,
  monthly equivalent, and any drift / fragmentation expectations. Scenarios match the
  CONTEXT D-845 list exactly: stable monthly, drifting monthly (mid-window price bump),
  quarterly insurance, yearly domain, weekly streaming, monthly salary, two-employer
  salary, irregular gym (must not cluster), missing-month subscription, mixed-currency
  Netflix USD (must cluster on USD), variable-amount bills (must fragment).
- **`Wave0FixtureCorpusTest`** — pins the corpus shape: exactly 11 synthesised files,
  top-level `transactions` + `expected` keys, canonical detector key set
  (`posted_at`, `booked_at`, `amount_minor`, `currency`, `counterparty_normalized`,
  `type`) on every row, real-export stub present at the documented path.

## Behavioural Test Coverage Added

14 new tests, all green:

- `Modules\Categorization\tests\Unit\MerchantMemoryQueryBatchTest` — 5 tests covering
  empty input, cross-user isolation, two-name lookup, missing-name absent-key behaviour,
  and empty-string skipping.
- `Modules\Recurring\tests\Unit\Wave0FixtureCorpusTest` — 4 tests pinning corpus shape.
- `Tests\Contracts\RecurringDetectionContractTest` — 1 scaffold test (skipped).
- `Tests\Contracts\BoundaryArchTest` — 4 new arch-style invariants (the existing rule
  array gains one carve-out entry; two new top-level `arch()` rules; two new `it()`
  filesystem-grep tests).

## Verification

| Gate                          | Result |
| ----------------------------- | ------ |
| `composer test` Unit/Feature/Contracts (verify-hook scope) | 1 pre-existing failure, 0 new failures |
| `composer analyse` (Larastan level max + strict + livewire) | OK — no errors |
| `composer format:check` (Pint default Laravel preset) | passed |
| `npm run build` (Vite production build) | exits 0, manifest includes app.js + ApexCharts chunk |
| `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | green |
| `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php` | green (skipped scaffold) |
| `vendor/bin/pest Modules/Recurring/tests/Unit/Wave0FixtureCorpusTest.php` | green |
| `vendor/bin/pest Modules/Categorization/tests/Unit/MerchantMemoryQueryBatchTest.php` | green |
| `grep -c 'RecurringServiceProvider::class' bootstrap/providers.php` | 1 |
| `grep -c 'Modules\\\\Recurring\\\\Tests\\\\' composer.json` | 1 |
| `grep -c 'apexcharts' package.json` | 1 |

Resolved `apexcharts` version (slopcheck `[ASSUMED]` resolution): **3.54.1**
(latest 3.x at install time per `npm view apexcharts@^3 version`). The npm registry
currently lists 5.12.0 as the newest major; this plan honours the locked `^3` pin.

`composer test` includes EmailScanIntegration + OAuth feature suites that have
pre-existing environment-dependent failures (storage/app/inbox permissions, OAuth
provider stubs) unrelated to this plan. The verify-hook scope of
`composer test -- --testsuite=Unit,Feature,Contracts --stop-on-failure` shows
**1 pre-existing failure**
(`Modules\Ledger\tests\Feature\TransactionDetailReclassifyTest > crossUser404`)
that fails on the base commit `41360a0` before any plan-08-01 changes; logged in
`.planning/phases/08-recurring-detection-fixed-payments-view/deferred-items.md`.

## Deviations from Plan

### `[Rule 3 - Blocking issue] Worktree environment bootstrap`

- **Found during:** Pre-task-1 baseline run
- **Issue:** Freshly-spawned worktree had no `vendor/`, no `node_modules`, no
  `database/database.sqlite`, no Vite manifest. Running `composer test` immediately
  failed because every Blade view referencing `@vite` could not resolve a manifest.
- **Fix:** `composer install`, `npm install`, `touch database/database.sqlite`,
  `npm run build`. None of these touched repo state.
- **Files modified:** None (vendor + node_modules + sqlite are gitignored).
- **Commit:** N/A — environment setup.

### `[Rule 1 - Bug] Pest toHaveKey signature mismatch in Wave0FixtureCorpusTest`

- **Found during:** Task 3 (corpus validator run)
- **Issue:** First draft passed a custom error message as the second argument to
  `expect($row)->toHaveKey($key, "Fixture X missing key Y")`. Pest's `toHaveKey`
  second argument is the **expected value** of the key, not a message — the
  assertion was comparing `posted_at`'s string value against the human-readable
  error message and failing on every row.
- **Fix:** Swapped to `expect(array_key_exists(...))->toBeTrue($message)` so the
  message lands in the right argument position. All four corpus tests pass.
- **Files modified:** `Modules/Recurring/tests/Unit/Wave0FixtureCorpusTest.php`
- **Commit:** `8550949` (rolled into the Task 3 atomic commit)

### `[Rule 2 - Critical] D-number reference inside fixture comment`

- **Found during:** End-of-plan self-check grep for GSD/planning-vocabulary leakage
- **Issue:** `Modules/Recurring/tests/fixtures/synthesised/yearly-domain.php` carried
  a `D-803 minimum-2-occurrences floor` comment — a planning-decision identifier
  the codebase-stays-agnostic-from-GSD rule disallows.
- **Fix:** Replaced with a plain-language description of the same rule. Other
  forward-looking `Wave N` / `Plan NN` references in arch test docblocks were
  kept — they mirror the existing convention in `Modules/Chains/Providers/
  ChainsServiceProvider.php` lines 35–66 and stay consistent with the codebase's
  established style.
- **Files modified:** `Modules/Recurring/tests/fixtures/synthesised/yearly-domain.php`
- **Commit:** `e76fcc2`

### Vite input pre-existing only on `resources/css/app.css`

- **Found during:** Task 3 (ApexCharts install)
- **Issue:** `vite.config.js` listed only `resources/css/app.css` as input, with no
  `resources/js/` directory present at all. Plan implicitly assumed a JS pipeline
  was already wired.
- **Fix:** Added `resources/js/app.js` to the Vite `laravel()` plugin input list and
  created the `resources/js/` directory. This is a one-line config delta — not a
  Rule-4 architectural decision because the project already builds via Vite and
  the JS pipeline is the documented pattern (matches the Laravel 12 starter kit
  convention).
- **Files modified:** `vite.config.js`, `resources/js/app.js` (new)
- **Commit:** `8550949`

No Rule-4 architectural deviations.

## Threat Flags

None. The new attack surface (ApexCharts + batch MerchantMemoryQuery + arch tests +
fixture corpus) is fully covered by the plan's threat register:

- T-08-01 supply-chain: mitigated by recording resolved `apexcharts@3.54.1` and the
  resolved `package-lock.json` integrity tree.
- T-08-03 cross-user info disclosure on batch lookup: mitigated by the user_id scope
  on both the merchants JOIN and the merchant_memories WHERE clause; the cross-user
  isolation test in `MerchantMemoryQueryBatchTest` pins this invariant.
- T-08-02 provider boot chain: mitigated — `composer test` post-registration shows
  zero new test failures.
- T-08-04 fixture spoofing: accepted — fixtures return literal arrays and never
  execute side-effect code; `Wave0FixtureCorpusTest` asserts the shape.
- T-08-SC composer/npm install paths: mitigated — only ONE new npm package
  (`apexcharts`) was installed; legitimacy verified via `npm view` (homepage
  `https://apexcharts.com`, repository `https://github.com/apexcharts/apexcharts.js.git`,
  700K+ weekly downloads, 8+ years on registry).

## Known Stubs

- `Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php` returns an empty
  transactions list plus a `'TODO_REAL_FIXTURE' => true` marker in the `expected`
  block. The actual anonymisation work (mining real ASN+ICS exports, scrubbing PII,
  stabilising counterparty IBANs into deterministic tokens) is deferred to a
  phase-close-out task and the plan explicitly accepts this as a Wave 0 stub.
- `tests/Contracts/RecurringDetectionContractTest.php` ships as a single skipped
  test. Plans 03/04 populate the load-bearing
  `assertSeriesSet / assertCadences / assertMetrics` triple alongside the detector
  implementations.

Both stubs are intentional and gated by the plan's `<output>` block which calls
out the real-export anonymisation as a deferred phase-close-out task.

## Self-Check: PASSED

Verified:

- Modules/Recurring/composer.json — FOUND
- Modules/Recurring/Providers/RecurringServiceProvider.php — FOUND
- Modules/Recurring/tests/Pest.php — FOUND
- Modules/Recurring/tests/TestCase.php — FOUND
- Modules/Recurring/tests/Unit/Wave0FixtureCorpusTest.php — FOUND
- Modules/Recurring/tests/fixtures/synthesised/*.php — 11 FILES PRESENT
- Modules/Recurring/tests/fixtures/real/anonymised-asn-ics-6mo.php — FOUND
- Modules/Categorization/tests/Unit/MerchantMemoryQueryBatchTest.php — FOUND
- tests/Contracts/RecurringDetectionContractTest.php — FOUND
- resources/js/app.js — FOUND
- resources/views/components/apex-chart-smoke.blade.php — FOUND
- Commit 95e2d07 (skeleton) — FOUND
- Commit 1a785cb (RED batch test) — FOUND
- Commit d46a2cf (GREEN batch impl) — FOUND
- Commit 82229e4 (arch invariants + contract scaffold) — FOUND
- Commit 8550949 (ApexCharts + fixture corpus) — FOUND
- Commit e76fcc2 (D-number cleanup) — FOUND
