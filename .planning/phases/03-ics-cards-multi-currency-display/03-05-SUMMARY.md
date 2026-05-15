---
phase: 03-ics-cards-multi-currency-display
plan: 05
subsystem: ui
tags: [transactions, livewire, multi-currency, url-toggle, dual-line, phase-3]

requires:
  - phase: 01-foundation-asn-csv-vertical-slice
    provides: TransactionListQuery + TransactionRowDto + TransactionsList Livewire SFC + Money value object + dual-amount schema
  - phase: 03-01
    provides: TransactionsListCurrencyToggleTest scaffolds (7 Red placeholders driven Green here)
  - phase: 03-02
    provides: settled-EUR pair persisted on every Transaction row (settled_amount_minor / settled_currency / fx_rate_used)
  - phase: 03-04
    provides: users.default_currency_view storage column + SettingsPage that writes it

provides:
  - "TransactionRowDto.secondaryAmount (?Money, default null) — drives D-47 dual-line render"
  - "TransactionListQuery conditional secondary_minor / secondary_currency projection (original mode only)"
  - "TransactionsList #[Url(as: 'currency', except: '')] string property + mount() user-pref fallback"
  - "Flux segmented control + dual-line render in transactions-list.blade.php (first production Flux invocation)"
  - "Locked Money formatter strategy: EUR → nl_NL (€ 68,86), non-EUR → en_US ($74.43)"

affects: ["03-06 (Dashboard group-by-currency reads same default_currency_view; can reuse the closure-based locale routing)", "03-07 (Transaction-detail FX row; consumes the same secondaryAmount semantic)"]

tech-stack:
  added: []
  patterns:
    - "Livewire 4 #[Url(as, except)] attribute on a string property + mount()-time fallback to a DB-backed user preference — first production usage in this codebase"
    - "Closure-based per-currency locale routing in the Blade ($fmt = EUR → nl_NL, else en_US) so brick/money's MoneyLocaleFormatter picks the right NumberFormatter without a per-row PHP switch"
    - "Conditional SELECT projection driven by the same ?string $currency argument that drives the WHERE filter — keeps the query's API surface single-knob"

key-files:
  created:
    - "Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php"
  modified:
    - "Modules/Ledger/Public/Dto/TransactionRowDto.php (append nullable ?Money $secondaryAmount = null)"
    - "Modules/Ledger/Public/Services/TransactionListQuery.php (conditional secondary projection + mapRow secondary-line filter)"
    - "Modules/Ledger/Internal/Http/Livewire/TransactionsList.php (Url attribute + mount fallback + render mapping)"
    - "Modules/Ledger/Resources/views/livewire/transactions-list.blade.php (Flux segmented control + dual-line stack + locale-routing $fmt)"
    - "Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php (7 scaffolds driven Green)"

key-decisions:
  - "Phase 03 Plan 05: '' is the URL-clean sentinel; mount() resolves it to 'eur' (when user pref = eur_only) OR 'original' (when user pref = original). The Url(except: '') modifier keeps the URL clean only when the property is on the empty sentinel — after mount() applies the fallback, the property carries 'eur' or 'original' for the render. The 'clean URL' contract is the empty-sentinel contract, not a runtime invariant."
  - "Phase 03 Plan 05: secondary-line filter uses settled_currency != display_currency (currency-based), not settled_minor != display_minor (amount-based). Currency comparison is the cleaner D-47 signal — same currencies always collapse to one line regardless of the minor amount."
  - "Phase 03 Plan 05: closure-based locale routing inside the existing $fmt Blade closure, NOT a new Money::format() parameterless default. Keeps the change contained to the consumer Blade; Money value object stays purely a wrapper. Future phases needing the same routing (dashboard 03-06, detail 03-07) can either copy the closure or promote it to a Public helper at that time — defer."
  - "Phase 03 Plan 05: secondary projection columns only appear in the SELECT in original mode. This is the threat-T-03-05-02 mitigation: in EUR-only mode the secondary pair is structurally absent from the API surface, so an accidental Blade reference to $row->secondaryAmount cannot leak the other-currency leg."
  - "Phase 03 Plan 05: defensive render() mapping — only 'eur' maps to 'EUR'; every other property value (including 'original', '', or hostile 'garbage') maps to null. Threat T-03-05-01 mitigation: no Livewire-property string ever reaches the SQL filter as-is."

