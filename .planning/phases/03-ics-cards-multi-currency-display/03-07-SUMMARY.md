---
phase: 03-ics-cards-multi-currency-display
plan: 07
subsystem: ui
tags: [transaction-detail, livewire, multi-currency, fx-rate, phase-3, route-class-handler]

requires:
  - phase: 01-foundation-asn-csv-vertical-slice
    provides: Transaction Eloquent model + RefreshDatabase Pest harness + layouts.app extends-style page envelope
  - phase: 03-01
    provides: TransactionDetailFxRateTest scaffolds (4 Red placeholders driven Green here, plus a 5th cross-user 404 guard added in this plan)
  - phase: 03-02
    provides: fx_rate_used decimal(18,8) column populated by NormalizeStage when settled/native pair diverges
  - phase: 03-06
    provides: Money locale-aware format() default — EUR -> nl_NL, else en_US — reused inline by the detail page for native + settled amounts

provides:
  - "TransactionDetail Livewire SFC (final, no constructor, method-DI via mount(int, CurrentUser, DatabaseManager) + render(CurrentUser, ViewFactory))"
  - "transaction-detail.blade.php with conditional `@if ($transaction->fx_rate_used !== null)` <dl> row"
  - "GET /transactions/{transactionId} route (->whereNumber, ->name('transactions.show'), web+auth middleware)"
  - "Ledger Livewire component registration: ledger.transaction-detail -> TransactionDetail::class"

affects: []

tech-stack:
  added: []
  patterns:
    - "Livewire class-as-handler route binding: Route::get('/path/{param}', Component::class)->whereNumber() — Livewire's SupportPageComponents injects the route parameter directly into mount(int $param, ...) via ImplicitRouteBinding"
    - "Page-envelope wiring via View::macro('extends', 'layouts.app', [...]) inside render() — Livewire's SupportPageComponents macro produces an @extends('layouts.app') @section('content') wrapper that matches every other diederik page without a separate Blade wrapper file"
    - "Cross-user 404 invariant: every Eloquent or Query Builder read in the Livewire SFC carries `->where('user_id', $currentUser->user()->id)` — the structural defence-in-depth pattern UserIdColumnArchTest enforces at the schema layer, mirrored at the query layer in code"
    - "Query Builder ->exists() (raw DatabaseManager::table()) used instead of Eloquent ->exists() to clear PHPStan staticMethod.dynamicCall — same pattern documented in 03-04 (PreviewWizard::needsIcsAccountName) and 03-04-SUMMARY"

key-files:
  created:
    - "Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php"
    - "Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php"
  modified:
    - "Modules/Ledger/Routes/web.php (new /transactions/{transactionId} route with class-as-handler binding)"
    - "Modules/Ledger/Providers/LedgerServiceProvider.php (new Livewire component registration: ledger.transaction-detail)"
    - "Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php (4 scaffolds Green + 5th cross-user 404 case added)"

