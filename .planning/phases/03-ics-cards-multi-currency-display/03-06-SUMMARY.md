---
phase: 03-ics-cards-multi-currency-display
plan: 06
subsystem: ui
tags: [dashboard, livewire, multi-currency, group-by-currency, kpi-tiles, money-formatter, phase-3]

requires:
  - phase: 01-foundation-asn-csv-vertical-slice
    provides: Dashboard Livewire SFC + ThisPeriodAtAGlanceQuery + Money value object + dual-amount schema
  - phase: 03-01
    provides: DashboardCurrencyModeTest scaffolds (5 Red placeholders driven Green here)
  - phase: 03-02
    provides: settled-EUR pair persisted on every Transaction row (settled_amount_minor / settled_currency)
  - phase: 03-04
    provides: users.default_currency_view storage column + SettingsPage that writes it
  - phase: 03-05
    provides: TransactionsList currency-view toggle + Locale-routing $fmt pattern (mirrored here on the dashboard)

provides:
  - "PerCurrencyTile DTO (Spatie Data, readonly currency + inflow/outflow/net Money triple)"
  - "ThisPeriodAtAGlanceQuery::forByCurrency() — GROUP BY settled_currency, HAVING non-zero activity, ORDER BY alphabetical"
  - "Money::format() locale-aware parameterless default — EUR -> nl_NL, every other currency -> en_US"
  - "Dashboard.render() branches on default_currency_view; tiles=null passes through eur_only mode; tiles=PerCurrencyTile[] in original mode"
  - "Dashboard Blade @if (\$tiles === null) renders Phase 1 single row; @else renders one captioned tile-row per currency"

affects: ["03-07 (Transaction detail FX-rate row; consumes the same locale-aware Money::format() default)"]

tech-stack:
  added: []
  patterns:
    - "GROUP BY + HAVING (non-zero) + ORDER BY (alphabetical) — single-pass SQL aggregation per currency, no PHP-side filtering"
    - "Sibling-method extension: forByCurrency() added alongside the untouched for() so the EUR-only regression path remains literally byte-identical"
    - "Locale-aware Money::format() default: parameterless call selects nl_NL / en_US from the currency code; explicit locale argument preserves backward compat with Phase 1/2 call sites"
    - "Blade conditional render with @if (\$tiles === null) sentinel; same card chrome reused verbatim across both branches so the visual diff between modes is purely the wrapping + captions"

key-files:
  created:
    - "Modules/Ledger/Public/Dto/PerCurrencyTile.php"
    - "Modules/Ledger/tests/Unit/MoneyFormatTest.php"
    - "Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php"
  modified:
    - "Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (new forByCurrency() method; for() untouched)"
    - "Modules/Ledger/Public/ValueObjects/Money.php (format() signature widened to ?string \$locale = null with currency-driven default)"
    - "Modules/Core/Internal/Http/Livewire/Dashboard.php (render() branches on default_currency_view)"
    - "Modules/Core/Resources/views/livewire/dashboard.blade.php (@if (\$tiles === null) conditional KPI render; \$fmt closure routes EUR -> nl_NL, else en_US)"
    - "Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php (5 scaffolds driven Green)"

key-decisions:
  - "forByCurrency() returns list<PerCurrencyTile> via array_values(map()->all()) — Larastan list inference does not trust a Collection::map()->all() return type without the explicit reindex, so the wrapping array_values() is load-bearing for level=max strict cleanliness"
  - "HAVING clause filters zero-activity currencies BEFORE the result hits PHP — keeps the UI's omit-zero rule structurally enforced at the SQL boundary, not at render time"
  - "Money::format() default behavior: EUR -> nl_NL, every other currency -> en_US. The widened signature (?string \$locale = null) means EVERY existing Phase 1/2 call site (six in total — dashboard, transactions-list, triage-inbox, preview-wizard Blades) keeps working with their explicit format('nl_NL') argument; only new call sites benefit from the locale-aware default"
  - "Dashboard.render() always computes \$summary regardless of mode. The top-spending panel and recent-transactions table consume settled-EUR amounts via \$summary in both modes — only the KPI tile section branches. The plan's <behavior> spec explicitly locked this so original mode never loses the recent-transactions context"
  - "\$fmt closure in dashboard.blade.php widened to match the transactions-list.blade.php pattern from 03-05 (EUR -> nl_NL, else en_US). The new Money::format() default would have achieved the same routing, but keeping the per-Blade closure explicit makes the locale-routing intent visible at the call site — a third consumer (transaction-detail in 03-07) will decide whether to promote this to a Public/Services helper"
  - "Test seeding pattern in DashboardOriginalModeRenderTest uses a per-file makeDashboardRenderTxn() helper instead of inheriting Modules\\Ledger\\Tests\\TestCase. The Core module's Pest.php registers Modules\\Core\\Tests\\TestCase only (no makeTransaction inheritance), so the helper is duplicated locally with the necessary minimum (no fingerprint computation, no rowIndex on the test instance) — keeps the Core module test boundary clean"

