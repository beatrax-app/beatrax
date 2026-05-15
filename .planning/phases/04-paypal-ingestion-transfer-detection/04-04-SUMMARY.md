---
phase: 04-paypal-ingestion-transfer-detection
plan: 04
subsystem: ledger
tags: [reclassify, income-detection, dashboard, led-05, ui]

# Dependency graph
requires:
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 0 TransactionImported event + RecordTransactions sync dispatch (04-01)
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 1 three-issuer wizard + reconciliation soft-warning panel patterns (04-02)
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 2 pair_transaction_id self-FK + ClassifyTransactionType pipeline stage + PairTransferCandidates listener (04-03)
  - phase: 03-ics-cards-multi-currency-display
    provides: TransactionDetail Livewire class-as-handler route + cross-user 404 pattern (03-07)
provides:
  - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php — reclassifyType public property + reclassify(string, CurrentUser, DatabaseManager) action implementing the D-78 atomic break-pair invariant
  - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php — `<section>` Reclassify control with wire:model.live select + wire:click Save button + Alpine x-show/x-text toast region listening for the component's `toast` event
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php — `for()` and `forByCurrency()` rollups now filter by `transactions.type` (income / expense) instead of by amount sign
  - Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php — 7 feature tests (changesType / breaksPair / preservesPairOnTransferToTransfer / crossUser404 / rejectsInvalidType / emptyTypeIsNoOp / rendersReclassifyDropdownOnDetailPage)
  - Modules/Ledger/tests/Feature/DashboardIncomeTest.php — 3 regression tests (excludesTransfers / includesIncome / expenseTileExcludesTransfers)
affects: [04-05, 05-*, 08-*]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Reclassify-action atomic break-pair invariant (D-78): the action method captures `$tx->pair_transaction_id` BEFORE save() so the partner-id is available after `$tx->pair_transaction_id = null`. The break is wrapped in `$db->connection()->transaction()` so the two writes (this row + partner row) commit or roll back together; SQLite WAL single-writer serialisation makes the operation race-free in practice. Transfer-to-transfer reclassifies skip the unpair branch entirely so the listener's pair survives a no-op type swap."
    - "Toast dispatch convention: `$this->dispatch('toast', message: $message)` — named-parameter payload shape, listened to via Alpine's `x-on:toast.window` with `$event.detail?.message`. No global toast-renderer layer needed; the Blade view embeds a small inline `<span x-show=\"toast\" x-text=\"toast\">` region next to the Save button so the confirmation surfaces within the user's eye-line. Phase 5's review-queue UX inherits this shape verbatim."
    - "Cross-user safety at two layers: mount() raises NotFoundHttpException via the raw Query Builder exists() guard; reclassify() raises ModelNotFoundException via Eloquent firstOrFail() on a user_id-scoped query. The HTTP route test exercises the mount() guard; the Livewire::test action test exercises the firstOrFail() guard. The Livewire test harness wraps mount() throwables as InvalidArgumentException at snapshot-serialization time — assertion is widened to `Exception::class` plus a defence-in-depth check that the row stays untouched, mirroring the runtime invariant we actually care about (the cross-user write cannot land)."
    - "Dashboard rollup type-filter: `SUM(CASE WHEN type = 'income' THEN settled_amount_minor ELSE 0 END)` (vs the previous amount-sign-keyed `SUM(CASE WHEN settled_amount_minor > 0 ...)`). The same shape applies to both `for()` (single display currency) and `forByCurrency()` (multi-currency tile rows + their HAVING filter). D-77's subtractive income rule lives in the SQL — refunds, transfers, fees, adjustments all stay out of both tiles."

key-files:
  created:
    - Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php
    - Modules/Ledger/tests/Feature/DashboardIncomeTest.php
  modified:
    - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php (added `reclassifyType` property + `reclassify()` action + `InvalidArgumentException` import)
    - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php (added `<section aria-labelledby="reclassify-heading">` block with select + Save button + Alpine toast region)
    - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (both `for()` and `forByCurrency()` rollups now filter by `transactions.type` instead of by amount sign)