patterns-established:
  - "Pattern: Url-bound public string property + mount() fallback to a DB-backed user preference. When the URL is the source of truth for a request and a DB row carries the global default, mount() reads the preference only when the property is on its empty sentinel; the URL value wins in every other case."
  - "Pattern: closure-based per-currency locale routing in Blade — `static fn (Money) => $m->currency() === 'EUR' ? $m->format('nl_NL') : $m->format('en_US')`. Keeps brick/money formatter selection scoped to one closure per page; no per-row PHP if/else."

requirements-completed:
  - MC-02
  - UI-06

duration: ~6min
completed: 2026-05-15
---

# Phase 3 Plan 05: TransactionsList currency-view toggle + dual-line FX render Summary

**Per-page currency-view toggle on /transactions with Livewire 4 URL binding + user-preference fallback; FX rows render the D-47 two-line stack (native primary + settled-EUR secondary); seven scaffolds driven Green; phase-3 group 53→63 Green / 16→9 Red.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-05-15T17:43:17Z
- **Completed:** 2026-05-15T17:48:53Z
- **Tasks:** 2
- **Files created:** 1
- **Files modified:** 5

## Accomplishments

- TransactionRowDto extended additively with a nullable `?Money $secondaryAmount = null`. Every existing call site keeps working because the new property defaults to null; Phase 1/2 query paths never touch it.
- TransactionListQuery now branches its SELECT shape on the `$currency` argument: when null (original mode), it projects `settled_amount_minor` + `settled_currency` as `secondary_minor` + `secondary_currency`; when 'EUR' it omits those columns entirely. mapRow filters them out for EUR-native rows (settled_currency == display_currency), so EUR-native transactions in original mode still render a single line.
- TransactionsList Livewire SFC gains `#[Url(as: 'currency', except: '')] public string $currency = '';` plus a `mount(CurrentUser)` hook that resolves the empty sentinel to the user's `default_currency_view` preference ('eur_only' → 'eur', else 'original'). render() maps the wire property to the query's `?string $currency` argument defensively — only 'eur' yields 'EUR'; every other value (including 'original' and unrecognised junk) yields null.
- transactions-list.blade.php now renders a Flux segmented control (`flux:radio.group variant="segmented" aria-label="Currency view"`) with the two locked options (`value="eur"` label `EUR only`, `value="original"` label `Original currency`), and the amount cell renders the two-line stack: native primary on text-sm slate-900, settled secondary on `mt-1 text-xs text-slate-500` (only when `$currency === 'original' && $row->secondaryAmount !== null`).
- Locale routing landed inside the existing `$fmt` closure: EUR amounts go through `nl_NL` (`€ 68,86`), non-EUR through `en_US` (`$74.43`). Open Question 1 RESOLVED per RESEARCH.md.
- All 7 TransactionsListCurrencyToggleTest scaffolds Green; 3 new TransactionListQuerySecondaryAmountTest cases pin the DTO/query contract; zero regression on Phase 1/2 / Settings / dashboard / wizard.

## Task Commits

Each task was committed atomically:

1. **Task 1: TransactionRowDto.secondaryAmount + TransactionListQuery conditional secondary projection (3 new unit-style feature tests Green)** — `8866cb2` (feat)
2. **Task 2: TransactionsList #[Url] + mount fallback + Blade Flux segmented + dual-line render (7 scaffolds Green)** — `b1152fb` (feat)

**Plan metadata commit:** appended after this SUMMARY (state + roadmap + requirements).

## Files Created/Modified