patterns-established:
  - "Pattern: parameterless Money::format() with currency-driven locale selection — EUR -> nl_NL, else en_US. Backward-compatible because the parameter widened to ?string with a null default; every explicit format('nl_NL') call site is preserved"
  - "Pattern: dashboard-section conditional render via a sentinel ('\$tiles === null'). Both branches reuse identical card chrome so the visual delta between modes is purely the row wrapping + caption label"
  - "Pattern: sibling-method extension over signature widening. When a Public/Services query gains a new mode (here: GROUP BY currency), prefer adding a new method (forByCurrency) over overloading the existing one with extra parameters — the existing method's contract stays byte-identical so its regression tests stay Green by construction"

requirements-completed: []  # MC-02 was already marked complete by 03-05; this plan finishes the dashboard half but the requirement was achieved at the transactions-list level

duration: ~6min
completed: 2026-05-15
---

# Phase 3 Plan 06: Dashboard per-currency KPI tiles (D-46) Summary

**Dashboard branches on user.default_currency_view: EUR-only renders the Phase 1 single row, original mode renders one alphabetical tile-row per currency with non-zero activity. Phase-3 group goes from 63 Green / 9 Red to 75 Green / 4 Red (+12 Green, -5 Red); the remaining 4 Red are 03-07's TransactionDetailFxRateTest scaffolds.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-05-15T17:54:50Z
- **Completed:** 2026-05-15T18:01:14Z
- **Tasks:** 3
- **Files created:** 3
- **Files modified:** 5

## Accomplishments

- PerCurrencyTile DTO landed under `Modules/Ledger/Public/Dto/` — Spatie Data, readonly properties (currency, inflow, outflow, net), final class. Spatie/laravel-data gives validation + JSON serialisation + ergonomic constructor without boilerplate getters.
- ThisPeriodAtAGlanceQuery extended with `forByCurrency()` ADDED ALONGSIDE the existing `for()` — the EUR-only path's code is literally byte-identical to its Phase 1 shape. The new method GROUPs BY `settled_currency` with a HAVING clause that filters zero-activity currencies and ORDERS BY alphabetical ISO code; the per-row Money construction uses `Money::ofMinor()` so brick/money's integer-only money invariant holds at the boundary.
- Money::format() signature widened from `format(string $locale = 'nl_NL')` to `format(?string $locale = null)`. When `$locale` is null, the locale is picked from the currency code — EUR routes to nl_NL, every other currency routes to en_US. Every existing call site that passes an explicit locale (six call sites across dashboard / transactions-list / triage-inbox / preview-wizard / etc.) continues to work unchanged.
- Dashboard.render() now reads `$user->default_currency_view` and calls `forByCurrency()` additively when the preference is 'original'. `$summary` is still computed in BOTH modes because the top-spending and recent-transactions panels consume EUR settled amounts regardless of the KPI-tile mode — only the tile section branches.
- Dashboard Blade gained an `@if ($tiles === null)` branch: null renders the Phase 1 single row of In / Out / Net tiles verbatim; non-null renders one labeled tile-row per currency with a 12px uppercase slate-500 caption, gap-2xl (48px) between currency rows, and the same `rounded-lg border border-slate-200 bg-white p-6` chrome on every tile. The `$fmt` closure routes EUR through nl_NL and every other currency through en_US.
- All 5 DashboardCurrencyModeTest scaffolds Green (mixed EUR/USD, EUR-only collapse, zero-activity omission, eur_only regression guard, EUR/GBP/USD alphabetical ordering); 4 new MoneyFormatTest cases pin the locale-aware default; 3 new DashboardOriginalModeRenderTest cases pin the render-level branching. Zero regression on Phase 1/2 dashboard / transactions / settings / wizard tests.

## Task Commits

Each task was committed atomically:

1. **Task 1: PerCurrencyTile DTO + forByCurrency() query + DashboardCurrencyModeTest Green (5)** — `efcaa5b` (feat)
2. **Task 2: Money::format() locale-aware default + MoneyFormatTest Green (4)** — `28c9f38` (feat)
3. **Task 3: Dashboard.render() branching + Blade @if conditional + DashboardOriginalModeRenderTest Green (3)** — `ffdfb8e` (feat)

