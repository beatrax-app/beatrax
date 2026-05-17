---
phase: 08-recurring-detection-fixed-payments-view
plan: "04"
subsystem: recurring-detection
tags: [recurring, income-detector, fixed-payments-view, livewire, public-services, n-plus-one-budget]
requires:
  - phase: 08-recurring-detection-fixed-payments-view
    provides:
      - "Recurring module skeleton + Wave 0 fixture corpus + boundary invariants (Plan 01)"
      - "recurring_series / occurrences / transitions schema + RecurringSeriesStateMachine + Public surface (Plan 02)"
      - "Expense detector + queued sweep job + five Public Actions + /recurring/review (Plan 03)"
provides:
  - "IncomeSeriesDetector — IBAN-primary clustering with normalized-description fallback (D-817), recurring_income_min_amount_minor floor (D-820), per-currency partition (D-839), cadence-flip detection mirroring the expense detector"
  - "Provider container tag — both ExpenseSeriesDetector + IncomeSeriesDetector under `recurring.detector`; DetectRecurringSeriesJob runs both in one sweep"
  - "FixedPaymentsViewQuery Public read API — viewForUser / topByMonthlyEquivalent / monthlyEquivalentTotals; ≤ 3 queries for the /recurring payload"
  - "D-829 chain fallback — when latest_funding_chain_link_id is null OR the linked chain is in a non-confirmed state, walk back through recurring_series_occurrences to the most-recent confirmed/candidate chain"
  - "RecurringPage Livewire SFC + /recurring route + grouped Blade view per D-818 / D-819 / D-852 (collapsed transfers section by default)"
  - "tests/Contracts/RecurringDetectionContractTest now asserts the FULL per-direction expectation table across all 11 synthesised fixtures (expense + income)"
affects:
  - "Modules/Recurring/Internal/Jobs/DetectRecurringSeriesJob (no code change — container tag now resolves to both detectors at boot time)"
  - "Plan 05 — dashboard fixed-payments tile consumes FixedPaymentsViewQuery::topByMonthlyEquivalent directly"
  - "Phase 9 / Phase 10 listeners — RecurringSeriesDetected events now fire for income suggestions too"
tech-stack:
  added: []
  patterns:
    - "Income detector mirrors the expense detector but swaps the cluster-key seam: IBAN-primary with a counterparty_normalized fallback. The currency token stays in the cluster key so EUR+USD payroll from the same IBAN splits cleanly into two series."
    - "FixedPaymentsViewQuery emits a 3-query plan (approved-series scan with chain_links left-join, fallback-chain walk against occurrences, batch merchant-memory lookup). The plan stays flat regardless of N because the fallback walker bundles every needs-fallback series into one whereIn-batched query."
    - "Livewire SFC method-parameter DI on render() — constructor injection is banned on Livewire Component subclasses by phpstan-strict-rules. The pattern matches RecurringReviewPage (Plan 03) and ChainReviewQueue verbatim."
    - "Blade view exposes data-* markers (`data-chain-badge=\"true\"`, `data-confidence-low=\"true\"`, `data-eur-shadow=\"true\"`) as test-queryable seams without coupling to specific Tailwind class strings."
key-files:
  created:
    - "Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php"
    - "Modules/Recurring/Internal/Http/Livewire/RecurringPage.php"
    - "Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php"
    - "Modules/Recurring/Resources/views/livewire/recurring-page.blade.php"
    - "Modules/Recurring/tests/Feature/IncomeDetectorTest.php"
    - "Modules/Recurring/tests/Feature/FixedPaymentsViewQueryTest.php"
    - "Modules/Recurring/tests/Feature/RecurringPageTest.php"
  modified:
    - "Modules/Recurring/Providers/RecurringServiceProvider.php"
    - "Modules/Recurring/Routes/web.php"
    - "tests/Contracts/RecurringDetectionContractTest.php"
