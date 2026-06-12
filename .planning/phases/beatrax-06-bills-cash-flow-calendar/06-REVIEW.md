---
phase: beatrax-06-bills-cash-flow-calendar
reviewed: 2026-06-12T00:00:00Z
depth: deep
files_reviewed: 34
files_reviewed_list:
  - Modules/Calendar/Database/Migrations/2026_06_12_000001_add_calendar_account_prefs_to_user_preferences.php
  - Modules/Calendar/Internal/Http/Livewire/CalendarPage.php
  - Modules/Calendar/Internal/Services/CalendarQuery.php
  - Modules/Calendar/Providers/CalendarServiceProvider.php
  - Modules/Calendar/Public/Dto/CalendarDayDto.php
  - Modules/Calendar/Public/Dto/CalendarEntryDto.php
  - Modules/Calendar/Resources/views/livewire/calendar-page.blade.php
  - Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php
  - Modules/Calendar/Routes/web.php
  - Modules/Calendar/tests/Feature/CalendarAccountsPopoverTest.php
  - Modules/Calendar/tests/Feature/CalendarDrillThroughTest.php
  - Modules/Calendar/tests/Feature/CalendarMonthNavTest.php
  - Modules/Calendar/tests/Feature/CalendarPageBalanceLineTest.php
  - Modules/Calendar/tests/Feature/CalendarPageComputingStateTest.php
  - Modules/Calendar/tests/Feature/CalendarPageEmptyStateTest.php
  - Modules/Calendar/tests/Feature/CalendarPagePastDayStateTest.php
  - Modules/Calendar/tests/Feature/CalendarPageRendersSeriesTest.php
  - Modules/Calendar/tests/Feature/CalendarPaletteAndSidebarTest.php
  - Modules/Calendar/tests/Pest.php
  - Modules/Calendar/tests/TestCase.php
  - Modules/Calendar/tests/Unit/CalendarQueryAccountPrefsTest.php
  - Modules/Calendar/tests/Unit/CalendarQueryIrregularTest.php
  - Modules/Calendar/tests/Unit/CalendarQueryPastDayMatchTest.php
  - Modules/Core/Models/UserPreference.php
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/DevMode/Providers/DevModeServiceProvider.php
  - Modules/Forecasting/Internal/Jobs/ProjectForecastJob.php
  - Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php
  - Modules/Forecasting/tests/Feature/ForecastPageHorizonTest.php
  - Modules/Forecasting/tests/Unit/ProjectForecastJobUniqueTest.php
  - bootstrap/providers.php
  - resources/css/app.css
  - tests/Pest.php
findings:
  critical: 2
  warning: 11
  info: 8
  total: 21
status: fixed
fixed_at: 2026-06-12
fix_report: 06-REVIEW-FIX.md
---

# Phase 6: Code Review Report — Bills / Cash-Flow Calendar

**Reviewed:** 2026-06-12
**Depth:** deep
**Files Reviewed:** 34
**Status:** issues_found

## Summary

Phase 6 ships the `/calendar` surface: a `CalendarQuery` service assembling month grids from `RecurringSeriesQuery` + `ForecastQuery` + `ExchangeRateService`, a Livewire `CalendarPage` (month nav, accounts popover, day panel), the user-preference persistence columns, and the forecast horizon extension to 180/365 days.

Cross-user posture is solid: every DB read traced through the full call chain (`CalendarQuery` → `RecurringSeriesQuery.allApprovedForUser/accountIdsForSeriesIds/counterpartyIdForSeries`, `ForecastQuery.forUser`, `CounterpartyProfileQuery.bySlug/identityForId`) is user-scoped, caller-supplied account IDs are intersected against owned accounts before use, and the toggle actions validate ownership. Blade output is consistently escaped — no XSS found. Module boundaries are respected (only `Public/*` services crossed; the `user_preferences` column-add from Calendar follows the sanctioned additive-migration pattern documented on `UserPreference`).