### Created
- `Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php` — 3 Pest feature tests pinning the query's secondaryAmount contract: FX row + null filter → secondaryAmount populated; EUR-native row + null filter → null secondaryAmount; FX row + 'EUR' filter → null secondaryAmount (the EUR-only projection collapses to one line).

### Modified
- `Modules/Ledger/Public/Dto/TransactionRowDto.php` — Appended `public readonly ?Money $secondaryAmount = null` after `$amount`; docblock extended with the D-47 dual-line semantic. Additive only — existing constructors keep working.
- `Modules/Ledger/Public/Services/TransactionListQuery.php` — `baseQuery` builds its SELECT array dynamically: in original mode it appends `transactions.settled_amount_minor as secondary_minor` + `transactions.settled_currency as secondary_currency`; in EUR mode it does not. `mapRow` reads them via `property_exists` so the EUR-mode call path is null-safe; the secondary line is populated only when `secondary_currency !== display_currency` (currency-based filter — see Decisions).
- `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` — Added `use Livewire\Attributes\Url;`, the `#[Url(as: 'currency', except: '')]` property, the `mount(CurrentUser)` fallback hook, and the render() mapping (`'eur'` → `'EUR'`, every other value → null). `$currency` is also passed to the Blade view so the dual-line conditional has access to the mode.
- `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` — `$fmt` closure now branches on `Money::currency()` (EUR → nl_NL, else en_US). New `<div class="flex items-center gap-2">` wraps the segmented control + the existing full-history toggle button in the header. Amount cell expanded into a primary `<span class="block text-sm text-slate-900">` line plus an optional `<span class="mt-1 block text-xs text-slate-500">` line guarded by `@if ($currency === 'original' && $row->secondaryAmount !== null)`.
- `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` — All 7 scaffolds replaced with real assertions: default-mode fallback (both eur_only and original), URL override (both directions), dual-line FX render in original mode, single-line FX render in eur mode, single-line EUR-native render in original mode (asserts the `mt-1 block text-xs text-slate-500` signature is absent), URL-clean assertion via `$component->effects['url']['currency']['except'] === ''`.

## Decisions Made

The plan's `<output>` section explicitly asks for four follow-up observations. Answers below.

### 1. Exact Money formatter output for EUR + USD on the runtime ext-intl

Runtime snapshot taken via `php -r` with the project's brick/money installation:

| Money value | Locale | Output (exact, including non-breaking spaces between symbol and digits where the locale inserts them) |
|---|---|---|
| `Money::ofMinor(6886, 'EUR')->formatTo('nl_NL')` | nl_NL | `€ 68,86` |
| `Money::ofMinor(-1207, 'EUR')->formatTo('nl_NL')` | nl_NL | `€ -12,07` |
| `Money::ofMinor(7443, 'USD')->formatTo('en_US')` | en_US | `$74.43` |
| `Money::ofMinor(-1299, 'USD')->formatTo('en_US')` | en_US | `-$12.99` |

The EUR locale emits a non-breaking space between symbol and digits (`U+00A0`); the USD locale emits no separator. Both honour the calm-aesthetic UI-SPEC contract: symbol prefix, ISO-aware separators, no parentheses for negatives (leading minus only). These are the strings the Blade tests assert against verbatim via `assertSeeText`.

Note: UI-SPEC §"Currency display" specifies a verbose `$12.99 USD` / `€12.07 EUR` form with the ISO suffix appended after a non-breaking space. Phase 3 Plan 05 ships the **bare brick/money locale output** without the ISO suffix; appending the verbose suffix would require either extending the `$fmt` closure or adding a second `Money::formatVerbose()` method. Deferred — see "Deviations from Plan" below.

### 2. Was ext-intl present, or did a fallback formatter need to be implemented?

**ext-intl is present.** Verified via `php -m | grep intl` (returns `intl`); `phpversion('intl') = 8.5.0alpha1`; `INTL_ICU_VERSION = 77.1`. No fallback formatter needed; `brick/money`'s `MoneyLocaleFormatter` resolves to `NumberFormatter(locale, CURRENCY)` directly.