key-decisions:
  - "Route binding chosen as class-as-handler: Route::get('/transactions/{transactionId}', TransactionDetail::class). The plan listed this shape verbatim; Livewire 4's SupportPageComponents resolves the route parameter into mount() automatically. Route::view was rejected because it cannot pass the URL parameter into the Livewire component without a closure wrapper, and a closure wrapper would force an extra Blade file that exists solely to forward the id."
  - "Page envelope via View::macro('extends') instead of a separate Blade wrapper file. The codebase's layouts.app uses @yield('content') (extends-style), not {{ \$slot }} (component-style), so render() calls \$view->extends('layouts.app', ['title' => ...]) — Livewire's SupportPageComponents macro hooks this into the existing layout without a wrapper Blade like the other pages have."
  - "mount() uses raw Query Builder DatabaseManager->table('transactions')->where(...)->exists() instead of Eloquent Transaction::query()->exists(). Reason: PHPStan strict-rules (level=max) flags `staticMethod.dynamicCall` on Eloquent's magic-forwarded exists() under a freshly resolved Builder. Same pattern is already documented in 03-04 (PreviewWizard::needsIcsAccountName) and codified in UpdateTransactionCategory's category-visibility pre-check."
  - "render() uses Eloquent Transaction::query()->firstOrFail() for the row read. firstOrFail() returns the typed Transaction model so the view receives a strongly-typed object; the eager existence check in mount() makes firstOrFail's ModelNotFoundException unreachable on a clean request (defence-in-depth, not the primary 404 gate)."
  - "Native + settled amount rendering uses the locale-aware Money::format() routing inline (EUR -> nl_NL, else en_US) — the same `\$fmt` closure pattern as transactions-list.blade.php (03-05) and dashboard.blade.php (03-06). Plan 03-06 widened Money::format()'s default to do this routing internally, but the explicit closure makes the locale-routing intent visible at the call site. Promoting to a Public/Services helper is now a viable cleanup: three consumers (list, dashboard, detail) all share the closure verbatim."
  - "Cross-user 404 guard test (the 5th case beyond the 4 scaffolded ones) added. It creates a second User row via App\\Models\\User::create([...]) and acts as that user against the first user's transaction id; assertStatus(404) confirms the user_id WHERE in mount() blocks cross-user reads. UserIdColumnArchTest verifies the schema invariant; this test verifies the runtime invariant on the new surface."

patterns-established:
  - "Pattern: Livewire class-as-handler route binding with whereNumber() on the URL parameter. Future detail pages (transaction-detail's sibling pages, e.g. /imports/{id}/details if added) should mirror this rather than introducing closure routes or Blade wrapper files."
  - "Pattern: page-shell wiring via \$view->extends('layouts.app', ['title' => ...]) inside the Livewire component's render(). Use this instead of a separate Blade wrapper file when the page lives at a route with URL parameters that need to flow into mount()."
  - "Pattern: raw Query Builder exists() check in Livewire mount(), Eloquent firstOrFail() in Livewire render(). The mount() check is the gate (cheap, no model instantiation, PHPStan-clean); the render() read is the data source (typed model, eager loading possible). The render() firstOrFail throw never trips in practice — it's defence in depth."

requirements-completed:
  - UI-06
  - LED-03

duration: ~4min
completed: 2026-05-15
---

# Phase 3 Plan 07: Transaction detail page with conditional FX-rate row Summary

**Detail page added from scratch at /transactions/{id} with a calm two-column metadata block plus an Effective-rate `<dl>` row that renders only when fx_rate_used is non-null. Phase-3 group goes from 75 Green / 4 Red to 80 Green / 0 Red — every Phase 3 test now passes.**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-05-15T18:08:23Z
- **Completed:** 2026-05-15T18:12:50Z
- **Tasks:** 1
- **Files created:** 2
- **Files modified:** 3

## Accomplishments

- TransactionDetail Livewire SFC landed at `Modules/Ledger/Internal/Http/Livewire/`. Final class, extends `Livewire\Component`, zero constructor. mount(int $transactionId, CurrentUser $currentUser, DatabaseManager $db) and render(CurrentUser $currentUser, ViewFactory $views) both use method-level DI; no `auth()` / `Auth::user()` / facade lookups. The `where('user_id', ...)` predicate appears on every read.
- transaction-detail.blade.php landed at `Modules/Ledger/Resources/views/livewire/`. Renders a calm-aesthetic two-column `<dl>` (date subtitle + counterparty + native amount + settled amount) wrapped in `layouts.app` chrome via the existing page envelope. The conditional `@if ($transaction->fx_rate_used !== null)` block renders the locked "Effective rate" `<dt>` + "€{rate} / {ISO}" `<dd>` + "Includes any ICS markup." helper paragraph.
- Route registered as a Livewire class-as-handler: `Route::get('/transactions/{transactionId}', TransactionDetail::class)->whereNumber('transactionId')->name('transactions.show')`. The `whereNumber()` constraint rejects non-numeric URL segments at the routing layer (T-03-07-04 mitigation). The route is grouped under `['web', 'auth']` middleware so unauthenticated visitors redirect to /login (Fortify default).
- LedgerServiceProvider gained one new Livewire component registration: `ledger.transaction-detail` -> `TransactionDetail::class`. Required by Livewire's class-as-handler — the route action resolves the component by FQCN, but the Blade view's `<livewire:...>` macro needs the kebab-case alias.
- TransactionDetailFxRateTest now carries five real assertions (the four scaffolded cases + a fifth cross-user 404 guard). All five Green; phase-3 group goes from 75/4 to 80/0.
- Zero regression on Phase 1/2 tests: full suite is 469 passed / 3 skipped (was 464 / 3); net +5 from this plan. The 3 skips are unchanged Phase 2 MT940 cross-format dedup skips.