However, two Critical defects exist: the Accounts popover renders an inverted/contradictory state for the default (empty-array) sentinel, making the first interaction do the opposite of what the user intends; and the balance line sums forecast points across accounts while ignoring per-point currency, with the FX "conversion" being a structurally guaranteed no-op — violating the project's v1 multi-currency constraint. Several Warnings cover entry-placement date drift, over-matching in the ±7-day paid window, phantom "missed" entries in history, an unenforced URL-tamper ceiling that the code comments claim is enforced, an unhandled parse exception, unsanitized persistence of client-controlled arrays, wrong start-of-day balances, and a heavy N+1 query pattern re-executed on every Livewire interaction.

## Critical Issues

### CR-01: Accounts popover state is inverted for the default sentinel — first toggle does the opposite of user intent

**File:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php:109,118` + `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:176-211` + `Modules/Calendar/Internal/Services/CalendarQuery.php:646-676`
**Issue:** `visibleAccountIds = []` means "ALL accounts ON" (D-02 default) and `balanceAccountIds = []` means "spendable default ON" (D-03), but the checkboxes render via `@checked(in_array($acct['id'], $visibleAccountIds))` / `@checked(in_array($acct['id'], $balanceAccountIds))`. In the default state every checkbox renders **unchecked** while every account's entries are shown and the spendable accounts are being summed — the UI directly contradicts effective behavior. Worse, the interaction is inverted: from the default all-on state, a user who clicks account A's unchecked "Entries" box (intending to *show* it, since it reads as off) triggers `toggleEntriesAccount(A)` → `[]` becomes `[A]` → suddenly **only** A is visible and every other account's entries disappear. Conversely a user intending to *hide* A from the all-on view cannot: unchecking is impossible because nothing renders checked. It is also impossible to represent "no accounts" — toggling the last account off produces `[]`, which `CalendarQuery`/`persistAccountPrefs` reinterpret as "all on" / "spendable default". `CalendarAccountsPopoverTest` only exercises pre-seeded non-empty arrays and never the default-state interaction, so this is untested.
**Fix:** Materialize the default into explicit IDs at mount/render time so UI state, component state, and query semantics agree:
```php
// CalendarPage::mount() — after loading prefs:
if ($this->visibleAccountIds === []) {
    $this->visibleAccountIds = $this->fetchOwnedAccountIds($db, $userId); // all ON
}
if ($this->balanceAccountIds === []) {
    $this->balanceAccountIds = $this->fetchSpendableAccountIds($db, $userId); // D-03 default
}
```
Then drop the `[]`-as-default overload in `CalendarQuery::resolveVisibleAccountIds`/`resolveBalanceAccountIds` (or keep `null` for "never configured" only), and persist explicit arrays — including the legitimate "everything off" state. Add a feature test that starts from a fresh user, asserts default checkboxes render checked, and asserts un-checking one account hides only that account.

### CR-02: Balance line sums minor units across currencies and the FX conversion is a guaranteed no-op

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:491-520`
**Issue:** `buildBalanceMap()` sums `ForecastPointDto::$pointMinor` across all balance accounts ignoring each point's `currency` field (which the DTO carries), then wraps the cross-currency sum as `Money::ofMinor($sumMinor, $baseCurrency)` and calls `$this->fxService->convertToBase($money, $baseCurrency)`. Since source and target currency are the *same variable*, `ExchangeRateService::convertToBase()` (verified at `Modules/FX/Public/Services/ExchangeRateService.php:55-58`) always takes the `passthrough` branch — the conversion can never do anything. A USD-denominated account (Google Play — an explicit v1 requirement: "Multi-currency tracking required from v1") has its USD minor units added 1:1 to EUR minor units, producing wrong daily balances, wrong `isRisk` rose tints, and a wrong "Balance dips below €0" summary strip. The in-code comment admits the simplification, but `CalendarDayDto`'s docblock, the D-05 decision reference, and the `forMonth()` docblock all claim "FX-converted to the base reporting currency" — the implementation does not do what the shipped contract says, in a money-handling app.
**Fix:** Sum per currency, then convert each bucket:
```php
/** @var array<string, array<string, int>> $byDateCurrency */
foreach ($dto->points as $point) {
    $byDateCurrency[$point->date][$point->currency]
        = ($byDateCurrency[$point->date][$point->currency] ?? 0) + $point->pointMinor;
}
// ...
foreach ($byDateCurrency as $dateStr => $byCurrency) {
    $totalMinor = 0;
    foreach ($byCurrency as $currency => $sumMinor) {
        $converted = $this->fxService->convertToBase(Money::ofMinor($sumMinor, $currency), $baseCurrency);
        $totalMinor += $converted->converted->toMinor();
    }
    $map[$dateStr] = [$totalMinor, $isComputingAny];
}
```
Add a test with one EUR and one USD account proving the USD points are converted, not added raw.