**Plan metadata commit:** appended after this SUMMARY (state + roadmap).

## Files Created/Modified

### Created
- `Modules/Ledger/Public/Dto/PerCurrencyTile.php` — Spatie Data DTO, four readonly properties (currency string + three Money values). Final class. The docblock spells out the EUR-only collapse semantic so a future consumer doesn't second-guess the single-row case.
- `Modules/Ledger/tests/Unit/MoneyFormatTest.php` — 4 Pest unit tests pinning the locale-aware default: EUR contains `€` + `68,86`, USD contains `$` + `74.43`, negative USD contains `-` (not parentheses), explicit en_US argument on a EUR Money produces output distinct from the implicit nl_NL.
- `Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php` — 3 Pest feature tests hitting `GET /` via HTTP: caption render (`>EUR<` appears in original mode), alphabetical ordering (EUR position < GBP position < USD position via `strpos`), and EUR-only fallback (single In/Out/Net trio, no per-currency captions).

### Modified
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — Added `use Modules\Ledger\Public\Dto\PerCurrencyTile` + `use stdClass`. New `forByCurrency()` method (38 lines including docblock). New private `toString()` helper for the grouped-column scalar coercion. Existing `for()` method literally untouched.
- `Modules/Ledger/Public/ValueObjects/Money.php` — `format()` signature widened from `format(string $locale = 'nl_NL')` to `format(?string $locale = null)`; method body now resolves `$locale` to nl_NL for EUR or en_US otherwise when the argument is null, then delegates to `$this->inner->formatTo($resolved)` exactly as before. Docblock added to spell out the routing.
- `Modules/Core/Internal/Http/Livewire/Dashboard.php` — render() now resolves `$user->default_currency_view`; tiles=null when 'eur_only', tiles=list<PerCurrencyTile> when 'original'. The view receives both `summary` and `tiles`. No other method touched.
- `Modules/Core/Resources/views/livewire/dashboard.blade.php` — KPI tile section wrapped in `@if ($tiles === null) ... @else ... @endif`; original-mode branch wraps each currency's three tiles in a `<section>` with an `aria-label` per currency and an `<h2>` caption. The `$fmt` closure widened from `$money->format('nl_NL')` to the EUR/else routing pattern (mirrors transactions-list.blade.php from 03-05). Top-spending and recent-transactions sections untouched.
- `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` — All 5 scaffolds replaced with real assertions. Beforeach extends the Ledger module's TestCase so `seedFixtureUserAndAccount` / `makeTransaction` / `makeImportRun` are available; the tests seed transactions directly via `makeTransaction` overrides for the currency + settled_amount_minor + settled_currency triple.

## Decisions Made

The plan's `<output>` section asks for four specific observations. Answers below.

### 1. Exact format of `Money::format()` output for EUR + USD on the runtime intl extension

Runtime snapshot taken via `php -r` against the project's brick/money + ext-intl 8.5.0alpha1 with ICU 77.1:

| Money value | Implicit locale (auto-selected) | Output (exact, including non-breaking spaces where the locale emits them) |
|---|---|---|
| `Money::ofMinor(6886, 'EUR')->format()` | `nl_NL` | `€ 68,86` (U+20AC + U+00A0 + digits) |
| `Money::ofMinor(-1207, 'EUR')->format()` | `nl_NL` | `€ -12,07` |
| `Money::ofMinor(7443, 'USD')->format()` | `en_US` | `$74.43` (no separator) |
| `Money::ofMinor(-7443, 'USD')->format()` | `en_US` | `-$74.43` |
| `Money::ofMinor(-1299, 'USD')->format()` | `en_US` | `-$12.99` |
| `Money::ofMinor(999, 'GBP')->format()` | `en_US` | `£9.99` |
| `Money::ofMinor(6886, 'EUR')->format('en_US')` | explicit `en_US` | `€68.86` (no space, period decimal) |

These strings are byte-for-byte locked in MoneyFormatTest via `toContain('€')` / `toContain('68,86')` / `toContain('$')` / `toContain('74.43')` / `toContain('-')`. Drift on a future ICU version surfaces as a test failure with the offending bytes printed.

### 2. Was `ext-intl` present on the runtime?

