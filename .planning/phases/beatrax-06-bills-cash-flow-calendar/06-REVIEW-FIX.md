---
phase: beatrax-06-bills-cash-flow-calendar
fixed_at: 2026-06-12
review_path: .planning/phases/beatrax-06-bills-cash-flow-calendar/06-REVIEW.md
iteration: 1
findings_in_scope: 21
fixed: 21
skipped: 0
status: all_fixed
---

# Phase 6: Code Review Fix Report — Bills / Cash-Flow Calendar

**Fixed at:** 2026-06-12
**Source review:** 06-REVIEW.md (deep, 21 findings: 2 critical / 11 warning / 8 info)
**Scope:** `--all` (critical + warning + info)

**Summary:** 21/21 fixed. 19 atomic commits (IN-01 folded into CR-02's rewrite,
IN-02 folded into CR-01's mount rewrite — same code regions).

**Gates after all fixes:**
- `vendor/bin/pest --compact`: 3473 passed; only the 5 declared pre-existing
  failures remain (4× DriftAlerts GlobalDriftThresholdSettingTest + 1×
  CrossUserIsolationTest route-coverage guard — not phase-6 scope)
- `vendor/bin/phpstan analyse` (level 10 strict): No errors
- `vendor/bin/pint --test`: passed
- Calendar module suite: 57 passed (was 41 — 16 new/strengthened tests)

## Critical

### CR-01: Accounts popover state inverted for the default sentinel — **fixed** (`d60e072`)
`mount()` now materializes the D-02/D-03 defaults into EXPLICIT account-id
lists (entries = all owned, balance = spendable set via new public
`CalendarQuery::ownedAccountIds()` / `spendableAccountIds()`), so checkboxes
render checked in the default state and the first toggle does what the user
intends. `CalendarQuery::forMonth` semantics changed to `null` = never
configured (defaults) vs explicit array — including `[]` (deselect-all is now
representable and round-trips through persistence). A zero-account user
passes `null` so unlinked series still render. New tests: default
materialization, inversion regression (uncheck one → only that one hides),
explicit everything-off persistence round-trip, deselect-all unit test.

### CR-02: Balance line summed minor units across currencies; FX was a guaranteed no-op — **fixed** (`ea2e66e`)
`buildBalanceMap` now buckets `pointMinor` per (date, currency) and converts
each bucket via `ExchangeRateService::convertToBase` before summing (D-05) —
the source currency is the point's own currency, so the passthrough branch
only fires for genuinely base-currency buckets. Proven by a new EUR+USD
feature test (rate 2.0: asserts €2.234 renders, raw-sum €3.234 does not).

## Warnings

### WR-01: N+1 query storm in buildEntryMap — **fixed** (`43d8e56`)
Placement now runs first (in memory); metadata resolves only for placed
series via new batched Public methods:
`RecurringSeriesQuery::counterpartyIdsForSeriesIds()`,
`CounterpartyProfileQuery::identitiesForIds()` / `idsBySlugs()`, plus single
owned-roster name and cluster-key lookups. ≤5 metadata queries per render,
independent of series count (was 3N–5N).

### WR-02: ±7-day paid window over-matched weekly cadences — **fixed** (`042b213`)
Per orchestrator guidance: `matchWindowDays()` clamps the window to half the
cadence interval (weekly → ±3, daily → exact day), ±7 stays the cap for
monthly+. New test: one June 8 occurrence pays only the June 8 weekly entry;
June 1 stays missed. (Occurrence consumption was the "and/or" alternative;
the clamp alone removes the adjacent-entry double-match for every cadence.)

### WR-03: Backward projection fabricated pre-inception "missed" entries — **fixed** (`6db0012`)
Batched `seriesStartFloors()` (MIN(observed_at) per series, created_at
fallback, minus a MATCH_WINDOW_DAYS slack so an entry paid slightly late
isn't dropped) bounds both the backward walk and date collection. Tests:
history month before inception is empty; first-occurrence month renders;
slack keeps the expected-June-1/paid-June-3 entry.

### WR-04: Monthly stepping drifted the day-of-month anchor — **fixed** (`5cbf5e8`)
Occurrences are now computed by index from the anchor
(`anchor->addMonthsNoOverflow($k)` and weekly/quarterly/yearly equivalents)
instead of chained no-overflow steps. Tests: Jan-31-anchored bill renders
Feb 28 → Mar 31 → May 31 (no permanent drift) and the anchor month is
invertible.

### WR-05: 12-month ceiling not enforced at render time — **fixed** (`a7654f2`)
`resolveDisplay()` clamps to today + 12 months, matching the docblock's
claimed invariant (D-14, T-06-01). Test: `?year=2099&month=12` renders the
ceiling month "Jun 2027".

### WR-06: selectDay threw InvalidFormatException on tampered dates — **fixed** (`48a1a17`)
`checkdate()` validation before `CarbonImmutable::parse()`; impossible dates
("2026-13-01", "2026-99-99") now silently no-op. Test asserts no exception,
selectedDay stays null, and a real date still selects.

### WR-07: persistAccountPrefs stored client-controlled arrays unsanitized — **fixed** (`2a36070`)
`sanitizeAccountIds()` filters to ints, dedupes, and intersects against the
user's owned accounts before persisting (and re-assigns the properties so
component state matches the stored row). Test: foreign ids injected via
direct property set are stripped from both the state and the persisted JSON.

### WR-08: sodBalanceMinor faked €0,00 when the prior day had no data — **fixed** (`d67bd66`)
`CalendarDayDto::$sodBalanceMinor` is now `?int` (null = unknown); only
known non-computing EoDs feed the chain; today seeds from the FX-converted
`todayBalanceMinor` anchor sum; the day panel renders "—" when unknown.

### WR-09: Past-day "actual balance" contract unimplemented — **fixed** (`a85a463`)
Implemented (D-07 is a locked decision — amending the contract was not an
option). `buildBalanceMap` computes cumulative per-currency transaction sums
(base sum before the grid + daily deltas inside it, 2 queries), FX-converted
like the projection, and overrides the computing fill for days before today
— past-day actuals stay visible even while a forecast run is computing.
Added the previously-missing feature assertion (past cells show the real
−€15 balance, not "—") plus unit coverage; today's SoD now chains from
yesterday's actual.

### WR-10: aria-label announced negative balances as positive — **fixed** (`9d4d875`)
"minus " prefix added for negative end-of-day balances, mirroring the
visible "−".

### WR-11: CalendarPage bypassed the Clock contract — **fixed** (`4a804aa`)
`render()`, `resolveDisplay()`, `exceedsCeiling()`, `prevMonth()`,
`nextMonth()`, `selectDay()` all use the injected `Clock` (Livewire method
injection, same pattern as the existing DatabaseManager injection).

## Info

### IN-01: Dead code in buildBalanceMap — **fixed** (in CR-02 commit `ea2e66e`)
The CR-02 rewrite of the same block deleted the unused `$cursor`/`$gridEnd`
lead-in lines and their stale comment.

### IN-02: mount() duplicated the UserPreference cast with raw SQL — **fixed** (in CR-01 commit `d60e072`)
The CR-01 mount rewrite reads via `UserPreference::query()` model casts
(`toIntListOrNull()` keeps the null-vs-array distinction CR-01 requires).
Folded into CR-01 because both findings rewrite the same method.

### IN-03: Stale "30 / 60 / 90" docblocks — **fixed** (`0a0b941`)
Listener docblock and scheduler comment now reference
`ProjectForecastJob::HORIZON_DAYS` and note the Phase 6 queue-load impact.

### IN-04: Month-clamp tests asserted nothing — **fixed** (`e909705`)
Both clamp tests now assert the rendered "Jun 2026" label; the
ceiling-clamp test for `?year=2099` landed with WR-05.

### IN-05: Risk strip counted lead-in/lead-out days — **fixed** (`ad431f7`)
Filter now requires `$d->date->month === $displayMonth`.

### IN-06: Whole-euro rounding renders "−€0"/"€1" near zero — **fixed** (`28e1fef`)
Accepted as a deliberate density trade-off and documented in the template
(the reviewer's first suggested option): the cell corner is a glanceable
magnitude, the day panel is the precise two-decimal surface, and the risk
tint keys off the exact minor-unit sign.

### IN-07: Unreachable `.cal-entry--paid .cal-day-num` CSS — **fixed** (`7807ba6`)
Rule removed; a comment notes the ✓ span carries the paid affordance.

### IN-08: Global Escape persisted prefs even with the popover closed — **fixed** (`444b587`)
Handler now guards on `popoverOpen` before closing + persisting.

---

_Fixed: 2026-06-12_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