## Task Commits

Each task was committed atomically:

1. **Task 1: TransactionDetail SFC + Blade + route + Livewire registration + 5 TransactionDetailFxRateTest cases Green** — `66b1fda` (feat)

**Plan metadata commit:** appended after this SUMMARY (state + roadmap).

## Files Created/Modified

### Created
- `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` — final class, zero constructor, method-DI on both mount() and render(). Class docblock spells out the multi-user-readiness invariant, the no-facade rule, the page-envelope macro, and the rationale for using raw Query Builder exists() in mount() but Eloquent firstOrFail() in render().
- `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` — two-column `<dl>` of date/counterparty/native amount/settled amount, conditional FX-rate row when fx_rate_used IS NOT NULL. Wrapped in `<main class="min-h-screen bg-white"><div class="mx-auto max-w-3xl px-8 py-12 space-y-6">` to match the calm page chrome from dashboard.blade.php / transactions.blade.php. `data-testid="transaction-detail"` on the outer wrapper and `data-testid="fx-rate-row"` on the conditional row.

### Modified
- `Modules/Ledger/Routes/web.php` — added the new `Route::get('/transactions/{transactionId}', TransactionDetail::class)->whereNumber('transactionId')->name('transactions.show')` line inside the existing `Route::middleware(['web', 'auth'])->group(...)`. Use statement for TransactionDetail added at file top.
- `Modules/Ledger/Providers/LedgerServiceProvider.php` — new use statement for TransactionDetail; new `$livewire->component('ledger.transaction-detail', TransactionDetail::class)` registration call inside boot(). Existing transactions-list registration untouched.
- `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` — four placeholder `expect(true)->toBe(false, 'scaffold')` calls replaced with real feature tests using actingAs + HTTP GET + assertOk + assertSee/assertDontSee on the locked copy. A fifth `it('returns 404 when the transaction belongs to a different user')` case added. beforeEach seeds the fixture user + ICS account (reusing the helper `seedFixtureUserAndAccount`) + an ImportRun; each test creates its transaction via `makeTransaction` overrides for the (currency, amount_minor, settled_amount_minor, settled_currency, fx_rate_used) tuple.

## Decisions Made

The plan's `<output>` section asks for five specific observations. Answers below.

### 1. Was the detail page created from scratch in this plan?

**Yes.** Pre-revision audit found zero pre-existing `TransactionDetail*.php` under `Modules/Ledger/Internal/Http/Livewire/` and zero `transaction-detail*.blade.php` under `Modules/Ledger/Resources/views/livewire/`. The pre-revision route table held only `/transactions` (the list page) — no detail route. This plan therefore introduced the SFC + Blade + route + Livewire-component registration in a single atomic commit (`66b1fda`).

### 2. What route-registration shape was chosen for /transactions/{transactionId}?

**Class-as-handler:** `Route::get('/transactions/{transactionId}', TransactionDetail::class)->whereNumber('transactionId')->name('transactions.show')`.

Trade-off considered: `Route::view('/transactions/{transactionId}', 'ledger::transaction-detail-page')` was rejected because Route::view does not pass route parameters into the Livewire component without a closure or a wrapper Blade that re-forwards the id via `@livewire('ledger.transaction-detail', ['transactionId' => $transactionId])`. Class-as-handler short-circuits both: Livewire's SupportPageComponents introspects `mount()`'s parameter list and injects the URL parameter directly. The plan also called out this exact shape in its `<interfaces>` block.