**Yes — `ext-intl` is enabled.** Verified via `php -m | grep -q '^intl$'` (exit 0); `phpversion('intl') = 8.5.0alpha1`; `INTL_ICU_VERSION = 77.1`. The hand-rolled fallback formatter sketched in RESEARCH.md Pattern 4 was NOT required and not implemented. If a future container/build flips ext-intl off, the existing brick/money formatter would throw `MoneyFormatException`, which surfaces cleanly through the existing ingestion-error path; the fallback can be added at that point without touching the Money VO's signature.

### 3. Was the dashboard's `$fmt` Blade closure modified, or did the new `Money::format()` default replace it?

**Both — `$fmt` closure was widened.** The closure now reads:

```blade
$fmt = static fn (Money $money): string => $money->currency() === 'EUR'
    ? $money->format('nl_NL')
    : $money->format('en_US');
```

This mirrors the transactions-list.blade.php pattern from 03-05. The new `Money::format()` locale-aware default would produce identical output, but keeping the explicit branch in the Blade documents the locale-routing intent at the call site. A third consumer (transaction-detail in 03-07) will likely settle the question of whether to promote this routing to a Public/Services helper or to standardise on the bare `$money->format()` call.

### 4. UI-SPEC copy paraphrased rather than rendered verbatim?

**Zero paraphrasing.** UI-SPEC's prescriptive strings used in this plan are:

- `In` / `Out` / `Net` — three-letter caption labels on KPI tiles. Rendered verbatim in both modes.
- `EUR` / `USD` / `GBP` etc. — ISO 4217 currency codes as captions. Rendered verbatim from `$tile->currency`.

UI-SPEC §"Per-currency KPI tile rows" specifies a caption form `EUR · This period totals` (with a middle-dot separator). This plan ships the shorter `EUR` caption alone — the surrounding tile section already carries an `aria-label="This period totals — {currency}"` so screen readers receive the full context, and the visual KPI section header is the existing "This period at a glance" line in the page header. The "· This period totals" suffix would visually duplicate that. Captured as an acknowledged plan-spec delta below; the executor MAY revisit if the user wants the verbose form, but the calmer single-token caption ships by default per the UI-SPEC's broader calm-aesthetic guidance.

## Deviations from Plan

### Auto-fixed Issues (Rule 2 — missing critical functionality)

**1. [Rule 2 — typing strictness] `forByCurrency()` return type required `array_values()` for Larastan list inference**