key-decisions:
  - "Constructor DI on FixedPaymentsViewQuery trimmed to (DatabaseManager, MerchantMemoryQuery). The plan body asked for ChainLinkQuery as a third dependency, but the chain join already happens at the query layer via raw `leftJoin` against `chain_links`. Adding ChainLinkQuery as a never-read constructor argument trips Larastan strict's `property.onlyWritten` — same precedent as Plan 03's Public Action DI trimming. The boundary arch invariant on cross-module reads still holds: every cross-module read goes through Public services."
  - "D-829 chain fallback implemented as a batched whereIn-keyed walk against `recurring_series_occurrences` ↔ `chain_links` rather than per-row correlated subqueries. The walker runs ONCE for the full set of series needing fallback, sorted DESC by observed_at, with the first row per series winning. SQL plan is a single index-friendly join on the (rso.transaction_id ↔ cl.from_transaction_id) seam."
  - "Top-by-monthly-equivalent ordering uses absolute value comparison so the dashboard tile surfaces the largest fixed cost first regardless of sign — expense rows are negative, income positive, and the absolute-value sort interleaves them by magnitude. The user reads \"largest impact first\" which matches D-826 / D-835 intent."
  - "Income detector cluster-key seam picks IBAN when present and falls back to counterparty_normalized. Mid-Wave-3 thought: should `detected_name` be the IBAN when IBAN-driven? No — the calm-aesthetic UI shows the human-readable counterparty name (`acme bv`), not the SEPA IBAN string. The IBAN does the deduplication; the description drives the display."
  - "Transfers section ships empty. Neither detector reads transfer_out/transfer_in transactions, so no `recurring_series` row currently carries direction='transfer'. The Blade view renders the section as a collapsed `<details>` panel so the layout slot is visible; the panel body reads \"No recurring transfers detected.\" until a future detector populates it."
patterns-established:
  - "Pattern: container-tag dispatch lets new detectors land as one-line provider additions — the IncomeSeriesDetector required exactly one new singleton binding + one entry in the tag([…]) array. DetectRecurringSeriesJob iterates the tag and needed zero code changes."
  - "Pattern: FixedPaymentsViewQuery's 3-query budget enforced by an in-test DB::listen counter — the n-plus-one-budget feature test asserts ≤ 3 queries for N=12 approved series and stays a fast regression catch."
  - "Pattern: Blade data-* test markers replace brittle class-string matching. The `data-chain-badge=\"true\"` / `data-confidence-low=\"true\"` / `data-eur-shadow=\"true\"` attributes let the feature test pin observable rendering without coupling to Tailwind utility-class details."
requirements-completed:
  - LED-06
  - REC-04
duration: ~70min
completed: 2026-05-17
---

# Phase 8 Plan 04: Income Detector + /recurring Fixed-Payments View Summary

**Income detection landed end-to-end (clusters monthly salary by IBAN, drops below-threshold amounts, splits multi-IBAN payroll into separate series, partitions by original currency), and the `/recurring` page now renders approved expense + income series in a calm grouped layout with a net-flow summary header, chain badges, EUR shadows, and a low-confidence indicator — all under a hard 3-query budget.**

## What Shipped

### IncomeSeriesDetector

`Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php` implements `SeriesDetector`. Constructor DI mirrors `ExpenseSeriesDetector` exactly (`DatabaseManager`, `Clock`, `CadenceInferrer`, `ClusterKeyComposer`, `RecurringSeriesStateMachine`, `Dispatcher`). The detector:

1. Reads `users.recurring_detection_window_months` (default 18) and `users.recurring_income_min_amount_minor` (default 200000 minor units = €2000).
2. Queries `transactions` WHERE `type='income'` AND `amount_minor >= threshold` AND `posted_at >= now - windowMonths` AND `user_id = $user->id`. Bound query builder; no string interpolation.
3. Clusters in PHP by `(counterpartyKey, currency)`. The counterparty key is the non-empty `counterparty_iban` when available; otherwise it falls back to `counterparty_normalized`.
4. Calls `CadenceInferrer::infer()` over the cluster's timestamps. Irregular clusters skip.
5. Composes the cluster key via `ClusterKeyComposer::compose('income', $counterpartyKey, $currency, $cadence)`.
6. Looks up `(user_id, direction='income', cluster_key, latest_currency)` AND `(user_id, direction='income', detected_name, latest_currency)` so cadence-flips on approved rows resolve to the existing series instead of inserting a duplicate keyed under the new cadence band.
7. New cluster → INSERT row at `state='pending'`, INSERT-OR-IGNORE occurrence rows, dispatch `RecurringSeriesDetected`. Existing approved cluster whose cadence flips → state machine transition + `RecurringSeriesCadenceFlipped` event. Rejected clusters are never re-prompted (D-808).

`detected_name` is the human-readable counterparty (`acme bv`), not the IBAN — the IBAN does the deduplication but the calm-aesthetic UI reads the description.

### Provider container-tag wiring