## Warnings

### WR-01: N+1 query storm in buildEntryMap — 3–5 queries per series on every Livewire interaction

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:269-291,744-782`
**Issue:** Inside the per-series loop, `buildEntryMap()` issues: `counterpartyIdForSeries()` (join + GROUP BY per series), then either `identityForId()` or `resolveCounterpartySlugByClusterKey()` + `bySlug()` (and `bySlug` itself runs two aggregate `SUM/COUNT` queries over `transactions` — see `CounterpartyProfileQuery.php:59-70`), plus `resolveAccountName()` (one query per series). For N approved series that is roughly 3N–5N queries, and `render()` re-runs the entire `forMonth()` on **every** Livewire round-trip (each checkbox toggle, each day click). The resolution also runs *before* `placeSeriesInMonth()`, so the queries are wasted even for series that place zero dates in the display month. Batched precedents exist in the same codebase (`forSeriesIds`, `displayNamesForSeriesIds`, `statesForSeriesIds`, `accountIdsForSeriesIds`).
**Fix:** (1) Skip counterparty/account-name resolution when `placeSeriesInMonth()` returns `[]`. (2) Add batched public methods (`counterpartyIdsForSeriesIds(array $ids, User $user): array<int,int>`, an `identitiesForIds()` batch, and a single `accounts` name map fetched once from the already-resolved owned-account query) and resolve all metadata in ≤3 queries total per render.

### WR-02: ±7-day paid window over-matches weekly cadences — one payment marks up to three entries paid

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:64,590-604`
**Issue:** `hasMatchingOccurrence()` accepts any occurrence within ±7 days of the expected date and never consumes a matched occurrence. The window is 15 days wide while a weekly series spaces entries 7 days apart, so a single occurrence on June 8 marks the June 1, June 8, **and** June 15 entries all `isPaid`. A skipped weekly payment adjacent to a real one can therefore never show `isMissed`. The same non-consumption means one real payment plus one genuinely missed monthly entry near a month boundary can both read "paid" from a single occurrence row in the extended window.
**Fix:** Scale the window to the cadence (e.g. `min(MATCH_WINDOW_DAYS, intdiv(cadenceDays, 2))` → ±3 for weekly) and/or greedily consume each occurrence for at most one expected entry (sort entries and occurrences by date, match nearest-first, remove matched occurrences from the pool).

### WR-03: Backward projection has no series-inception bound — history months fabricate "missed" entries

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:360-383`
**Issue:** `placeSeriesInMonth()` walks `retreat()` from `nextExpectedAt` indefinitely far into the past (the only bound is the displayed month; `resolveDisplay()` permits years back to 2000). A monthly series first observed in June 2026 will render an entry in, say, March 2024 — a date when the subscription did not exist — and because no occurrence exists there, `forMonth()` flags it `isMissed`, rendering a false amber "Expected — not found" marker. Every history month before a series' first real occurrence becomes a wall of phantom missed payments.
**Fix:** Bound the backward walk at the series' earliest observed occurrence (or `created_at` as a fallback). `buildOccurrenceMap` data or a batched `MIN(observed_at)` per series can supply the floor; stop retreating once `cursor < seriesStart`.

### WR-04: Monthly/quarterly/yearly stepping drifts the day-of-month anchor and is non-invertible

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:413-436`
**Issue:** The cursor steps iteratively with `addMonthNoOverflow()`/`subMonthNoOverflow()` from the *previous step's result*, not from the anchor. A series anchored on Jan 31 walks Jan 31 → Feb 28 → **Mar 28** → Apr 28… permanently losing the 31st after the first short month. The backward-then-forward walk is also non-invertible: `nextExpectedAt = May 31` viewed in March retreats May 31 → Apr 30 → Mar 30 and places the entry on March **30**, while forward-stepping from an earlier anchor would yield March 31. Entries for end-of-month bills (a very common billing day) render on the wrong day, and the same bill can appear on different days-of-month depending on which month the user is viewing.
**Fix:** Step by occurrence index from the anchor instead of chaining: `$anchor->addMonthsNoOverflow($k)` for k = …,-1,0,1,… (and `addMonthsNoOverflow(3*$k)` / `addYearsNoOverflow($k)`), which preserves the day-31 anchor in months that have it and makes backward/forward placement symmetric.