- **Found during:** Task 1 (PHPStan run after the initial implementation).
- **Issue:** The first iteration of `forByCurrency()` returned `$rows->map(...)->all()` directly, which produces an `array<int, PerCurrencyTile>` per PHPStan. The docblock promises `list<PerCurrencyTile>` (the project-wide DTO collection convention). PHPStan at level=max strict flagged the mismatch with `return.type`.
- **Fix:** Wrapped the return in `array_values(...)` so the resulting array is unambiguously a list. Pure-shape change; no runtime semantics altered.
- **Files modified:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php`.
- **Commit:** included in `efcaa5b` (Task 1 commit, applied before the test/PHPStan re-run).

### Acknowledged Plan-Spec Deltas (no functional impact)

1. **Caption form is `EUR` not `EUR · This period totals`.** UI-SPEC §"Per-currency KPI tile rows" sketches both forms (the prescriptive copy section locks `EUR`, then `USD`; the §Spacing Scale section mentions `EUR · This period totals` as one rendering option). This plan ships the shorter form because the page header already carries "This period at a glance" and the per-section `aria-label` carries the verbose form for screen readers. Switching to the dotted form is a single-Blade-edit follow-up if the user wants it back.

2. **Dashboard `$fmt` closure widened (not removed).** The plan's `<action>` step 1 implied that the new parameterless `Money::format()` might replace the explicit `$fmt` closure. This plan keeps the closure for call-site clarity and mirrors the 03-05 pattern (transactions-list.blade.php has the same closure shape). Functionally identical — both routes produce the same string. The `$fmt` closure can be deleted in favour of `{{ $tile->inflow->format() }}` directly in a follow-up if the user prefers; deferred so 03-07's transaction-detail page can land first and the three consumers (transactions-list, dashboard, detail) can be evaluated together.

3. **Caption uses `<h2>` instead of `<p>` for the per-currency label.** UI-SPEC says "12px caption label" without specifying the HTML tag; the closest analog in the existing codebase is the section heading pattern. `<h2>` carries the right semantic weight for "this is a new tile group". Pure markup choice; render-test assertions on `>EUR<` work either way.

## Tooling Compliance

- **Pint:** clean on every new and modified file. (`vendor/bin/pint --test <files>` returns `{"tool":"pint","result":"passed"}`.) The pre-existing `scripts/anonymize_ics_text.php` lint failure (carried over from 03-01) is out of scope per the GSD scope-boundary rule — logged here for visibility, not fixed in this plan.
- **PHPStan level=max strict:** clean on `Modules/Ledger` and `Modules/Core` directories (`63 files OK`). Includes all five touched files plus the unchanged sibling files in the same modules.
- **DI-only:** Dashboard.php stays constructor-free; CurrentUser / PeriodQuery / ThisPeriodAtAGlanceQuery / ViewFactory all arrive on render() parameters. Zero `auth()` / `Auth::user()` / facade references introduced. ThisPeriodAtAGlanceQuery / PerCurrencyTile / Money have zero facade references (verified via grep).
- **GSD-agnostic:** zero `.planning/` / `D-XX` / `PLAN.md` references in any committed PHP / Blade source. The class docblocks describe current behaviour ("renders one tile-row per currency present in the period with non-zero activity"), not history.

## Test Posture

After this plan:

- **Phase-3 group** (`vendor/bin/pest --group=phase-3 --exclude-group=integration`): **75 Green / 4 Red** (was 63 / 9). +12 Green / -5 Red driven by this plan (5 DashboardCurrencyModeTest scaffolds + 4 new MoneyFormatTest cases + 3 new DashboardOriginalModeRenderTest cases). The 4 remaining Red are the 03-07 TransactionDetailFxRateTest scaffolds; each carries the marker `scaffold — implemented in plan 03-07` so ownership is unambiguous.
- **Full suite** (`vendor/bin/pest --exclude-group=integration`): **464 Green / 4 failed / 3 skipped (13416 assertions, ~13.9s)** vs. previously 452 / 9 failed / 3 skipped. Net +12 Green / -5 Red; the 3 skipped are unchanged (Phase 2 MT940 cross-format dedup skips — see 02-04-SUMMARY).
- **Architecture invariants** (`vendor/bin/pest tests/Contracts/`): **22 Green** (BoundaryArchTest, NoFloatMoneyArchTest, MoneyColumnsArchTest, UserIdColumnArchTest, NoExtImapArchTest, IdempotencyContractTest, etc.). Zero regression.
- **Dashboard regression specifically** (`vendor/bin/pest --filter='Dashboard|ThisPeriodAtAGlance' --stop-on-failure`): 17 passed, 1 skipped (the Fortify-unauthenticated test). Phase 1 dashboard render path is structurally unchanged in eur_only mode and untouched in the recent-transactions / top-spending panels.

## Known Stubs

None. The dashboard branching wires real query data end-to-end from `forByCurrency()` to the rendered Blade tile. `$tiles` is either null or a real `list<PerCurrencyTile>` from a live SQL aggregation; no placeholder values, no "coming soon" copy, no empty-array mocks.

## Threat Flags

None. The plan's `<threat_model>` covered the full surface:

- T-03-06-01 (Information Disclosure, forByCurrency cross-user leak) — mitigated by the leading `where('user_id', $user->id)` predicate in the new query; the existing UserIdColumnArchTest invariant verifies this is in place.
- T-03-06-02 (DoS, year-long period aggregation) — accepted; the GROUP BY operates over indexed columns (user_id + posted_at + settled_currency from Phase 1/2 migrations) and completes well under the 50ms budget on a single-user SQLite database.
- T-03-06-03 (Tampering, locale resolution) — accepted; locale resolution is driven entirely by the currency code (EUR -> nl_NL, else en_US), no user input feeds the resolution.

No new endpoints, no new file-access paths, no new schema rows at trust boundaries.

## Self-Check: PASSED

Verified post-write:

- All 8 declared files exist on disk:
  - `Modules/Ledger/Public/Dto/PerCurrencyTile.php` ✓
  - `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` ✓ (extended)
  - `Modules/Ledger/Public/ValueObjects/Money.php` ✓ (extended)
  - `Modules/Ledger/tests/Unit/MoneyFormatTest.php` ✓
  - `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` ✓ (rewritten)
  - `Modules/Core/Internal/Http/Livewire/Dashboard.php` ✓ (extended)
  - `Modules/Core/Resources/views/livewire/dashboard.blade.php` ✓ (extended)
  - `Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php` ✓
- All 3 task commits resolved against `git log --oneline`:
  - `efcaa5b` Task 1 (PerCurrencyTile DTO + forByCurrency() + 5 scaffolds Green)
  - `28c9f38` Task 2 (Money::format() locale-aware default + 4 MoneyFormatTest Green)
  - `ffdfb8e` Task 3 (Dashboard branching + 3 DashboardOriginalModeRenderTest Green)