### 3. Was the optional cross-user 404 test added (recommended) or skipped?

**Added.** The fifth test case `it('returns 404 when the transaction belongs to a different user')` creates a second User row via `App\Models\User::create([...])`, calls `$this->actingAs($intruder)`, then GETs the first user's transaction's URL. `assertStatus(404)` confirms mount()'s `NotFoundHttpException` fires before any row data leaves the database. This is the runtime mirror of `UserIdColumnArchTest`'s schema-level invariant.

### 4. Did native + settled amount rendering use the locale-aware Money formatter from plan 03-06?

**Yes.** The Blade uses the same `$fmt = static fn (Money $money): string => $money->currency() === 'EUR' ? $money->format('nl_NL') : $money->format('en_US')` closure pattern as transactions-list.blade.php (03-05) and dashboard.blade.php (03-06). `number_format($cents / 100, 2, '.', '')` was rejected as a regression to the pre-03-06 simple-format path — the locale-aware formatter is the project-wide standard since 03-06 widened Money::format()'s default. Note: the FX-rate row's `€{rate} / {ISO}` string is intentionally not a Money amount — it's a ratio — so it stays on `number_format((float) $transaction->fx_rate_used, 3, '.', '')` per the UI-SPEC's locked format.

### 5. Did NoFloatMoneyArchTest stay GREEN despite the Blade-level (float) cast on fx_rate_used?

**Yes — and the arch test's scope explains why.** `NoFloatMoneyArchTest` (in `tests/Contracts/`) introspects only migration files: it globs `Modules/*/Database/Migrations/*.php` and checks `preg_match('/->(float|double|real)\(["\']\w*(amount|minor)\w*["\']/i', $contents)`. Blade files, PHP source files outside Migrations, and casts on display-only values are not in scope. The arch test passed without modification. The `(float) $transaction->fx_rate_used` cast in the Blade is bounded by `number_format(..., 3, '.', '')` to three decimal places — it's a display-only conversion of the decimal(18,8) string into a PHP float for the formatter, never written back to a money column.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] PHPStan staticMethod.dynamicCall on Eloquent ->exists() in mount()**

- **Found during:** Task 1 (PHPStan run after the initial Eloquent-only implementation).
- **Issue:** The plan's `<action>` step 1 showed `Transaction::query()->where(...)->exists()` in mount(). PHPStan at level=max strict flagged `staticMethod.dynamicCall` because Eloquent's `exists()` is a magic forward over the Builder's instance method, which the strict ruleset rejects on a freshly resolved query.
- **Fix:** Switched mount() to raw Query Builder via constructor-injected DatabaseManager: `$db->connection()->table('transactions')->where(...)->exists()`. Same pattern as `UpdateTransactionCategory`'s category-visibility pre-check and `PreviewWizard::needsIcsAccountName` (documented in the 03-04 STATE.md decisions). Pure-shape change; no runtime semantics altered.
- **Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` (added DatabaseManager parameter to mount() signature; updated mount() body to use Query Builder; added explanatory inline comment).
- **Commit:** included in `66b1fda` (Task 1 commit, applied before the test/PHPStan re-run).

### Acknowledged Plan-Spec Deltas (no functional impact)

1. **`mount()` signature includes DatabaseManager.** The plan's `<action>` block shows `mount(int $transactionId, CurrentUser $currentUser)`. The auto-fix above forces a third parameter (`DatabaseManager $db`) so mount() can dispatch to the raw Query Builder. Pure DI shape; consistent with the strict-rules pattern.

2. **Native + settled amounts use the locale-aware `$fmt` closure.** The plan's `<action>` step 2 sketched `number_format($cents / 100, 2, '.', '')` for the amount rows. This plan upgrades to the Money locale-routing closure (matching 03-05 / 03-06's pattern) because the project-wide standard moved to the locale-aware formatter in 03-06. The FX-rate ratio string is untouched — it stays on `number_format` as documented.