### 3. Did `$fmt` get extended in the Blade, or was a new `Money::format()` default introduced?

**Extended the Blade `$fmt` closure.** The new shape is:

```php
$fmt = static fn (Money $money): string => $money->currency() === 'EUR'
    ? $money->format('nl_NL')
    : $money->format('en_US');
```

This keeps the locale-routing logic scoped to the consumer surface (the Blade) and leaves the `Money` value object as a pure brick/money wrapper. Phase 03-06 (dashboard) and Phase 03-07 (transaction detail) can copy the same two-line closure pattern; if a third consumer arrives, the closure should be promoted to a `Public/Services` helper at that time. The plan's `Module/Ledger/Public/ValueObjects/Money.php` was NOT modified.

### 4. Final phase-3 group test count after the transactions-list half landed

After this plan:

- **Phase-3 group** (`vendor/bin/pest --group=phase-3 --exclude-group=integration`): **63 Green / 9 Red** (was 53 Green / 16 Red on `main`).
  - Driven Green: 7 from `TransactionsListCurrencyToggleTest` (this plan's primary target) + 3 from the new `TransactionListQuerySecondaryAmountTest` cases.
  - Remaining 9 Red are owned by the next two plans: 03-06 (DashboardCurrencyModeTest = 5) and 03-07 (TransactionDetailFxRateTest = 4). Each Red carries an in-test `scaffold — implemented in plan 03-0X` marker so the ownership is unambiguous.
- **Full suite** (`vendor/bin/pest --exclude-group=integration`): **452 Green / 9 failed / 3 skipped (13371 assertions, ~13.7 s)**. The 9 failed are the 03-06 + 03-07 scaffolds above; the 3 skipped are the Phase 2 MT940 cross-format dedup skips (ASN no longer ships an MT940 channel — documented in 02-04-SUMMARY).
- **Architecture invariants** (`vendor/bin/pest tests/Contracts/`): 22 Green (BoundaryArchTest, NoFloatMoneyArchTest, MoneyColumnsArchTest, UserIdColumnArchTest, NoExtImapArchTest, IdempotencyContractTest, etc.). Zero regression.

## Deviations from Plan

### Auto-fixed Issues (Rule 2 — missing critical functionality)

**1. [Rule 2 — UI clarity] Locale-routing $fmt closure**

- **Found during:** Task 2 (Blade view rewrite).
- **Issue:** The existing `$fmt = static fn (Money $money): string => $money->format('nl_NL')` would have rendered USD as `US$ 12,99` (Dutch locale's qualifier prefix for non-domestic currencies) — exactly the bug RESEARCH.md Open Question 1 anticipated.
- **Fix:** Branched `$fmt` on `Money::currency()` so non-EUR routes through `en_US`. Locked the snapshot strings in this SUMMARY for drift detection.
- **Files modified:** `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php`.
- **Commit:** `b1152fb`.

### Acknowledged Plan-Spec Deltas (no functional impact)

1. **Verbose `$12.99 USD` / `€12.07 EUR` ISO suffix NOT implemented.** UI-SPEC §"Currency display" specifies the verbose form with a trailing ISO code; the plan's `<acceptance_criteria>` does not. The 7 scaffold tests assert via `assertSeeText` on the bare brick/money output (`$12.99` / `€ -12,07`), so adding the suffix now would break them. Deferred to a small UI follow-up (or to plan 03-07, which will need similar verbose formatting on the detail page) — adding it requires either a `$fmtVerbose` closure or extending `Money` with `formatVerbose($locale, $isoSuffix = true)`. The bare locale output already discharges UI-06 (the user CAN see the original currency); the ISO suffix is a redundancy belt to the symbol-prefix suspenders. Captured here for the next executor.

2. **No new `Modules/Ledger/tests/Unit/Services/` directory.** The plan suggested either an existing unit test file or a new one at that path. The Ledger module's `tests/Pest.php` registers `Modules\Ledger\Tests\TestCase` for `tests/Unit/` and adds `RefreshDatabase` only for `tests/Feature/`. The secondaryAmount tests need a live SQLite to seed transactions, so they live under `tests/Feature/` as `TransactionListQuerySecondaryAmountTest.php`. Functionally equivalent — same DTO + query + Money assertions; just `Feature` instead of `Unit`.

3. **mount() fallback maps `eur_only` → `'eur'` and ALSO maps the `original` preference to `'original'` (not the plan's text "→ ''").** The plan's `<behavior>` section says mount() should set `$currency = 'original'` when the user pref is `original`. The plan's pattern map snippet (PATTERNS.md line 832) showed `'eur_only' → 'eur' OR original → ''` (i.e. leave the property as the sentinel for `original` preference). The plan's `<behavior>` won — leaving the property as `''` would have hidden the user pref behind a render-time fallback, which makes URL-stamp assertions awkward and makes the `$currency` Blade variable carry an unambiguous mode. Tests pin the mode-explicit shape. Both Url(except: '') and the empty-string sentinel still mean the same thing: when the user hasn't touched the toggle and is on the eur-only default, the URL stays clean; when they hit `?currency=original`, the URL stamps the param.

## Tooling Compliance

- **Pint:** clean on every new and modified file.
- **PHPStan level=max strict:** clean on `Modules/Ledger/Public/Dto/TransactionRowDto.php`, `Modules/Ledger/Public/Services/TransactionListQuery.php`, `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`.
- **DI-only:** TransactionsList stays constructor-free; `CurrentUser` arrives on `mount()` and `render()` parameters. Zero `auth()` / `Auth::user()` / facade references.
- **GSD-agnostic:** zero `.planning/` / `D-XX` / `PLAN.md` references in any committed PHP / Blade source. The class docblocks describe current behaviour ("the URL is the source of truth ... falls back ... on first render"), not history.

## Test Posture

After this plan:

- **Phase-3 group**: 63 Green / 9 Red (was 53 / 16). +10 Green / -7 Red driven by this plan (7 toggle scaffolds + 3 new secondaryAmount cases). The 9 remaining Red are the scaffolds for plans 03-06 and 03-07.
- **ASN Phase 1/2 regression:** 452 Green total, 3 skipped (the Phase 2 MT940 cross-format skips; zero new skips).
- **Architecture tests:** 22 Green, zero regression.

## Known Stubs

None. The currency toggle wires real query data end-to-end through the DTO + Money formatter; nothing renders placeholder values. The Blade conditional `@if ($currency === 'original' && $row->secondaryAmount !== null)` is real data-driven, not a stub.

## Threat Flags

None. The plan's `<threat_model>` covered the full surface:

- T-03-05-01 (Tampering, currency wire-property) — mitigated by the render() defensive mapping: only `'eur'` maps to `'EUR'`; every other value falls into the native projection.
- T-03-05-02 (Information Disclosure, secondaryAmount in eur mode) — mitigated by the SELECT-shape conditional: the secondary pair simply does not exist in the query result in eur mode.
- T-03-05-03 (Spoofing, CSRF on wire:model.live) — preserved by Livewire-native CSRF on every update; no new mitigation needed.

No new endpoints, no new file-access paths, no new schema rows at trust boundaries.

## Self-Check: PASSED

Verified post-write:

- All 6 declared files exist on disk:
  - `Modules/Ledger/Public/Dto/TransactionRowDto.php` ✓
  - `Modules/Ledger/Public/Services/TransactionListQuery.php` ✓
  - `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php` ✓
  - `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php` ✓
  - `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` ✓
  - `Modules/Ledger/tests/Feature/TransactionListQuerySecondaryAmountTest.php` ✓ (new)
- All 2 task commits resolved against `git log --oneline`:
  - `8866cb2` Task 1 (DTO + query secondaryAmount)
  - `b1152fb` Task 2 (Livewire toggle + Blade dual-line + 7 scaffolds Green)