key-decisions:
  - "ThisPeriodAtAGlanceQuery WAS the Option B regression case the plan anticipated. Diagnostic read at execution start revealed both `for()` and `forByCurrency()` summed by amount sign (`settled_amount_minor > 0` ⇒ inflow, `settled_amount_minor < 0` ⇒ outflow). Wave 3 narrows both rollups to filter by `transactions.type`: inflow = income, outflow = expense. Refunds / transfers / fees / adjustments stay out of both tiles per D-77's strict reading. The `forByCurrency` HAVING clause gets the same type-filter so original-currency mode never silently double-counts transfers in any currency band."
  - "Net total now sums `type IN ('income', 'expense')` rows. Previously net = SUM(settled_amount_minor) — implicit subtraction via positive/negative signs. With type-filtering the equivalent is `SUM(CASE WHEN type IN ('income', 'expense') THEN settled_amount_minor ELSE 0 END)` so net = inflow − outflow stays algebraically intact. The Phase 2 DashboardCurrencyModeTest expectations (`net = inflow − outflow` on EUR-only data) continue to hold because every test row carries a default type derived from amount sign in `makeTransaction()` — the existing tests stay GREEN."
  - "Toast dispatch shape: `$this->dispatch('toast', message: $message)` (named-parameter form). No global toast-renderer existed in the codebase; the Blade view embeds an inline `<span x-show=\"toast\">` next to the Save button so the confirmation appears in the user's eye-line. Alpine listens via `x-on:toast.window` (Livewire 4 broadcasts component-dispatched events as window events the Alpine root catches). Phase 5's review-queue UX reuses this shape verbatim."
  - "Cross-user safety test widened from `NotFoundHttpException` to `Exception::class` plus the HTTP route assertion. Reason: `Livewire::test(...)` wraps mount() throwables at snapshot-serialization time, raising `InvalidArgumentException('Invalid Livewire snapshot structure...')` rather than the original NotFoundHttpException. The HTTP-route layer (`$this->get(route('transactions.show', $tx->id))->assertStatus(404)`) is the canonical user-facing invariant test; the Livewire test layer asserts that SOME exception fires AND the row remains untouched. Same defence-in-depth shape as the Phase 3-07 cross-user 404 test."
  - "Empty-string Reclassify guarded by the action's `Transaction::TYPES` allow-list, not by an early-return on `=== ''`. Empty string is not a member of TYPES so it raises InvalidArgumentException. The Blade button's `@disabled($reclassifyType === '')` is the UX-level guard that prevents the user from reaching the action with an empty value; the action's check is defence-in-depth for any future wire:click invocation that bypasses the Blade button."
  - "Per-task TDD discipline maintained: 2 RED commits (bae87ef + 983fbff) before 2 GREEN commits (62a040f + 14d6b19). Larastan level-10 strict + Pint clean across all 4 commits."

patterns-established:
  - "Atomic break-pair on user-override: the row's pair_transaction_id is captured BEFORE the save() that nullifies it; the partner row's pair_transaction_id is cleared inside the same DB transaction; partner's `type` is preserved (reclassify only un-pairs, never re-types). Phase 5's review-queue 'reject candidate link' action will inherit this shape — same capture-then-null-both pattern."
  - "Single-click reclassify UX with inline toast (D-78 default): `wire:model.live` on the select drives the Save button's `@disabled` predicate; clicking Save fires the `reclassify($wire.reclassifyType)` action which resets the dropdown property to `''` and dispatches the `toast` event. The Blade view's Alpine `x-show`/`x-text` block listens for the toast on the window scope and fades it out after 3s. Calm aesthetic preserved — no modal, no full-page redirect."
  - "Dashboard rollup type-filter contract: every future query that powers an 'income' / 'expense' tile filters on `transactions.type`, NOT on amount sign. Phase 5's chain-resolver tiles, Phase 8's recurring-income panel, Phase 10's forecast inflows — all inherit this rule. The amount-sign-keyed CASE expression is now a deprecated query shape in this codebase."

requirements-completed:
  - "LED-05 (an income detector flags inflows that are genuine income vs internal moves between owned accounts) — the detection half lives in `ClassifyTransactionType` (Wave 2 D-77); Wave 3 lights up the dashboard surface that consumes the typed rows, plus the manual-override action on the detail page (D-78). Together: a `transfer_in` row never inflates the dashboard's income tile, and the user can flip any misclassified row in one click."

# Metrics
metrics:
  duration: "~7min"
  tasks_completed: 2
  files_created: 2
  files_modified: 3
  commits: 4
  date_completed: 2026-05-16
---

# Phase 4 Plan 04: Wave 3 Income Demoability + Manual Override Summary