### WR-05: 12-month ceiling not enforced at render time despite the docblock claiming it is (T-06-01)

**File:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:32-34,233-321,334-345`
**Issue:** The class docblock states "The 12-month forward ceiling is enforced both on nextMonth() and at render time so a tampered ?year=&month= cannot exceed the forecast horizon." It is not. `resolveDisplay()` clamps year only to 2000–2100 and month to 1–12; `render()` merely computes `$atCeiling` to disable the next button. `/calendar?year=2099&month=12` renders the December 2099 grid (all balances "—", phantom entries per WR-03/WR-04). The stated security/UX invariant (D-14) is silently violated for direct URL manipulation.
**Fix:** Clamp in `resolveDisplay()`:
```php
$ceiling = CarbonImmutable::now()->addMonths(12);
if ($year > $ceiling->year || ($year === $ceiling->year && $month > $ceiling->month)) {
    $year = $ceiling->year;
    $month = $ceiling->month;
}
```

### WR-06: selectDay throws an unhandled InvalidFormatException for tampered date strings

**File:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:151-169`
**Issue:** The guard regex `^\d{4}-\d{2}-\d{2}$` accepts impossible dates such as `2026-13-01` or `2026-99-99`; `CarbonImmutable::parse()` then throws `Carbon\Exceptions\InvalidFormatException`, surfacing as a 500 from a trivially tampered `wire:click` payload. (Dates like `2026-02-31` silently roll over to March 3 and are then rejected by the month check — inconsistent but harmless.)
**Fix:** Validate strictly before parsing:
```php
[$y, $m, $d] = array_map(intval(...), explode('-', $date));
if (! checkdate($m, $d, $y)) {
    return;
}
```

### WR-07: persistAccountPrefs writes client-controlled arrays to the DB unsanitized

**File:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:218-231`
**Issue:** `visibleAccountIds` / `balanceAccountIds` are public Livewire array properties — the client can set them to arbitrary content (foreign account IDs, strings, deeply nested arrays of any size) without going through the ownership-validated toggle methods, then call `persistAccountPrefs()` to store the raw payload as JSON in `user_preferences`. Read-side defenses hold (`mount()` keeps only ints, `CalendarQuery` intersects against owned IDs), so no data leak results — but the persistence path stores unvalidated, unbounded attacker-shaped data, and foreign IDs persisted today would silently become active if the read-side intersection ever regresses. Defense-in-depth requires write-side sanitization too.
**Fix:** Sanitize before persisting:
```php
$owned = $this->fetchOwnedAccountIds($db, $currentUser->id());
$entries = array_values(array_intersect(array_filter($this->visibleAccountIds, is_int(...)), $owned));
$balance = array_values(array_intersect(array_filter($this->balanceAccountIds, is_int(...)), $owned));
```

### WR-08: sodBalanceMinor is wrong whenever the previous grid day has no forecast data — day panel shows a fake €0,00 start-of-day

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:143-211` + `Modules/Calendar/Resources/views/livewire/partials/day-panel.blade.php:27-36`
**Issue:** SoD is chained as "previous grid day's EoD", but days without forecast points (every past day, the first grid day, any computing day) carry `eodMinor = 0`, so the next day inherits `sod = 0`. Concretely: forecast points start at today, so *today's* `sodBalanceMinor` is always 0 (yesterday is a past day with no point), yet today's `isComputing` is false — the day panel's SoD row gates only on the day's own `isComputing` and confidently renders "Start of day €0,00" regardless of the real balance. There is no "SoD unknown" sentinel in `CalendarDayDto`.
**Fix:** Track whether the previous day's EoD was real: carry `?int $sodBalanceMinor` (null = unknown) or an `isSodKnown` flag in the DTO, set it only when the prior day had a non-computing balance entry, and render "—" in the panel when unknown. For today specifically, seed SoD from the forecast's `todayBalanceMinor` anchor.