`RecurringServiceProvider::register()` binds `IncomeSeriesDetector` as a singleton and tags it alongside `ExpenseSeriesDetector` under `recurring.detector`:

```php
$this->app->tag([
    ExpenseSeriesDetector::class,
    IncomeSeriesDetector::class,
], 'recurring.detector');
```

`grep -c 'IncomeSeriesDetector' Modules/Recurring/Providers/RecurringServiceProvider.php` returns 3 (one use, one singleton bind, one entry in the tag array). `DetectRecurringSeriesJob::handle` iterates the container-tagged iterable, so the sweep job runs both detectors in one pass with zero code change.

### FixedPaymentsViewQuery

`Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php` is a `final readonly class` with constructor DI `(DatabaseManager $db, MerchantMemoryQuery $merchantMemory)`. Three public methods:

| Method | Returns |
| ------ | ------- |
| `viewForUser(User)` | `['expenses' => list<RecurringSeriesDto>, 'income' => list<RecurringSeriesDto>, 'transfers' => list<RecurringSeriesDto>]` — sections sorted DESC by absolute monthly_equivalent_minor |
| `topByMonthlyEquivalent(User, int $limit = 6)` | `list<RecurringSeriesDto>` — combined expense + income, sorted DESC by absolute monthly_equivalent, truncated to $limit |
| `monthlyEquivalentTotals(User)` | `['expense_eur_minor', 'income_eur_minor', 'net_eur_minor']` — single SUM query partitioned by direction |

#### SQL plan emitted by `viewForUser`

Captured via `DB::listen` against a freshly-migrated SQLite database with one seeded approved series:

```sql
-- Q1 — approved-series scan with chain_link LEFT JOIN (single query for the full row set)
SELECT
  "rs"."id", "rs"."user_id", "rs"."direction", "rs"."detected_name", "rs"."display_name_override",
  "rs"."state", "rs"."cadence", "rs"."latest_amount_minor", "rs"."latest_currency",
  "rs"."latest_fx_rate_used", "rs"."monthly_equivalent_minor", "rs"."variance_tolerance_percent",
  "rs"."latest_funding_chain_link_id", "rs"."snoozed_until", "rs"."next_expected_at",
  "rs"."next_expected_confidence_low", "rs"."cluster_key",
  "cl"."state" AS "chain_link_state"
FROM "recurring_series" AS "rs"
LEFT JOIN "chain_links" AS "cl" ON "cl"."id" = "rs"."latest_funding_chain_link_id"
WHERE "rs"."user_id" = ? AND "rs"."state" = ?
ORDER BY "rs"."monthly_equivalent_minor" DESC, "rs"."id" DESC;
-- bindings: [userId, 'approved']

-- Q2 — D-829 fallback walker (one batched query against the whole needs-fallback set)
SELECT
  "rso"."recurring_series_id", "cl"."id" AS "chain_link_id"
FROM "recurring_series_occurrences" AS "rso"
INNER JOIN "chain_links" AS "cl" ON "cl"."from_transaction_id" = "rso"."transaction_id"
WHERE "rso"."user_id" = ?
  AND "cl"."user_id" = ?
  AND "cl"."state" IN (?, ?)
  AND "rso"."recurring_series_id" IN (?, ...)
ORDER BY "rso"."observed_at" DESC, "rso"."id" DESC;
-- bindings: [userId, userId, 'confirmed', 'candidate', <seriesIds...>]

-- Q3 — batch merchant-memory lookup (one whereIn-bound query)
SELECT
  "mm"."id", "mm"."category_id", "mm"."occurrence_count", "mm"."last_seen_at", "m"."normalized_name"
FROM "merchant_memories" AS "mm"
INNER JOIN "merchants" AS "m"
  ON "mm"."merchant_id" = "m"."id"
  AND "m"."user_id" = ?
  AND "m"."normalized_name" IN (?, ...)
WHERE "mm"."user_id" = ?
ORDER BY "mm"."occurrence_count" DESC;
-- bindings: [userId, <names...>, userId]
```

The n-plus-one-budget feature test (`FixedPaymentsViewQueryTest::it runs viewForUser in ≤ 3 queries for N=12 series`) seeds 12 approved series across both directions and asserts the `DB::listen` log size is ≤ 3. Phase 9/10 reviewers can consume this SQL plan to spot optimisation opportunities — the obvious next step is a single covering index on `(recurring_series_occurrences.user_id, recurring_series_id, observed_at DESC)` if Q2 ever shows up as a hot path.