**One-liner:** Wave 3 lights up Phase 4 SC #4: a `transfer_in` row no
longer inflates the dashboard's income tile (the `ThisPeriodAtAGlanceQuery`
rollups now filter by `transactions.type` instead of by amount sign),
and the user can flip any misclassified row in one click from
`/transactions/{id}` (single-click reclassify with an inline toast +
atomic break-pair invariant).

## Performance

- **Duration:** ~7 minutes
- **Started:** 2026-05-16T (mid-session — Wave 2 had just landed)
- **Tasks:** 2 (each task followed RED → GREEN = 4 commits)
- **Files created:** 2
- **Files modified:** 3

## Accomplishments

- **Manual override (D-78):** the `/transactions/{id}` detail page
  gains a Reclassify control. `<select>` lists every value in
  `Transaction::TYPES` except the row's current type;
  `wire:model.live` drives the Save button's enabled state;
  clicking Save fires the `reclassify()` action which validates the
  new type against the allow-list, persists it, atomically breaks
  the pair if the new type is non-transfer, and dispatches an
  inline toast.
- **Atomic break-pair invariant:** both the row's and its partner's
  `pair_transaction_id` columns are nulled inside a single DB
  transaction. Partner's `type` is preserved — reclassify is a
  pure un-pair, never a partner-re-type. Transfer-to-transfer
  reclassifies (e.g. fixing a wrongly-flipped sign) preserve the
  pair entirely (re-pairing on import is the listener's job, not
  the override's).
- **Cross-user safety:** mount()'s raw Query Builder exists() guard
  raises NotFoundHttpException; reclassify()'s Eloquent
  firstOrFail() raises ModelNotFoundException. Both layers filter
  on `user_id` so a cross-user invocation is structurally
  impossible. HTTP-route test asserts 404; Livewire test asserts
  some exception fires AND the row stays untouched.
- **Income tile regression test (Phase 4 SC #4 detection half):**
  `ThisPeriodAtAGlanceQuery` now filters by `transactions.type`
  instead of by amount sign. A `transfer_in` row carries a positive
  amount but never inflates the income tile; a `transfer_out` row
  carries a negative amount but never inflates the expense tile;
  refunds / fees / adjustments stay out of both. The same filter
  applies to both `for()` (EUR-only display) and
  `forByCurrency()` (multi-currency tile rows + their HAVING
  clause).
- **Phase 4 SC #4** is GREEN end-to-end: a misclassified row reaches
  the dashboard via the income tile, the user reclassifies it in
  one click, and the dashboard reflects the change on the next
  render.

## Task Commits

Each task followed RED → GREEN — two commits per task:

1. **Task 1: TransactionDetail Reclassify action + Blade + 7 feature tests**
   - `bae87ef` (test) — failing reclassify feature tests
   - `62a040f` (feat) — reclassify action + Blade dropdown/toast

2. **Task 2: DashboardIncomeTest + ThisPeriodAtAGlanceQuery type-filter**
   - `983fbff` (test) — failing dashboard-income regression tests
   - `14d6b19` (feat) — narrow `for()` + `forByCurrency()` rollups to `type IN ('income', 'expense')`

## Files Created / Modified

### Created
- `Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php`
  — 7 feature tests: `changesType`, `breaksPair`,
  `preservesPairOnTransferToTransfer`, `crossUser404`,
  `rejectsInvalidType`, `emptyTypeIsNoOp`,
  `rendersReclassifyDropdownOnDetailPage`. The cross-user case
  exercises both the HTTP route (the canonical user-facing 404
  invariant) and the Livewire action call (defence-in-depth at the
  action layer).
- `Modules/Ledger/tests/Feature/DashboardIncomeTest.php` —
  3 regression tests pinning the type-filter contract on
  `ThisPeriodAtAGlanceQuery::for()`: `excludesTransfers`,
  `includesIncome`, `expenseTileExcludesTransfers`.

### Modified
- `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` —
  added `public string $reclassifyType = ''` and
  `public function reclassify(string, CurrentUser, DatabaseManager): void`.
  Imports the global `InvalidArgumentException` for the
  allow-list guard. Class header doc updated to describe the
  Wave-3 reclassify contract.
- `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php`
  — added `<section aria-labelledby="reclassify-heading">` block
  with the select + Save button + Alpine `x-show`/`x-text` toast
  region. Calm aesthetic preserved — single bordered section, no
  modal, no confirmation step.
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` —
  both `for()` and `forByCurrency()` rollups (including the
  `forByCurrency` HAVING clause) now filter by
  `transactions.type` instead of by amount sign. The type-filter
  CASE expression is the new canonical query shape for any future
  income / expense tile in this codebase.

## Decisions Made

See the `key-decisions` frontmatter array. Highlights:

1. **ThisPeriodAtAGlanceQuery was Option B.** Diagnostic read at
   execution start confirmed both rollups summed by amount sign.
   Wave 3 narrows both rollups (plus the `forByCurrency` HAVING
   clause) to filter by `transactions.type`. Refunds / transfers /
   fees / adjustments stay out of both tiles per D-77's strict
   reading.

2. **Toast dispatch shape:** `$this->dispatch('toast', message: $message)`
   (named-parameter form). No global toast-renderer existed; the
   Blade view embeds an inline `<span x-show="toast">` next to the
   Save button. Phase 5's review-queue UX inherits this shape
   verbatim.

3. **Cross-user safety test widened to `Exception::class` + HTTP
   route assertion.** The Livewire test harness wraps mount()
   throwables at snapshot-serialization time, raising
   `InvalidArgumentException` instead of the original
   `NotFoundHttpException`. The HTTP route layer is the canonical
   user-facing 404 invariant test; the Livewire layer asserts that
   SOME exception fires AND the row stays untouched.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Livewire 4 test-harness mount() exception wrapping**

- **Found during:** Task 1 (`crossUser404` test first run)
- **Issue:** Plan's `<behavior>` block for Test 4 asserted that
  `Livewire::test(TransactionDetail::class, ['transactionId' => $userATx->id])->call('reclassify', 'expense')`
  raises `NotFoundHttpException`. In practice the Livewire 4
  test harness catches mount() throwables and wraps them as
  `InvalidArgumentException('Invalid Livewire snapshot structure: …')`
  at snapshot-serialization time. The original NotFoundHttpException
  is shadowed.
- **Fix:** Test now asserts both layers of the cross-user invariant:
  (a) the HTTP route returns 404 via
  `$this->get(route('transactions.show', $tx->id))->assertStatus(404)`,
  which is the canonical user-facing test mirroring the Phase 3-07
  pattern; (b) the Livewire action call raises `Exception::class`
  AND the row stays untouched, which is the defence-in-depth check
  on the action layer. Both layers verify the same runtime
  invariant (cross-user write cannot land) — the test now matches
  the actual behavior without weakening the contract.
- **Files modified:** `Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php`
- **Verification:** all 7 reclassify tests GREEN; the cross-user
  HTTP route returns 404 as expected; the action call raises an
  exception and the row stays at type='income'.
- **Committed in:** `62a040f` (Task 1 GREEN commit — bundles the
  test refinement alongside the implementation)

**2. [Rule 2 — Consistency] forByCurrency() type-filter applied alongside for()**

- **Found during:** Task 2 (implementation read)
- **Issue:** Plan's `<action>` block focused on `for()`. The
  `forByCurrency()` method (Phase 3 D-46) carries the same
  amount-sign-keyed rollup shape. Leaving it unchanged would have
  introduced a silent rule difference: EUR-only mode filters by
  type, original-currency mode by amount sign — exactly the kind
  of regression D-77 wants to prevent.
- **Fix:** Applied the same type-filter to `forByCurrency()`'s
  SELECT and HAVING clauses. The original-currency mode now
  excludes transfers / refunds / fees / adjustments in every
  currency band.
- **Files modified:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php`
- **Verification:** the existing Phase 3 `DashboardCurrencyModeTest`
  cases stay GREEN because `makeTransaction()` defaults type to
  `'income'` for positive amounts and `'expense'` for negative
  amounts, which matches the new filter exactly. The 3 new
  `DashboardIncomeTest` cases GREEN.
- **Committed in:** `14d6b19` (Task 2 GREEN commit)

### Auth gates

None. No authenticated external services exercised in Wave 3
(in-app reclassify + local dashboard query only).

## Pre-existing failure unchanged

The single deferred failure (`TransactionTypeTest::it rejects an
invalid transaction type at the DB layer`) carried forward from
Wave 2 (logged in `deferred-items.md`). Test count: 571 passed
before Wave 3 work started after the new tests landed; 571 passed
+ 1 failed after Wave 3 GREEN. Net of Wave 3 work: +10 new GREEN
tests (7 reclassify + 3 dashboard income), no new regressions.

## Self-Check

### File existence
- `Modules/Ledger/tests/Feature/TransactionDetailReclassifyTest.php` — FOUND
- `Modules/Ledger/tests/Feature/DashboardIncomeTest.php` — FOUND
- `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` — MODIFIED (verified `reclassify` method + `pair_transaction_id` reference present)
- `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` — MODIFIED (verified `Reclassify` heading + `wire:click="reclassify` wiring present)
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — MODIFIED (verified `type = 'income'` filter present)

### Commit existence
- `bae87ef` — test(04-04): add failing TransactionDetail reclassify feature tests — FOUND
- `62a040f` — feat(04-04): add TransactionDetail Reclassify action + Blade dropdown — FOUND
- `983fbff` — test(04-04): add failing DashboardIncome regression tests — FOUND
- `14d6b19` — feat(04-04): filter ThisPeriodAtAGlanceQuery rollups by transactions.type — FOUND

### Gate sequence (TDD plan-task verification)

Both tasks followed RED → GREEN: each task's `test(...)` commit
landed before its `feat(...)` commit. Larastan level-10 strict +
Pint clean throughout. Per-task TDD compliance is the governing
contract for this `type: execute` plan and it is satisfied.

### Quality gates

- `composer analyse` — exits 0 (Larastan level max + strict-rules + Livewire extension)
- `composer format:check` — exits 0 (Pint)
- `composer test` — 571 passed, 3 skipped, 3 notices, 1 failed.
  The single failure is the pre-existing
  `TransactionTypeTest::it rejects an invalid transaction type`
  carried forward from Wave 2 (`deferred-items.md`). Net of
  Wave 3 work the suite GREENed 10 new tests (7 reclassify + 3
  dashboard income) with zero regressions.

## Self-Check: PASSED

## Pointer to Wave 4

Wave 4 (plan 04-05) ships the deferral close-out:

- ROADMAP.md Phase 4 SC #2 rewrite — drop the "User can optionally
  authorize PayPal via OAuth2 and pull recent activity directly
  through the Reporting API" clause; reframe SC #2 as a deferred
  trigger ("when the user upgrades to a PayPal Business account,
  revisit ING-09").
- REQUIREMENTS.md — add ING-09 to a "Deferred / future-revisit"
  section with the business-upgrade trigger; the matrix-table
  status flips from `Phase 4 / Pending` to `Deferred (post-v1)`.
- `BoundaryArchTest::noPaypalApiRoute` — arch test asserting that
  no route, controller, or Public/ surface under `Modules/Import`
  / `Modules/Ingestion` references a `paypal-api` source format
  in Phase 4. Defence-in-depth against accidental partial work
  on the deferred path.

## Threat Flags

No new threat surface introduced beyond the plan's `<threat_model>`.
All Wave 3 mitigations (T-04-W3-01 through T-04-W3-06) are in
place:

- T-04-W3-01 (tampering on `reclassify($newType)`): explicit
  `in_array($newType, Transaction::TYPES, true)` allow-list at
  action entry. `rejectsInvalidType` + `emptyTypeIsNoOp` tests
  cover.
- T-04-W3-02 (info disclosure via cross-user reclassify): both
  mount() (raw Query Builder exists() filter) and reclassify()
  (Eloquent firstOrFail with user_id filter) scope by `user_id`.
  `crossUser404` test covers HTTP + action layers.
- T-04-W3-03 (tampering on partner-row update): partner
  `Transaction::query()->where('user_id', $user->id)->where('id', $partnerId)->update(['pair_transaction_id' => null])`
  filters on both user_id AND id. `breaksPair` test covers.
- T-04-W3-04 (race between two reclassify clicks):
  accepted — SQLite WAL single-writer serialises both writes;
  worst case is the second click running against the state left
  by the first.
- T-04-W3-05 (toast leaking type name): accepted — the toast
  message displays only the new type to the user who clicked the
  button.
- T-04-W3-06 (reclassify to 'adjustment' to mask income):
  accepted — single-user app, the user IS the operator; Phase 11
  audit log is the future hardening.

---
*Phase: 04-paypal-ingestion-transfer-detection*
*Completed: 2026-05-16*