### WR-09: Past-day "actual balance" contract is unimplemented — every past day renders "—"

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:147-151,456-540` + `Modules/Calendar/tests/Feature/CalendarPagePastDayStateTest.php:22-24`
**Issue:** The test header states the contract: "The balance line for past days uses the actual (real) balance up to today and projection after today." No code implements it — forecast points begin at `as_of` (today), so every past day falls through to the `[0, true]` computing default and renders "—" in the balance corner forever. The contract item is also untested (the test file asserts only paid/missed markers), so the gap is invisible to CI.
**Fix:** Either implement past-day actuals (cumulative `transactions.amount_minor` sums per account per day up to today, reusing the `resolveAnchorFromTransactionsSum` pattern from `ForecastQuery`) or amend the documented contract and render past-day balance cells intentionally blank (not the "computing" em-dash, which implies pending data). Add the missing assertion either way.

### WR-10: Grid cell aria-label announces negative balances as positive

**File:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php:205-208`
**Issue:** `$ariaLabel .= ', projected balance €' . number_format(abs($day->eodBalanceMinor / 100), 0, ',', '.')` strips the sign via `abs()` with no minus prefix (unlike the visible `$balanceStr`, which prepends '−'). A screen-reader user on a −€450 risk day hears "projected balance €450" — materially wrong financial information on the exact cells that matter most (risk days).
**Fix:** `$ariaLabel .= ', projected balance ' . ($day->eodBalanceMinor < 0 ? 'minus ' : '') . '€' . number_format(...)`.

### WR-11: CalendarPage bypasses the Clock contract — two sources of "now" can disagree with CalendarQuery

**File:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:302,336,353`
**Issue:** `CalendarQuery` injects `Modules\Core\Public\Contracts\Clock` for "now" (isToday/isPast), but `CalendarPage` calls `CarbonImmutable::now()` directly in `render()`, `resolveDisplay()`, and `exceedsCeiling()`. If the bound Clock implementation ever differs from wall-clock (frozen clock, test double not using `setTestNow`, future timezone handling), the page's ceiling/default-month and the query's past-day classification silently diverge. Tests only pass because `setTestNow()` happens to affect both.
**Fix:** Inject `Clock` into the component methods (Livewire supports method injection, already used for `DatabaseManager`/`CurrentUser`) and use `$clock->now()` everywhere `CarbonImmutable::now()` appears.

## Info

### IN-01: Dead code in buildBalanceMap

**File:** `Modules/Calendar/Internal/Services/CalendarQuery.php:501-503`
**Issue:** `$cursor = $monthStart->subWeeks(1);` and `$gridEnd = $monthEnd->addWeeks(1);` are assigned and never used (a later loop redeclares `$cur = $monthStart`). The stale "include lead-in dates for the grid" comment suggests an abandoned approach.
**Fix:** Delete both lines and the comment.

### IN-02: mount() duplicates the UserPreference array cast with raw SQL + manual json_decode

**File:** `Modules/Calendar/Internal/Http/Livewire/CalendarPage.php:78-115`
**Issue:** The read path raw-queries `user_preferences` and hand-decodes JSON while the write path (`persistAccountPrefs`) uses the `UserPreference` model whose `casts()` already handles the same columns. Two mechanisms for one row invites drift (e.g. a future cast change won't apply to the read).
**Fix:** Read via `UserPreference::query()->where('user_id', $userId)->first()` and filter the cast arrays to ints.

### IN-03: Stale docblocks still describe three horizons after the Phase 6 extension to five

**File:** `Modules/Forecasting/Internal/Listeners/ProjectForecastOnRecurringChange.php:20-23` + `routes/console.php:195`
**Issue:** Both say "one per horizon (30 / 60 / 90 days)" while the loops now iterate the five-element `HORIZON_DAYS` (30/60/90/180/365). Misleading for the next reader sizing queue load (each recurring change now dispatches 5×(1+scenarios) jobs, two of them long-horizon).
**Fix:** Update both comments to reference `HORIZON_DAYS` rather than enumerating values.

### IN-04: Month-clamp tests assert nothing about clamping

**File:** `Modules/Calendar/tests/Feature/CalendarMonthNavTest.php:79-98`
**Issue:** The two "clamps an invalid #[Url] month" tests assert only `assertSee('Calendar')` — they pass even if clamping is removed entirely (the comments admit "We verify the render didn't crash"). Combined with WR-05 this leaves the whole URL-tampering surface effectively untested.
**Fix:** Assert the rendered month label, e.g. `->assertSee('Jun 2026')` (and after fixing WR-05, add a ceiling-clamp test for `?year=2099`).

### IN-05: Summary strip risk count includes lead-in/lead-out days outside the display month

**File:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php:24-47`
**Issue:** `$riskDays = array_filter($days, ...)` filters the full Mon–Sun grid, so "Balance dips below €0 on Jul 1" can headline a June view (July 1 being a trailing grid cell), and the risk count mixes months.
**Fix:** Filter to `$d->date->month === $displayMonth` before counting.