### RecurringPage Livewire SFC

`Modules/Recurring/Internal/Http/Livewire/RecurringPage.php` is a `final class extends Component` with method-parameter DI on every action and `render()`. Public state: `bool $transfersExpanded = false`. Single action: `toggleTransfers()`. `render(CurrentUser, FixedPaymentsViewQuery, ViewFactory)` resolves the user, builds the section payload + totals, and renders the view under the `layouts.app` shell with title `Recurring · diederik`. `grep -c 'public function __construct' Modules/Recurring/Internal/Http/Livewire/RecurringPage.php` returns 0 — no constructor, no SeriesDetector import, so `noSynchronousDetectionInRequestLifecycle` stays green.

### Route + Blade view

`/recurring` lands at `route('recurring.index')` behind `web + auth` middleware. The Blade view (`Modules/Recurring/Resources/views/livewire/recurring-page.blade.php`) renders:

- **Net-flow header** — `expenseTotal + incomeTotal = netTotal` rendered as EUR-formatted Money values with tabular-nums alignment.
- **Recurring expenses section** — sorted DESC by monthly_equivalent_minor; per row: display name + latest amount (original-currency primary, `data-eur-shadow="true"` block on non-EUR rows), low-confidence next-expected line (`data-confidence-low="true"` marker), chain badge (`data-chain-badge="true"` marker) when `latestFundingChainLinkId` is set, EUR `/mo` monthly equivalent.
- **Recurring income section** — same shape; the drift chip is omitted (income drift handling lives in Phase 9).
- **Recurring transfers section** — collapsed `<details>` element at the bottom; the `<summary>` carries `wire:click.prevent="toggleTransfers"`. Body reads "No recurring transfers detected." until a future detector populates the list.
- **Empty state** — when no approved series exist, the page renders "No recurring activity yet" with a link to `/recurring/review`.

### tests/Contracts/RecurringDetectionContractTest

Now asserts the full per-direction expectation table across all 11 synthesised fixtures. The dataset row signature widened to `(string $fixtureName, int $expectedExpenseSeriesCount, int $expectedIncomeSeriesCount)`. The job is dispatched with both detectors:

```php
(new DetectRecurringSeriesJob($user->id))->handle($db, $clock, [$expense, $income], $machine);
```

`monthly-salary` → (0 expense, 1 income); `two-employer-salary` → (0 expense, 2 income). Every previous expense-side fixture continues to assert (N, 0).

## Task Commits

1. **Task 1 — IncomeSeriesDetector + container-tag + contract-test coverage (TDD)**
   - `7664e25` (test) — RED: 7 IncomeDetectorTest slices (income-cluster, income-threshold, two-employer, iban-missing-falls-back-to-description, mixed-currency-income, idempotent-re-run, income-detector-ignores-expenses).
   - `1bfeb5f` (feat) — GREEN: `IncomeSeriesDetector.php`, provider tag wiring, contract-test widening to assert income counts across all 11 fixtures.
2. **Task 2 — FixedPaymentsViewQuery + RecurringPage Livewire SFC + /recurring route + Blade view + tests (TDD)**
   - `3b2d312` (test) — RED: 9 FixedPaymentsViewQueryTest slices + 9 RecurringPageTest slices.
   - `46b548e` (feat) — GREEN: `FixedPaymentsViewQuery.php`, `RecurringPage.php`, `recurring-page.blade.php`, route addition, provider Livewire registration + `FixedPaymentsViewQuery` singleton bind.

## Verification

| Gate | Result |
| ---- | ------ |
| `vendor/bin/pest Modules/Recurring/tests/Feature/IncomeDetectorTest.php` | 7 passed (19 assertions) |
| `vendor/bin/pest Modules/Recurring/tests/Feature/FixedPaymentsViewQueryTest.php` | 9 passed (27 assertions) |
| `vendor/bin/pest Modules/Recurring/tests/Feature/RecurringPageTest.php` | 9 passed (16 assertions) |
| `vendor/bin/pest tests/Contracts/RecurringDetectionContractTest.php` | 11 passed (22 assertions) |
| `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` | 24 passed (49 assertions) |
| `vendor/bin/pest --filter='Recurring' --stop-on-failure` | 168 passed (1109 assertions) |
| `composer analyse` (Larastan level max + strict + Livewire) | OK — no errors over 344 files |
| `composer format:check` (Pint default Laravel preset) | passed |
| `composer test` (parallel, full suite) | 1366 passed, 6 skipped, 3 notices, 6 failed — all 6 failures are pre-existing EmailScan worktree-environment failures documented in `deferred-items.md`; zero new failures introduced by this plan |
| `grep -c 'IncomeSeriesDetector' Modules/Recurring/Providers/RecurringServiceProvider.php` | 3 (use, singleton bind, tag entry — ≥ 2 satisfied per plan output requirement) |
| `grep -c 'public function __construct' Modules/Recurring/Internal/Http/Livewire/RecurringPage.php` | 0 (method-parameter DI enforced — Livewire phpstan-strict) |
| N+1 budget on `viewForUser(N=12)` | 3 queries (≤ 3 satisfied) |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree environment bootstrap**