3. **`render()` uses `Eloquent firstOrFail()` rather than `find()` + manual 404.** Both patterns are listed in the plan's `<behavior>`. firstOrFail() returns the typed Transaction model in one call and throws ModelNotFoundException (which Laravel maps to 404 automatically) if the mount-level check ever leaks through; defence in depth.

## Tooling Compliance

- **Pint:** clean on every new and modified file (`vendor/bin/pint --test <files>` returns `{"tool":"pint","result":"passed"}`).
- **PHPStan level=max strict:** clean on `Modules/Ledger` (`39 files OK`). Includes all five touched files plus the unchanged sibling files in the module.
- **DI-only:** TransactionDetail.php has zero constructor; CurrentUser / DatabaseManager / ViewFactory all arrive on method parameters. Zero `auth()` / `Auth::user()` / facade references introduced in production code (the docblock mentions `auth()`/`Auth::user()` only to spell out what the file avoids).
- **GSD-agnostic:** zero `.planning/` / `D-XX` / `PLAN.md` references in any committed PHP / Blade source. The class docblock describes current behaviour ("renders a calm two-column <dl>... plus a conditional Effective rate row"), not history.

## Test Posture

After this plan:

- **Phase-3 group** (`vendor/bin/pest --group=phase-3 --exclude-group=integration`): **80 Green / 0 Red** (was 75 / 4). +5 Green / -4 Red driven by this plan (the 4 TransactionDetailFxRateTest scaffolds + the cross-user 404 guard). Every Phase 3 test now passes.
- **Full suite** (`vendor/bin/pest --exclude-group=integration`): **469 Green / 0 Failed / 3 skipped (13422 assertions, ~14.0s)** vs. previously 464 / 4 / 3. Net +5 Green / -4 Red. The 3 skips are unchanged Phase 2 MT940 cross-format dedup skips (see 02-04-SUMMARY).
- **Architecture invariants** (`vendor/bin/pest tests/Contracts/`): **22 Green** (BoundaryArchTest, NoFloatMoneyArchTest, MoneyColumnsArchTest, UserIdColumnArchTest, NoExtImapTest, IdempotencyContractTest, etc.). Zero regression.

## Known Stubs

None. The detail page wires real Eloquent data end-to-end from the (id, user_id) tuple to the rendered Blade. `$transaction` is a real Transaction model from a live SQLite read; the FX-rate row is gated by an actual column value (not a placeholder); no "coming soon" copy, no empty mocks, no stub data.

## Threat Flags

None. The plan's `<threat_model>` covered the full surface:

- T-03-07-01 (Information Disclosure, cross-user leak) — mitigated by the leading `where('user_id', $currentUser->user()->id)` predicate on every Eloquent and Query Builder read in mount() AND render(). UserIdColumnArchTest verifies the schema invariant; the new cross-user 404 test verifies the runtime invariant on this surface.
- T-03-07-02 (Tampering, fx_rate_used display value) — accepted; the `(float)` cast in Blade is display-only and never re-enters the money pipeline. NoFloatMoneyArchTest scope (migrations only) confirms this is not a violation.
- T-03-07-03 (Spoofing, CSRF) — mitigated by Phase 3's read-only detail page (no form submissions); Livewire's built-in CSRF would apply automatically to any future wire:click/wire:submit.
- T-03-07-04 (Tampering, non-numeric URL segments) — mitigated by `->whereNumber('transactionId')` rejecting non-numeric segments at the routing layer before mount() runs.

No new endpoints beyond the documented `/transactions/{transactionId}`, no new file-access paths, no new schema rows at trust boundaries.

## Self-Check: PASSED

Verified post-write:

- All 5 declared files exist on disk:
  - `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php` ✓
  - `Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php` ✓
  - `Modules/Ledger/Routes/web.php` ✓ (extended)
  - `Modules/Ledger/Providers/LedgerServiceProvider.php` ✓ (extended)
  - `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` ✓ (rewritten)
- 1 task commit resolved against `git log --oneline`:
  - `66b1fda` Task 1 (TransactionDetail SFC + Blade + route + Livewire registration + 5 TransactionDetailFxRateTest cases Green)