### IN-06: Whole-euro rounding renders "−€0" / "€1" for sub-euro balances

**File:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php:198-200`
**Issue:** `number_format(abs($minor / 100), 0, ...)` rounds half-up: −49 minor renders "−€0" and ±50 minor renders "€1" while the day panel shows two decimals — adjacent surfaces disagree near zero, exactly where the risk tint flips.
**Fix:** Accept as a deliberate density trade-off with a comment, or floor toward zero for the cell display.

### IN-07: `.cal-entry--paid .cal-day-num` CSS rule can never match

**File:** `resources/css/app.css:3119-3122`
**Issue:** `.cal-day-num` is a sibling of the entry rows, never a descendant of `.cal-entry--paid`, so the emerald paid styling is dead CSS; the only paid affordance is the inline ✓ span.
**Fix:** Change the selector to `.cal-entry--paid` (or remove the rule).

### IN-08: Global Escape handler persists prefs on every Escape press, popover open or not

**File:** `Modules/Calendar/Resources/views/livewire/calendar-page.blade.php:87`
**Issue:** `@keydown.escape.window="popoverOpen = false; $wire.persistAccountPrefs()"` is window-scoped, so every Escape anywhere on the page (including closing the day panel, which has its own Escape handler) fires a persistence round-trip even when the popover never opened.
**Fix:** Guard: `@keydown.escape.window="if (popoverOpen) { popoverOpen = false; $wire.persistAccountPrefs() }"`.

---

## Cross-file analysis notes (deep)

- **Call chain verified:** `CalendarPage` → `CalendarQuery` → `RecurringSeriesQuery` (Public), `ForecastQuery` (Public), `ExchangeRateService` (Public), `CounterpartyProfileQuery` (Public). No `Internal/*` cross-module imports. `forecast-page.blade.php` referencing `ProjectForecastJob::HORIZON_DAYS` is same-module (Forecasting) and legal.
- **Error propagation:** `ForecastQuery::forUser()` throws `NotFoundHttpException` for non-owned accounts; `CalendarQuery` always intersects against owned IDs first, so the exception path is unreachable from the calendar except in a delete race — acceptable.
- **Horizon-365 availability:** the daily scheduler (`routes/console.php:212`) and all forecast listeners loop the extended `HORIZON_DAYS`, so 365-day runs are produced; the calendar shows "—" only until the first post-upgrade projection tick (no on-demand dispatch from the calendar — acceptable, worth knowing for UAT).
- **`ProjectForecastJob` extension:** constructor validation, `uniqueId` baseline-vs-0 disambiguation, and the test suite are correct and consistent with the 5-horizon set.
- **Tests/Pest wiring:** Calendar module Feature/Unit suites are correctly bound to `RefreshDatabase` + module TestCase via the root `tests/Pest.php` map.

---

_Reviewed: 2026-06-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