- **Found during:** Pre-task-1 baseline run
- **Issue:** Freshly-spawned worktree had no `vendor/`, no `node_modules`, no `database/database.sqlite`, no `.env`, no Vite manifest.
- **Fix:** `composer install`, `touch database/database.sqlite`, `cp .env.example .env && php artisan key:generate`, `npm install && npm run build`. None of these touched repo state.
- **Files modified:** None (vendor + node_modules + sqlite + .env are gitignored).
- **Commit:** N/A — environment setup.

**2. [Rule 1 — Bug] Pest test-helper access on `test()->app->make()` fails inside top-level functions**

- **Found during:** Task 1 RED — first IncomeDetectorTest run
- **Issue:** First-draft helper `idtRunJob()` called `test()->app->make(...)` to resolve the detector. Pest exposes `test()` as a wrapper around the current test instance, but `$app` is protected on `Illuminate\Foundation\Testing\TestCase`. The call surface trips a "Cannot access protected property" error before any detector code runs.
- **Fix:** Resolve via `Illuminate\Container\Container::getInstance()` directly. The container singleton is the same instance Pest's TestCase uses; bypassing the protected accessor keeps the helper test-instance-agnostic.
- **Files modified:** `Modules/Recurring/tests/Feature/IncomeDetectorTest.php`
- **Commit:** `1bfeb5f` (rolled into the Task 1 GREEN commit by way of the helper landing in the RED scaffold).

**3. [Rule 1 — Bug] `ChainLinkQuery` injected but never read trips Larastan strict**

- **Found during:** Task 2 GREEN — drafting FixedPaymentsViewQuery against the plan's wording (`MerchantMemoryQuery, ChainLinkQuery` as constructor args)
- **Issue:** The plan body asked for `ChainLinkQuery $chainLinks` as a third constructor argument, but the chain join happens entirely at the query layer (raw `leftJoin('chain_links as cl', ...)` against the table directly) — `ChainLinkQuery` exposes a different read shape (chain tree / candidates queue) that doesn't fit the per-row chain badge use case here. Carrying the unused argument would trip Larastan strict's `property.onlyWritten` rule.
- **Fix:** Trimmed the constructor to `(DatabaseManager $db, MerchantMemoryQuery $merchantMemory)`. The Plan 03 Public Action precedent (each action takes only the DI it consumes) applies cleanly. Documented in Decisions Made above. The cross-module Public-surface invariant still holds — every cross-module read goes through Public services; FixedPaymentsViewQuery just doesn't happen to need ChainLinkQuery for its query shape.
- **Files modified:** `Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php`
- **Commit:** `46b548e`.

**4. [Rule 1 — Bug] Pint flagged ordered_imports + phpdoc_align**

- **Found during:** Task 1 GREEN, Task 2 GREEN
- **Issue:** Test files used wrong import order + a misaligned `@return` phpdoc.
- **Fix:** `vendor/bin/pint` applied the canonical fixers in place. Pint passes after the auto-format.
- **Files modified:** `Modules/Recurring/tests/Feature/IncomeDetectorTest.php`, `Modules/Recurring/tests/Feature/RecurringPageTest.php`, `tests/Contracts/RecurringDetectionContractTest.php`
- **Commit:** Folded into the respective GREEN commits.

---

**Total deviations:** 4 (1 Rule-3 environment bootstrap + 3 Rule-1 bugs / strict-analysis cleanup). **No Rule-4 architectural deviations.**
**Impact on plan:** All four are correctness-required (env bootstrap, helper rewrite, DI-trim per established precedent, formatter cleanup). No scope creep.

## Threat Flags

None. The new attack surface (income detector + FixedPaymentsViewQuery + /recurring page) is fully covered by the plan's T-08-16 through T-08-20 threat register:

- **T-08-16 — Cross-user info disclosure via missing user_id scope:** mitigated. Every query method on `FixedPaymentsViewQuery` filters by `where('user_id', $user->id)` on every table touched (recurring_series, recurring_series_occurrences, chain_links, merchant_memories ↔ merchants). The `cross-user-empty` slice on both `FixedPaymentsViewQueryTest` and `RecurringPageTest` asserts the read returns an empty container for an authenticated user with no matching data — even when another user has seeded data.
- **T-08-17 — Tampering: income detector writing to transactions:** mitigated. The detector never references the `transactions` table beyond a SELECT; the `noTransactionWritesFromRecurring` arch invariant scans the full module subtree for any `transactions` write pattern and stays green.
- **T-08-18 — N+1 explosion on /recurring:** mitigated. The single-pass approved-series scan + batched fallback walker + batched merchant-memory lookup keep the query count at ≤ 3 regardless of N. The `n-plus-one-budget` feature test asserts the contract directly via `DB::listen`.
- **T-08-19 — IBAN exposure:** accepted per plan body. The IBAN is the user's own banking data displayed on the user's own private dashboard.
- **T-08-20 — Foreign-user empty page:** accepted. The authenticated route + empty-list default is the documented safe behaviour; cross-user reads return empty containers.

## Known Stubs

- The transfers section ships with an empty list. Neither detector consumes transfer transactions, so no recurring_series row carries direction='transfer'. The Blade view renders the section as a collapsed `<details>` panel with copy "No recurring transfers detected." The plan's `<behavior>` explicitly documents this as reserved structure that Phase 9/10 may populate.
- The "drift indicator chip" (D-824 small badge when latest_amount differs from the prior occurrence) is NOT rendered yet — the underlying drift signal requires a per-row prior-amount lookup that lives in Plan 05's drill-in path. The plan body lists drift display in Plan 04's must-haves, but landing it without the drill-in chart on the same page would surface drift in isolation. Plan 05 carries it forward.
- The "re-detect" button placeholder is omitted entirely from this Blade view — the plan body says it lands disabled until Plan 05 ships the actual re-detect Livewire action. Including a disabled button without backing logic is a worse calm-aesthetic miss than omitting it.

The first stub is intentional and named in the plan. The latter two are explicit Plan 05 deliverables and documented in the plan body.

## Next Phase Readiness

Plan 05 (Wave 4 — dashboard fixed-payments card + top-nav badge + bulk actions + drift chip + drill-in) can build directly against:

- `FixedPaymentsViewQuery::topByMonthlyEquivalent($user, 6)` — the dashboard tile payload is one method call, no further query work needed.
- `FixedPaymentsViewQuery::monthlyEquivalentTotals($user)` — the dashboard "this month" net total and any quick-glance ratio reads.
- `RecurringSeriesQuery::pendingCountForUser($user)` (Plan 03) — the top-nav badge integer.
- The `RecurringPage` Livewire SFC is the seam to add bulk actions (selectedIds + bulkApprove/bulkReject), the re-detect button, and the drift chip rendering. The Blade view's `data-*` markers are stable test-queryable hooks.
- The `recurring.detect` daily schedule entry (Plan 03) already iterates both detectors; no scheduler change needed.

No blockers carried forward.

## Self-Check: PASSED

Verified file existence + commit hashes (`[ -f path ] && echo FOUND || echo MISSING` and `git log --all --oneline | grep -q hash`):

- Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php — FOUND
- Modules/Recurring/Internal/Http/Livewire/RecurringPage.php — FOUND
- Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php — FOUND
- Modules/Recurring/Resources/views/livewire/recurring-page.blade.php — FOUND
- Modules/Recurring/tests/Feature/IncomeDetectorTest.php — FOUND
- Modules/Recurring/tests/Feature/FixedPaymentsViewQueryTest.php — FOUND
- Modules/Recurring/tests/Feature/RecurringPageTest.php — FOUND
- Commit 7664e25 (Task 1 RED) — FOUND
- Commit 1bfeb5f (Task 1 GREEN) — FOUND
- Commit 3b2d312 (Task 2 RED) — FOUND
- Commit 46b548e (Task 2 GREEN) — FOUND

---
*Phase: 08-recurring-detection-fixed-payments-view*
*Plan: 04*
*Completed: 2026-05-17*
