# Phase 3: ICS Cards + Multi-Currency Display - Research

**Researched:** 2026-05-13
**Domain:** ICS Cards CSV ingestion + multi-currency display (EUR vs original-currency toggle) + minimal Settings page
**Confidence:** HIGH for stack, patterns, and pitfalls anchored in Phase 1/2 shipped artefacts. MEDIUM for ICS-specific CSV shape (rightly deferred to Wave 0 per D-32). HIGH for brick/money + Livewire 4 `#[Url]` + Flux segmented control.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**ICS Export Format Coverage**

- **D-31:** **CSV only.** No `phpoffice/phpspreadsheet` dependency in Phase 3. The ICS adapter mirrors `AsnCsvAdapter`'s lazy-Generator + BOM-safe + `league/csv` pattern. Excel ingestion is deferred — revisit if the user's ICS portal ever drops the CSV export.
- **D-32:** **Real-fixture-first Wave 0.** The user provides a raw ICS CSV export; **anonymisation is OUR job, not the user's**. Wave 0 receives the raw file, anonymises card numbers / cardholder names / any cross-referenceable identifiers, preserves dates / amounts / currencies / merchants verbatim, and writes the redacted fixture under `Modules/Ingestion/tests/fixtures/ics/`. The Wave 0 plan reports back on (a) column layout, (b) source_ref availability (D-34), and (c) FX-row structure (D-35) — and only then is the adapter plan written. Mirrors Phase 2 Plan 1's enablement wave.
- **D-33:** **Two-step grouped wizard picker.** Refactor `UploadWizard`'s flat `['asn-csv', 'asn-camt053', 'asn-mt940']` dropdown into a two-step picker: first "Which issuer?" (ASN / ICS), then "Which format?" (ASN → CSV / CAMT.053 / MT940; ICS → CSV). The `sourceFormat` field still stores the leaf key (`ics-csv`); `HeaderSniffer` still validates the declared format.
- **D-34:** **`source_ref` strategy is deferred to Wave 0 fixture inspection.** If the ICS CSV exposes a stable per-transaction reference (auth code / slip number / txn ID), the adapter sets `source_ref` from that column. If not, `source_ref` is `NULL` and the v3 fingerprint tuple is the only dedup anchor.
- **D-35:** **FX-row shape is deferred to Wave 0 fixture inspection.** Two possibilities the adapter must handle: (a) one CSV row with both original-currency and settled-EUR columns → adapter yields one `SourceTransactionDto`; (b) two CSV rows per FX charge (merchant line + FX-conversion line) → adapter rolls them up into one canonical row, similar to how Phase 4's PayPal event-log rollup will work. The wizard preview MUST continue to show one preview row per canonical transaction regardless of source-row count.

**ICS Account Modeling**

- **D-36:** **Single ICS Account in Phase 3.** The user has only one ICS card today. The `accounts` table records one row with `type = 'ics_card'` (new value alongside existing `bank` / `paypal` etc.), nameable by the user during first upload, currency `EUR` (the settlement currency). No `card_last4` column, no per-card subtable.
- **D-37:** **No card_number extraction in Phase 3.** The CSV's card-number column (if any) is dropped at the adapter boundary and NOT preserved in `rawPayload`.
- **D-38 (Claude's discretion):** **Wizard prompts to name the ICS Account on first upload.** Mirrors Phase 1's "unknown IBAN → name the account" step (D-14), but the trigger is "no Account of type `ics_card` exists yet" rather than IBAN-not-found.

**FX Rate + ICS Markup Handling**

- **D-39:** **`fx_rate_used` is derived: `settled_amount_minor / amount_minor`** with `decimal(18,8)` precision. Populated whenever both legs are present on the source row. This is the **effective** rate (markup-inclusive). NULL when no FX took place (EUR-native rows).
- **D-40:** **ICS markup is invisible at the canonical layer in Phase 3.** When the CSV exposes an explicit markup / fee row alongside the merchant line, the adapter rolls both into one canonical row whose `settled_amount_minor` already includes the markup. The markup figure remains recoverable from `rawPayload`.
- **D-41:** **EUR-native rows mirror native → settled.** For a EUR-native ICS row, the canonical row sets `settled_amount_minor = amount_minor`, `settled_currency = currency`, `fx_rate_used = NULL`. No schema change required.
- **D-42:** **`SourceTransactionDto` gains nullable `settledAmountMinor`, `settledCurrency`, `fxRateUsed` fields.** Phase 1/2 ASN adapters keep yielding `null`. NormalizeStage substitutes `settled = native`, `fx_rate_used = null` when the source DTO leaves them `null`.
- **D-43 (implicit / no change):** **The v3 fingerprint tuple uses native `amount_minor` + `currency`.** Fingerprint composer needs no change for multi-currency.

**Dual-Currency Display UX**

- **D-44:** **Toggle scope = both global default + per-page override.** A new `default_currency_view` user-preference field (`'eur_only'` | `'original'`) is read from Settings as the default. The `/transactions` page surfaces a Flux segmented control that, when toggled, overrides the default via a `?currency=` URL query parameter (`?currency=eur` or no query for default). URL is the source of truth for the request; refresh-stable.
- **D-45:** **Ship a minimal `/settings` page in Phase 3.** Discharges Phase 1's deferred Settings UI question (D-19). Surfaces: (a) `default_currency_view` toggle, (b) the existing `period_start_day` integer (migrated from install-only config to user-editable). Lives under `Modules/Core/Internal/Http/Livewire/SettingsPage.php`.
- **D-46:** **Dashboard tiles respond to the toggle.** In EUR-only mode, `ThisPeriodAtAGlanceQuery` returns one set of in/out/net totals computed from `settled_amount_minor WHERE settled_currency = 'EUR'`. In original-currency mode, the query GROUPs BY currency and returns one tile-row per currency present in the period. EUR-only months collapse to a single row.
- **D-47:** **Transaction rows render two-line stack when native ≠ settled in original-currency view.** Primary line: native (e.g. `$12.99 USD`). Secondary line in muted/`text-muted-foreground`: settled (`€12.07 EUR`). The existing `TransactionListQuery`'s `?string $currency` projection is consumed by the Blade view.
- **D-48 (Claude's discretion):** **FX-rate placement = transaction detail page only.** Detail page renders "Charged $12.99 USD · effective rate €0.929/USD · settled €12.07 EUR" alongside existing detail fields.

### Claude's Discretion

- **D-38 above:** Exact wizard wording for the "name your ICS Account" step (e.g. "ICS card / Mastercard" suggested vs blank input).
- **D-48 above:** Exact rendering of the FX-rate string on the detail page (rate orientation `EUR/USD` vs `USD/EUR`; precision).
- **Currency formatting** — `brick/money` formatter selection (locale-aware vs ISO-symbol). Default to Dutch-locale formatting (€-prefix, comma decimal) for EUR; ISO-symbol-prefix (e.g., `$` for USD) for non-EUR currencies. Planner can override per UI sketch.
- **Settings page styling** — match the calm Linear/Notion aesthetic; reuse Flux primitives that already exist for the install command's text inputs.
- **Storage decision (per CONTEXT integration points):** `default_currency_view` lives on the `users` row vs a `user_preferences` table — planner picks.

### Deferred Ideas (OUT OF SCOPE)

- **ICS Excel (.xlsx) ingestion** — explicitly rejected for Phase 3 per D-31. Revisit only if the user's ICS portal drops the CSV export. Would add `phpoffice/phpspreadsheet`.
- **Per-card visibility within one ICS contract** — Phase 3 ships single Account, no `card_last4` column. A future phase must (a) add `card_last4` (or `card_id`), (b) extend the v3 fingerprint tuple to include card identity (= v4 bump with re-derivation), (c) update the dashboard to optionally group totals by card. **Landmine:** importing a second card under the current single-Account model BEFORE this work would cause two cards posting the same merchant+date+amount to falsely de-duplicate.
- **Splitting ICS FX markup as a separate fee row** — Phase 3 rolls markup into the same canonical row (D-40).
- **Market-rate-vs-effective-rate split (`fx_market_rate` + `fx_markup_basis_points` columns)** — explicitly rejected for v1.
- **PayPal Reporting API + USD-funded PayPal** — Phase 4.
- **Chain-resolution use of ICS source_ref** — captured for Phase 5.
- **OFX / QIF export of multi-currency rows** — out of v1 scope.
- **Per-currency budgets / spending limits** — out of scope.
- **Settings UI for `period_start_day`** — landing in Phase 3 (D-45) as a co-discharge of Phase 1 D-19.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ING-04 | User can upload an ICS Cards CSV (Excel rejected per D-31) and see its transactions imported, with original-currency + settled-EUR preserved where applicable | New `IcsCsvAdapter` under `Modules/Ingestion/Internal/Adapters/Ics/`, registered in `SourceAdapterRegistry` as `'ics-csv'`; `HeaderSniffer` extended with ICS signature (Wave 0 nails the exact substring) |
| LED-03 | Each transaction stores original-currency, settled-EUR, and `fx_rate_used` where source provides it | Schema columns already exist from Phase 1 (`settled_amount_minor` NOT NULL, `settled_currency` NOT NULL CHAR(3), `fx_rate_used` decimal(18,8) NULLABLE). Phase 3 populates them for non-EUR rows via D-42 NormalizeStage substitution + D-39 derived rate. No schema change required for the LED-03 core. |
| MC-02 | User can switch transaction list + reports between EUR-only and dual-currency view | Flux `radio.group variant="segmented"` bound to a Livewire 4 `#[Url(as: 'currency')]` property; default falls through to `users.default_currency_view` (D-44); existing `TransactionListQuery::recent(..., ?string $currency)` already projects native vs settled |
| UI-06 | Currency amounts surface their original currency when different from settled | Two-line render in `transactions-list` Blade for rows where `currency !== settled_currency` (D-47); brick/money formatter outputs ISO-symbol prefix for non-EUR, Dutch locale for EUR |

</phase_requirements>

## Summary

Phase 3 is a small-surface but high-leverage phase. The schema was front-loaded in Phase 1 (MC-01 — `settled_amount_minor` / `settled_currency` / `fx_rate_used` columns NOT-NULL on the settled pair, nullable on the rate), and the adapter contract was generalised in Phase 2 (the four-state preview pipeline, `SourceAdapterRegistry`, `HeaderSniffer`, `SourceTransactionDto`'s extensible shape). Phase 3 lands four discrete pieces of work behind that wiring:

1. **ICS CSV ingestion** — a new `IcsCsvAdapter` mirroring `AsnCsvAdapter`'s lazy-Generator + `league/csv` + `CharsetConverter` shape, with a Wave 0 enablement step that produces the anonymised fixture before the adapter is written (mirroring Phase 2 Plan 1).
2. **Multi-currency wiring** — extend `SourceTransactionDto` with three nullable settled-pair fields (D-42), let `NormalizeStage` substitute `settled = native` when the source DTO leaves them `null`, and derive `fx_rate_used = settled_amount_minor / amount_minor` as a decimal(18,8) string when both legs are present (D-39). Phase 1/2 ASN adapters keep returning `null` for the new fields without code change.
3. **Settings page + user preference** — a new `/settings` Livewire 4 SFC under `Modules/Core/Internal/Http/Livewire/SettingsPage.php` discharging Phase 1's deferred D-19. Two form fields: `default_currency_view` (eur_only / original) and `period_start_day` (1..28). Choice of storage column-vs-table is deliberately the planner's call (see "Architecture Patterns" §Settings storage).
4. **Toggle UX on `/transactions` + dashboard** — Flux `radio.group variant="segmented"` bound to a Livewire 4 `#[Url(as: 'currency')]` property; defaulting to the user preference; mode flipping `TransactionListQuery::recent()` to project settled-EUR vs native; and a new `groupByCurrency: bool` variant on `ThisPeriodAtAGlanceQuery` for the dashboard tiles (D-46).

The hardest unknown is the ICS CSV's exact column shape and how FX charges are emitted (one row with both legs vs two rows merchant+FX). This phase intentionally answers that question via Wave 0 against a real anonymised fixture rather than by guessing — public documentation on the icscards.nl CSV export is sparse (verified across three GitHub repos + Yuki Support article + bunni.nl tutorial; none specify columns). The adapter design accommodates both shapes by allowing a small look-ahead/buffer step inside the generator.

**Primary recommendation:** Plan 1 is Wave 0 (anonymise + commit fixture + write a fixture-record `.md` mirroring `tests/fixtures/asn-sample-1.md` + answer D-34 / D-35 / D-40). Plan 2 builds the `IcsCsvAdapter` against the now-known shape. Plan 3 lands `SourceTransactionDto` extension + `NormalizeStage` substitution + `fx_rate_used` derivation + ICS Account modeling (D-36). Plan 4 lands the two-step wizard picker (D-33), the `/settings` page (D-45), and the `users.default_currency_view` storage. Plan 5 lands the per-page toggle + dual-line transaction list (D-47) + GROUP BY currency dashboard mode (D-46) + the FX-rate detail-page surface (D-48). Each plan is a vertical slice consistent with the project's MVP slicing constraint.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| ICS CSV parsing (file → `SourceTransactionDto[]`) | Ingestion module (`Internal/Adapters/Ics/IcsCsvAdapter`) | — | Adapters live in Ingestion per the Phase 1 module split (D-05). Cross-module access goes through `Public/` only. |
| FX rate derivation + settled→native mirror | Import module (`Internal/Pipeline/Stages/NormalizeStage`) | — | NormalizeStage already converts Source DTOs into Canonical rows; D-42 substitution is one localised branch. Adapters MUST NOT do this — they only report what the source provided. |
| Schema columns for settled pair + fx_rate_used | Ledger module (already shipped Phase 1) | — | Schema is owned by Ledger per D-04. No new migration strictly required for the dual-amount surface. |
| `users.default_currency_view` storage | Core module (User model + migration) | Ledger (if a `user_preferences` table is preferred) | User-scoped preferences belong on Core. Planner picks between users column vs separate table — see "Architecture Patterns" §Settings storage. |
| `/settings` Livewire SFC | Core module (`Internal/Http/Livewire/SettingsPage`) | — | Settings UI cross-cuts user preferences; Core is the right module per D-08 (Core owns User + auth wiring). |
| Currency toggle UI (segmented control + URL binding) | Ledger module (transactions list view) + Core module (dashboard) | — | The toggle modifies a query parameter consumed by `TransactionListQuery` (Ledger Public) and `ThisPeriodAtAGlanceQuery` (Ledger Public); the Blade view lives in whichever module hosts the page. |
| Two-step wizard picker | Import module (`Internal/Http/Livewire/UploadWizard`) | — | UploadWizard is already Import-owned; D-33 is a refactor inside the existing component. |
| Money formatting (€-prefix vs ISO-symbol prefix) | Ledger module (`Public/ValueObjects/Money` extension) | Blade view layer | Money formatting is the domain VO's job; the Blade view consumes the formatted output. Per FND-07 every monetary path stays inside brick/money via the Ledger `Money` wrapper. |

## Standard Stack

### Core

| Library | Version (installed) | Latest | Purpose | Why Standard |
|---------|---------------------|--------|---------|--------------|
| `league/csv` | 9.28.0 | 9.28.0 (Dec 27 2025) | CSV streaming reader for ICS CSV | Already pinned + battle-tested in `AsnCsvAdapter`. Streaming-by-row keeps multi-year ICS history off the heap. `[VERIFIED: composer.lock]` |
| `brick/money` | 0.11.2 | 0.13.0 (Mar 28 2026) | Multi-currency Money VO; non-EUR formatter exercised first time in Phase 3 | Already pinned. `Money::ofMinor()` / `Money::of()` / `MoneyLocaleFormatter` cover Phase 3's needs. **Version note:** composer.json constrains `^0.11`, latest is `0.13.0`. The `Money::ofMinor()` / `Money::of()` API surface used by `Modules/Ledger/Public/ValueObjects/Money` is stable across 0.11 → 0.13 (verified by inspecting `vendor/brick/money/src/Money.php` head). **Recommend planner leaves the constraint at `^0.11` for Phase 3** — no upgrade is required to ship multi-currency. `[VERIFIED: composer.lock + vendor inspection]` |
| `spatie/laravel-data` | 4.23.0 | 4.23.0 | DTO base class for `SourceTransactionDto` extension | Phase 3 adds three nullable fields per D-42; Spatie Data's nullable-prop handling makes the diff trivial. `[VERIFIED: composer.lock]` |
| `livewire/livewire` | v4.3.0 | v4.3.0 | Reactive UI for `/transactions` toggle + `/settings` page | Required for `#[Url]` attribute (Livewire 3+/4). `[VERIFIED: composer.lock + livewire.laravel.com/docs/4.x/attribute-url]` |
| `livewire/flux` | v2.14.1 | v2.14.1 (Apr 23 2026) | Segmented control for currency toggle | `flux:radio.group variant="segmented"` is the standard Flux primitive (verified in vendor stubs at `vendor/livewire/flux/stubs/resources/views/flux/radio/group/variants/segmented.blade.php`). `[VERIFIED: vendor inspection + fluxui.dev]` |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `nesbot/carbon` (ships with Laravel) | 3.x | Parse ICS CSV date strings | Already in tree. `CarbonImmutable::createFromFormat('!d-m-Y', ...)` is the pattern from `AsnCsvAdapter::parseDate()`. |
| `pestphp/pest` + `pestphp/pest-plugin-arch` | v4.7.0 / v4.0.2 | Adapter unit tests + arch boundary tests | Established Phase 1/2 pattern. New `IcsCsvAdapter` follows the same `Modules/Ingestion/tests/Unit/Adapters/Ics/` layout. |
| `spatie/pest-plugin-snapshots` | 2.3.1 | Snapshot test for canonical DTO stream from fixture | Established Phase 1/2 pattern. One fixture CSV → one expected canonical DTO stream snapshot. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `flux:radio.group variant="segmented"` | Plain Tailwind buttons / `flux:tabs` | Flux's segmented variant is the documented calm-aesthetic primitive; rolling our own loses keyboard accessibility and dark-mode parity. |
| `#[Url]` attribute | Manual `mount(?string $currency = null)` parameter + URL builder | `#[Url]` gives bidirectional URL sync, browser-history support, and `wire:model.live` integration with one line of code; manual route param requires hand-rolled redirects on toggle. |
| `users.default_currency_view` column | `user_preferences` polymorphic key-value table | See Architecture Patterns §Settings storage — both are acceptable; the column is simpler for v1, the table is cleaner for future settings growth (Phase 6 OAuth credentials, etc.). |
| `MoneyLocaleFormatter` for non-EUR | Hard-coded `$symbol + amount` string | The locale formatter handles thousands-separator + ISO-symbol prefix in one call; verified in `vendor/brick/money/src/Formatter/MoneyLocaleFormatter.php`. Cleaner than building a per-currency switch. |

**Installation:** None. Zero new direct composer dependencies for Phase 3 (verified against `composer.json`). All required packages are already pinned and installed.

**Version verification:** Verified live against Packagist on 2026-05-13:
- `brick/money`: latest 0.13.0 (2026-03-28); installed 0.11.2 — Phase 3 does **not** require the upgrade.
- `league/csv`: latest 9.28.0 (2025-12-27); installed 9.28.0 — current.
- `livewire/flux`: latest v2.14.1 (2026-04-23); installed v2.14.1 — current.
`[VERIFIED: packagist.org repo.packagist.org/p2/ JSON endpoints]`

## Architecture Patterns

### System Architecture Diagram

```
                                  User uploads ICS CSV via /imports/new
                                                  │
                                                  ▼
                  ┌──────────────────────────────────────────────────┐
                  │  Modules/Import/Internal/Http/Livewire           │
                  │  UploadWizard (D-33 two-step picker)             │
                  │  - issuer dropdown: ASN / ICS                    │
                  │  - format dropdown: ics-csv (only ICS option)    │
                  │  - sourceFormat = "ics-csv"                      │
                  └────────────────────┬─────────────────────────────┘
                                       │ RunsImports::runFromUpload
                                       ▼
              ┌──────────────────────────────────────────────────────┐
              │  Modules/Import/Internal/Pipeline/ImportPipeline     │
              │  ParseStage → NormalizeStage → FingerprintStage      │
              └─────┬─────────────────────────┬──────────────────────┘
                    │ ParseStage              │ NormalizeStage (D-42)
                    ▼                         ▼
   ┌──────────────────────────────┐  ┌─────────────────────────────────────┐
   │ SourceAdapterRegistry        │  │ if (source->settledAmountMinor null)│
   │   .for("ics-csv")            │  │   settled = native,                 │
   │   → IcsCsvAdapter (new)      │  │   fx_rate_used = null               │
   │   parses CSV lazily via      │  │ else                                │
   │   league/csv + Charset       │  │   settled = source->settled*,       │
   │   yields SourceTransactionDto│  │   fx_rate_used = settled/native     │
   │   with nullable settled pair │  │     (decimal(18,8))                 │
   └──────────────────────────────┘  └─────────────────────────────────────┘
                                       │
                                       ▼
                  ┌──────────────────────────────────────────────────┐
                  │  Modules/Ledger/Public/Actions/RecordTransactions│
                  │  inserts CanonicalTransaction[] (idempotent v3 FP)│
                  └──────────────────────────────────────────────────┘

User views /transactions or /                          User opens /settings
        │                                                       │
        ▼                                                       ▼
┌──────────────────────────────┐                ┌──────────────────────────────┐
│ TransactionListQuery::recent │                │ SettingsPage (new Livewire)  │
│   (?string $currency)        │                │   - default_currency_view    │
│ ?currency=eur → settled pair │                │   - period_start_day         │
│ ?currency=null → native pair │                │  reads + writes User row     │
└─────────────┬────────────────┘                └──────────────────────────────┘
              │                                                  │
              ▼                                                  │
┌──────────────────────────────┐                                 │
│ Blade view (D-47)            │                                 │
│ Flux segmented control       │                                 │
│ wire:model="currency"        │                                 │
│ #[Url(as: 'currency')]       │   ◄─── default falls back to ──┘
│ Two-line stack when          │       $currentUser->user()->default_currency_view
│   native != settled          │
└──────────────────────────────┘

Dashboard:
ThisPeriodAtAGlanceQuery
  - eur_only mode: SUM WHERE settled_currency = 'EUR'  (existing path)
  - original mode: GROUP BY settled_currency, returns one tile-row per currency
```

### Recommended Project Structure

```
Modules/
├── Ingestion/
│   ├── Internal/Adapters/
│   │   ├── Asn/                           # existing (CSV, CAMT.053, MT940)
│   │   └── Ics/                           # NEW for Phase 3
│   │       ├── IcsCsvAdapter.php          # implements SourceAdapter
│   │       ├── IcsCsvHeaderProfile.php    # FORMAT, DELIMITER, encoding, header signature
│   │       ├── IcsCsvColumnMap.php        # zero-based column index constants (Wave 0 nails)
│   │       └── (look-ahead helper if D-35 shape (b))
│   ├── Public/
│   │   ├── Dto/SourceTransactionDto.php   # D-42 extension — add 3 nullable fields
│   │   └── Services/HeaderSniffer.php     # extend with ICS CSV signature
│   └── tests/
│       ├── fixtures/ics/
│       │   ├── ics-sample-1.csv           # anonymised raw export (Wave 0)
│       │   ├── ics-sample-1.md            # fixture record (mirrors asn-sample-1.md)
│       │   └── (FX-charge sub-fixture if shape (a) and shape (b) both need exercising)
│       └── Unit/Adapters/Ics/
│           ├── IcsCsvAdapterTest.php
│           └── __snapshots__/             # canonical DTO stream snapshot
├── Import/
│   └── Internal/Pipeline/Stages/
│       └── NormalizeStage.php             # D-42 substitution branch + D-39 fx derivation
├── Core/
│   ├── Database/Migrations/
│   │   └── 2026_05_13_XXXXXX_add_default_currency_view_to_users.php (NEW; or user_preferences)
│   ├── Internal/Http/Livewire/
│   │   └── SettingsPage.php               # NEW — /settings Livewire SFC
│   ├── Models/User.php                    # add `default_currency_view` to $fillable + $casts
│   └── Resources/views/livewire/
│       └── settings-page.blade.php        # NEW
└── Ledger/
    └── Public/Services/
        ├── TransactionListQuery.php       # already accepts ?currency — no change
        └── ThisPeriodAtAGlanceQuery.php   # D-46 groupByCurrency mode (NEW)
```

### Pattern 1: Lazy generator adapter mirroring AsnCsvAdapter

**What:** Stream the CSV one row at a time, yield one `SourceTransactionDto` per **logical** transaction. For D-35 shape (a) — one CSV row per logical txn — this is exactly the `AsnCsvAdapter` pattern. For shape (b) — two CSV rows per FX charge — use a small `peek`-and-buffer step inside the generator (see Pattern 2).

**When to use:** Every adapter in this project. Lazy generator is non-negotiable (multi-year history) and is enforced by the `SourceAdapter` interface docblock.

**Example (shape-a single-row, mirrors `AsnCsvAdapter::parse`):**
```php
// Source: Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php (Phase 1 reference)
public function parse(string $localPath, AccountResolver $accounts): Generator
{
    $this->sniffer->sniff($localPath, IcsCsvHeaderProfile::FORMAT);

    $reader = Reader::from($localPath, 'r');
    $reader->setDelimiter(IcsCsvHeaderProfile::DELIMITER);
    $reader->setEscape('');
    $reader->setHeaderOffset(0);
    CharsetConverter::addTo($reader, IcsCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8');

    $index = 0;
    foreach ($reader->getRecords() as $record) {
        $row = $this->normaliseRow($record);
        // ... parse date, amount(native), amount(settled), currency(native), currency(settled)
        yield new SourceTransactionDto(
            // ... existing fields ...
            settledAmountMinor: $settledMinor,   // NEW (D-42)
            settledCurrency: $settledCurrency,   // NEW (D-42)
            fxRateUsed: null,                    // adapter never derives; NormalizeStage does
        );
        $index++;
    }
}
```

### Pattern 2: Multi-row → single-DTO roll-up via 1-row look-ahead buffer

**What:** When D-35 confirms shape (b) — merchant row + FX-conversion row pair share an auth code or appear back-to-back — the generator buffers one row and decides on the next read whether to merge or yield.

**When to use:** Only if Wave 0 reports shape (b). Phase 4 PayPal `Transaction ID` rollup will use the same pattern (it's the exact case PITFALLS.md §3 anticipates).

**Example:**
```php
public function parse(string $localPath, AccountResolver $accounts): Generator
{
    // ... reader setup as above ...

    $buffered = null; // ?array{row: array<int,string>, index: int}
    $index = 0;
    foreach ($reader->getRecords() as $record) {
        $row = $this->normaliseRow($record);

        if ($this->isFxConversionRow($row) && $buffered !== null) {
            // Merge the FX-conversion legs into the buffered merchant row,
            // yield once, clear the buffer.
            yield $this->mergeIntoFxAwareDto($buffered['row'], $row, $buffered['index']);
            $buffered = null;
            $index++;
            continue;
        }

        // The current row is a fresh merchant line: flush any prior buffer
        // (which had no FX-conversion follow-up — EUR-native) and re-buffer.
        if ($buffered !== null) {
            yield $this->mergeIntoFxAwareDto($buffered['row'], null, $buffered['index']);
            $index++;
        }
        $buffered = ['row' => $row, 'index' => $index];
    }

    // EOF: flush a final buffered merchant row that never received an
    // FX-conversion follow-up.
    if ($buffered !== null) {
        yield $this->mergeIntoFxAwareDto($buffered['row'], null, $buffered['index']);
    }
}
```

The key invariants for correctness:
1. `isFxConversionRow($row)` MUST be a deterministic test (Wave 0 reports the marker — e.g. blank merchant + non-EUR currency + same auth code as the prior row).
2. The yielded DTO's `sourceRowIndex` is the **merchant row's** index, not the FX-conversion row's — this anchors the audit trail and keeps the index monotonically increasing across one parse run (matching `AsnCsvAdapter`'s contract).
3. The FX-conversion row's cells stay reachable via `rawPayload` (e.g. nested under `rawPayload['fxConversion']`) so a future markup-split pass can recover them without re-parsing.

### Pattern 3: NormalizeStage substitution + FX rate derivation (D-42 + D-39)

**What:** Single branch inside `NormalizeStage::run` that fills in settled-pair defaults from the native pair when the source DTO leaves them `null`, and derives `fx_rate_used` when both legs are present and differ from each other.

**When to use:** Every Phase 3 row passes through this branch — ASN and ICS alike. The branch is a no-op for the ASN side (their adapters yield `null` for the new fields) and active for ICS FX rows.

**Example (additions to `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php`):**
```php
// D-42 + D-39 substitution. Adapters report what the source provides; the
// pipeline canonicalises that into the always-NOT-NULL settled pair.
$settledMinor = $source->settledAmountMinor ?? $source->amountMinor;
$settledCurrency = $source->settledCurrency ?? $source->currency;

$fxRateUsed = null;
if ($source->settledAmountMinor !== null
    && $source->settledCurrency !== null
    && $source->amountMinor !== 0
    && $source->settledCurrency !== $source->currency
) {
    // Effective rate = settled / native, as a string to preserve
    // decimal(18,8) precision. Use brick/math BigDecimal for the divide
    // so floating-point never enters the pipeline.
    $fxRateUsed = (string) BigDecimal::of((string) $source->settledAmountMinor)
        ->dividedBy(
            BigDecimal::of((string) $source->amountMinor),
            8,                                 // scale to fit decimal(18,8)
            RoundingMode::HALF_UP,
        );
}

return new CanonicalTransaction(
    // ... existing fields ...
    settledAmountMinor: $settledMinor,
    settledCurrency: $settledCurrency,
    fxRateUsed: $fxRateUsed,
    // ...
);
```

Note: `brick/math` is a transitive dep of `brick/money` (already installed at `~0.14.4` per `vendor/brick/money/composer.json`) so `BigDecimal::of()` is available without a new require. The NoFloatMoneyArchTest allow-list stays untouched (we never construct a float).

### Pattern 4: Livewire 4 `#[Url]` attribute for per-page currency toggle (D-44)

**What:** Bind a public property on the `/transactions` Livewire component to the `?currency=` query parameter. The property defaults to the user's `default_currency_view` preference; toggling the segmented control updates both the property and the URL atomically; refresh + share-link work without extra code.

**When to use:** Every page that needs a per-page setting that overrides a global default and must be refresh-stable.

**Example:**
```php
// Source: livewire.laravel.com/docs/4.x/attribute-url
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

final class TransactionsList extends Component
{
    /**
     * Currency view mode. Null = use the user's default_currency_view
     * preference. 'eur' = EUR-only projection. (Other ISO codes are out of
     * scope for Phase 3 but the URL surface is forward-compatible.)
     */
    #[Url(as: 'currency', except: '')]
    public string $currency = '';

    public function mount(CurrentUser $currentUser): void
    {
        if ($this->currency === '') {
            $pref = $currentUser->user()->default_currency_view;
            $this->currency = $pref === 'eur_only' ? 'eur' : '';
        }
    }
    // ...
}
```

Blade:
```blade
<flux:radio.group wire:model.live="currency" variant="segmented">
    <flux:radio value=""    label="Original" />
    <flux:radio value="eur" label="EUR" />
</flux:radio.group>
```

Two key Livewire 4 facts (`[CITED: livewire.laravel.com/docs/4.x/attribute-url]`):
- `#[Url(as: 'currency')]` aliases the property name to `?currency=` in the URL.
- `wire:model.live` triggers a network request on each radio change; the URL updates via `history.replaceState()` by default (no history pollution from rapid toggles).
- `except: ''` keeps the URL clean when the user toggles back to the default (the `?currency=` segment is dropped instead of left as `?currency=` empty).

### Pattern 5: Settings page Livewire 4 SFC with DI-clean form handling

**What:** A Livewire 4 component at `Modules/Core/Internal/Http/Livewire/SettingsPage.php` exposing two form fields (`default_currency_view` and `period_start_day`), backed by the User model, using **method-level DI** for collaborators (Phase 1 established this — see `01-05-SUMMARY.md` decisions list).

**When to use:** Every Phase 3+ Livewire form that mutates the User row.

**Example:**
```php
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Illuminate\Database\DatabaseManager;

final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view ?? 'eur_only';
        $this->periodStartDay = $user->period_start_day;
    }

    public function save(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $this->validate();

        $user = $currentUser->user();
        $user->default_currency_view = $this->defaultCurrencyView;
        $user->period_start_day = $this->periodStartDay;
        $user->save();
    }

    public function render(...): View
    {
        return ...->make('core::livewire.settings-page');
    }
}
```

Two invariants from Phase 1/2:
- **Method-level DI only.** The component constructor takes no collaborators; collaborators land on the `save()` / `mount()` parameter list and Laravel's container resolves them at call time. Property-style constructor DI on Component subclasses trips the larastan-strict-rules.
- **`$this->redirect(...)` over `RedirectResponse`.** If the save flow needs a redirect (e.g. to refresh the dashboard with the new period_start_day), call `$this->redirect($urls->route('dashboard'), navigate: false)` per the Phase 1 fix in `01-05-SUMMARY.md` §"Auto-fixed Issues" #6.

### Pattern 6: Settings storage — column on `users` vs `user_preferences` table

**Context:** D-19 from Phase 1 explicitly left this open ("planner picks"). Phase 3 must finally answer. Both options work; the trade is between simplicity-now and growth-flexibility-later.

**Option A — `users.default_currency_view` column (RECOMMENDED for Phase 3):**

| Pro | Con |
|-----|-----|
| One migration (add a single `string('default_currency_view', 16)->default('eur_only')` column to `users`). | If Settings grows to 10+ fields by Phase 11, the User row gets noisy. |
| Larastan-clean — `$user->default_currency_view` is a typed property on the model. | A v2 multi-user scenario where two users share a workspace and need divergent settings has to copy this column for every user. |
| `$currentUser->user()->default_currency_view` reads it in one method call. | — |
| The existing `period_start_day` column already lives here — consistent. | — |

**Option B — `user_preferences` table:**

| Pro | Con |
|-----|-----|
| Cleaner growth — Phase 6 OAuth credentials, Phase 9 drift thresholds, etc. can land as rows without migrations. | Two extra rows to read on every page (joinable but adds query surface). |
| Multi-user-friendly — partner share scenarios get cleaner per-user rows. | Larastan typing is less ergonomic (`$user->preferences->get('default_currency_view')` style). |
| — | Phase 1 `period_start_day` would either stay on `users` (inconsistent) or get migrated, doubling the migration footprint of Phase 3. |

**Recommendation:** **Option A.** Phase 3 only adds one preference. The `period_start_day` column already lives on `users` and moving it for symmetry would expand Phase 3 scope. When (and if) the Settings page grows past three fields, extract to a `user_preferences` JSON column or table as a focused refactor. Document the decision in the Phase 3 plan so a future researcher knows the door is open.

### Anti-Patterns to Avoid

- **DON'T derive `fx_rate_used` inside the adapter.** Adapters report what the source provides (the settled pair when both legs exist on the source row); rate derivation is `NormalizeStage`'s job. Mixing the two breaks the Phase 1/2 contract that adapters are "pure source → DTO" and pipeline stages own canonicalisation.
- **DON'T touch `FingerprintComposer` for multi-currency.** D-43 is implicit: the fingerprint already uses native `amount_minor` + `currency`. Two USD charges to the same merchant on the same day differ on `booked_at` (second precision) or on `amount_minor`. No version bump required, no migration required.
- **DON'T promote the ICS card number to `rawPayload` (D-37).** Strip it at the adapter boundary. The single-card v1 design means it carries no useful information; preserving it pollutes the JSON column and breaks the v2-multi-card landmine documented in `<deferred>`.
- **DON'T add `ics-csv` to the cross-format rank function in `FingerprintStage::classify`.** Phase 3 ships exactly one ICS format. The ENRICHED state code path stays unreachable for ICS rows. If the rank-function `match` is touched at all, add a fourth case scoring `ics-csv` at rank 1 (parity with `asn-csv`) and document it; if not touched, the existing default of rank 0 is also acceptable because there's no second ICS format to enrich from.
- **DON'T inject `auth()` / `Auth::user()` anywhere.** The DI-only project constraint and Phase 1's custom Larastan rule reject this on sight. Inject `CurrentUser` in `SettingsPage`, `TransactionsList`, and the dashboard component instead.
- **DON'T use float arithmetic in the rate derivation.** Use `Brick\Math\BigDecimal::dividedBy(...)`. The `NoFloatMoneyArchTest` migration grep won't catch a `float` cast in PHP code, but the canvural-strict-rules / phpstan-strict will catch a `/` over numerics that should be string-precise.
- **DON'T re-build the cross-format ENRICHED preview state for ICS.** The Phase 2 four-state pipeline (NEW / DUPLICATE / ENRICHED / ERROR) stays — ICS rows will simply never produce an ENRICHED preview row in Phase 3 (no second ICS format exists). The Blade UI already handles all four states; no Blade re-baselining required for the ENRICHED arm. (Snapshot re-baselining IS required for the **wizard upload step** because D-33 reshapes the dropdown.)

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSV parsing with non-UTF-8 input | `fgetcsv` + manual `mb_convert_encoding` per cell | `League\Csv\Reader` + `CharsetConverter::addTo($reader, 'windows-1252', 'utf-8')` | League CSV handles BOM stripping, header offset, streaming, encoding conversion. Verified via the `AsnCsvAdapter` precedent + `csv.thephpleague.com/9.0/converter/charset/`. |
| Multi-currency arithmetic | `(int) ($eurMinor / $usdMinor * 100)` style | `Brick\Math\BigDecimal::of($settled)->dividedBy($native, scale: 8, RoundingMode::HALF_UP)` | Float drift was Phase-1 Pitfall 1's whole point. BigDecimal preserves decimal(18,8) precision exactly. |
| ISO-4217 currency code validation | A hand-coded allow-list | `Brick\Money\ISOCurrencyProvider::getInstance()->getCurrency($code)` (used inside `Money::ofMinor`) | brick/money ships every ISO currency built-in. `Money::ofMinor(0, 'JPY')` correctly knows JPY has zero minor units. |
| Currency formatting (€ / $ / £ prefix) | `'€'.number_format(...)` switch | `Brick\Money\Formatter\MoneyLocaleFormatter` (locale-aware) | The formatter handles thousands separator + currency-symbol position + minor-unit scale per locale. Dutch uses `€ 12,07`; US English uses `$12.07`. |
| URL query-param sync | `mount(Request $request)` + manual `$request->query('currency')` + manual redirect on toggle | Livewire 4 `#[Url(as: 'currency')]` attribute | Bidirectional sync, browser-history support, `wire:model.live` integration — all free with one attribute. `[CITED: livewire.laravel.com/docs/4.x/attribute-url]` |
| Segmented control for currency toggle | Hand-rolled Tailwind buttons with active-state classes | `flux:radio.group variant="segmented"` | Flux's segmented variant is documented + ships in the project (`vendor/livewire/flux/stubs/resources/views/flux/radio/group/variants/segmented.blade.php`). Keyboard-accessible, dark-mode-aware, calm-aesthetic-default. |
| User-preference round-trip | A custom JSON blob on `users` | Plain typed column (`string('default_currency_view', 16)`) or a dedicated `user_preferences` table | Two clean options exist; both are well-precedented in Laravel apps. JSON blobs are the worst of both worlds for typed access under Larastan level 10 strict. |
| Anonymisation script | A one-off bash sed pipeline | A small `tests/fixtures/anonymize_ics.py` mirroring the script Phase 1 used (referenced in `asn-sample-1.md` §"Anonymization protocol") | Wave 0 produces a deterministic, re-runnable redaction; an ad-hoc sed is non-auditable and likely incomplete (card numbers vs. names vs. PII). |

**Key insight:** Phase 3 is mostly *wiring* — every primitive it needs (CSV streaming, currency arithmetic, formatter, segmented control, URL sync, Livewire SFC, user-preference column) is provided by an installed dependency. The work is in choosing the right primitive at each seam and resisting the temptation to hand-roll something "just for this case".

## Common Pitfalls

### Pitfall 1: Losing FX information that the source provided (PITFALLS.md §1 + §3)

**What goes wrong:** A USD ICS charge of `$12.99` lands as `€12.07` and the adapter stores only `12.07 EUR`. Months later, the user wants "what was my dollar spend in Q1?" — irrecoverable.

**Why it happens:** Treating the EUR settlement as the canonical row and discarding the rest.

**How to avoid:** Honour D-42's contract — when the source CSV has both legs, both legs land on the `SourceTransactionDto`, NormalizeStage propagates them to the canonical row, the `decimal(18,8) fx_rate_used` column captures the effective rate. The ICS markup row's cents are recoverable from `rawPayload` even though they roll up into `settled_amount_minor` (D-40).

**Warning signs:** A non-EUR row whose `settled_currency == currency` AND `fx_rate_used IS NULL` — that's an FX charge that lost its rate. Add a Pest assertion to `IcsCsvAdapterTest` for at least one fixture row that exercises non-EUR + rate-derivation.

### Pitfall 2: Wave-0 fixture missing rows that the adapter then can't handle

**What goes wrong:** The anonymised fixture only contains EUR-native rows; the adapter ships; the user uploads a real statement containing one USD purchase; the adapter throws.

**Why it happens:** The user's recent statements happened to be EUR-only.

**How to avoid:** Wave 0 explicitly seeks at least one foreign-currency row in the source export (D-32 fixture inspection step). If the user's recent statements are all EUR, request an older one or a synthetic row added by anonymisation that preserves a known USD shape. Document the absence in `ics-sample-1.md` and add an `it('handles foreign-currency rows correctly')->skip('no FX rows in fixture corpus yet')` test placeholder so the gap is visible.

**Warning signs:** No `expect($dtos[N]->settledCurrency)->toBe('EUR')->and($dtos[N]->currency)->toBe('USD')` assertion in `IcsCsvAdapterTest`.

### Pitfall 3: Two-step picker breaks existing wizard snapshot tests (Phase 2 Risk)

**What goes wrong:** D-33 reshapes the upload-wizard's flat dropdown into an issuer→format picker. Phase 1/2 wizard feature tests assert HTML output against a snapshot or against literal strings. The new shape breaks them.

**Why it happens:** UI tests are brittle to layout reshape.

**How to avoid:** The CONTEXT explicitly carves a task for "regenerate wizard snapshots". The planner should ensure that task is in the plan that lands the picker refactor, NOT deferred to a later plan. Tests touched include `Modules/Import/tests/Feature/UploadWizardTest.php` (covers ING-07's `in:asn-csv` validator — must become `in:asn-csv,asn-camt053,asn-mt940,ics-csv` and the dropdown shape changes accordingly).

**Warning signs:** A wizard test that fails on the literal HTML string of the dropdown options.

### Pitfall 4: GROUP BY currency dashboard mode quietly hides currencies (D-46)

**What goes wrong:** The dashboard "original-currency" mode groups by `settled_currency`, but a query that only SUMs currencies the user has any row in that period for could surface noise (a single $0.05 PayPal refund landing as a separate USD tile). Or — worse — drop a currency that has rows in the period but the query somehow filters out.

**Why it happens:** SUM + GROUP BY without thinking about edge cases.

**How to avoid:** Add Pest assertions for (a) EUR-only month in original mode → exactly one row (must match EUR-only mode output); (b) month with EUR + USD → two rows; (c) month with multiple non-EUR currencies → tile-row per currency, sorted by absolute settled amount descending (or alphabetical — planner picks; document the choice). The test fixture for `ThisPeriodAtAGlanceQuery` already exists; extend it.

**Warning signs:** A dashboard test that only asserts the EUR totals and never the multi-currency split.

### Pitfall 5: `default_currency_view` round-trip from Settings to URL silently breaks

**What goes wrong:** User saves "original" as default in Settings. Visits `/transactions`. URL has no `?currency=` param. Component's `mount()` should fall back to the User's preference, but a typo or wrong injection causes it to fall back to a hardcoded `'eur_only'`.

**Why it happens:** Four boundaries (Settings → DB → query → URL override) and no end-to-end test that exercises all four.

**How to avoid:** A Pest feature test that:
1. Logs in as a user.
2. Saves `default_currency_view = 'original'` via the SettingsPage component.
3. Asserts the User row has it.
4. Visits `/transactions` with NO query param.
5. Asserts the Livewire component's `$currency` property and the rendered Blade output reflect "original".
6. Visits `/transactions?currency=eur`.
7. Asserts the rendered output reflects "eur_only" (URL overrides default).

### Pitfall 6: brick/money refuses unknown currency on `Money::ofMinor`

**What goes wrong:** A row lands with `currency = 'XBT'` or some merchant returning a non-ISO code; `Money::ofMinor(123, 'XBT')` throws `UnknownCurrencyException`; the import dies mid-stream.

**Why it happens:** brick/money's `ISOCurrencyProvider` is strict by design (this is a *good* property for a finance app).

**How to avoid:** The adapter validates the currency code at the parse boundary. If unknown, treat the row as ERROR rather than throwing during canonicalisation. The Phase 1 ImportPipeline already catches per-row exceptions and converts them to ERROR `PreviewRowDto` (per `01-05-SUMMARY.md`), so the failure is non-fatal — but the user sees the row marked ERROR with a readable message. Wave 0 verifies the fixture contains only ISO-4217 currencies.

**Warning signs:** No test for "what happens when the ICS CSV row's currency is malformed".

## Code Examples

### Example 1: ICS CSV adapter shape-a (one row per logical transaction)

```php
// Source: mirrors Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php
// Wave 0 specifies the exact column indices in IcsCsvColumnMap.

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Generator;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ledger\Public\Dto\StatementSummaryData;

final class IcsCsvAdapter implements SourceAdapter
{
    public function __construct(
        private readonly HeaderSniffer $sniffer,
    ) {}

    public function format(): string
    {
        return IcsCsvHeaderProfile::FORMAT; // 'ics-csv'
    }

    public function statementMetadata(): ?StatementSummaryData
    {
        return null; // Phase 3 doesn't yet emit statement summaries for ICS;
                    // revisit if Wave 0 surfaces a usable period/balance.
    }

    public function parse(string $localPath, AccountResolver $accounts): Generator
    {
        $this->sniffer->sniff($localPath, IcsCsvHeaderProfile::FORMAT);

        $reader = Reader::from($localPath, 'r');
        $reader->setDelimiter(IcsCsvHeaderProfile::DELIMITER);
        $reader->setEscape('');
        $reader->setHeaderOffset(0);
        CharsetConverter::addTo($reader, IcsCsvHeaderProfile::SOURCE_ENCODING, 'UTF-8');

        $index = 0;
        foreach ($reader->getRecords() as $record) {
            $row = $this->normaliseRow($record);

            // ... date parsing, currency parsing, amount parsing ...

            $hasFxRow = $row[IcsCsvColumnMap::ORIGINAL_AMOUNT] !== '' &&
                        $row[IcsCsvColumnMap::ORIGINAL_CURRENCY] !== '';

            yield new SourceTransactionDto(
                bookedAt: $bookedAt,
                postedAt: $postedAt,
                valueDate: $valueDate,
                ownIban: $icsAccountIban,           // synthetic; ICS has no IBAN per row
                counterpartyIban: null,
                counterpartyName: $merchantName,
                currency: $hasFxRow ? $originalCurrency : 'EUR',
                amountMinor: $hasFxRow ? $originalMinor : $settledEurMinor,
                sourceRef: $authCode,               // ?string per D-34 Wave 0
                description: $merchantNarrative,
                rawPayload: $row,
                sourceRowIndex: $index,
                // D-42 new fields:
                settledAmountMinor: $hasFxRow ? $settledEurMinor : null,
                settledCurrency: $hasFxRow ? 'EUR' : null,
                fxRateUsed: null,                   // adapter never derives; NormalizeStage does
            );

            $index++;
        }
    }
}
```

### Example 2: NormalizeStage with D-42 substitution + D-39 derivation

```php
// Source: Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php (extension)
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

public function run(
    SourceTransactionDto $source,
    int $accountId,
    User $user,
    int $importRunId,
    string $sourceFormat,
): CanonicalTransaction {
    // ... existing counterparty normalisation + type derivation ...

    // D-42 + D-39: substitute settled = native when source omits the pair;
    // derive fx_rate_used when both legs are present and the currencies differ.
    $settledMinor = $source->settledAmountMinor ?? $source->amountMinor;
    $settledCurrency = $source->settledCurrency ?? $source->currency;

    $fxRateUsed = null;
    if ($source->settledAmountMinor !== null
        && $source->settledCurrency !== null
        && $source->amountMinor !== 0
        && $source->settledCurrency !== $source->currency
    ) {
        $fxRateUsed = (string) BigDecimal::of((string) $source->settledAmountMinor)
            ->dividedBy(
                BigDecimal::of((string) $source->amountMinor),
                8,                          // matches decimal(18,8) column scale
                RoundingMode::HALF_UP,
            );
    }

    return new CanonicalTransaction(
        // ...
        amountMinor: $source->amountMinor,
        currency: $source->currency,
        settledAmountMinor: $settledMinor,
        settledCurrency: $settledCurrency,
        fxRateUsed: $fxRateUsed,
        // ...
    );
}
```

### Example 3: ThisPeriodAtAGlanceQuery group-by-currency mode (D-46)

```php
// Source: extends Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
// Returns one or more tile-rows depending on the mode.

public function forByCurrency(User $user, Period $period): array
{
    $rows = $this->db->connection()
        ->table('transactions')
        ->where('user_id', $user->id)
        ->where('posted_at', '>=', $period->start->toDateString())
        ->where('posted_at', '<',  $period->endExclusive->toDateString())
        ->groupBy('settled_currency')
        ->selectRaw(
            'settled_currency,
             COALESCE(SUM(CASE WHEN settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END), 0) AS inflow_minor,
             COALESCE(SUM(CASE WHEN settled_amount_minor < 0 THEN -settled_amount_minor ELSE 0 END), 0) AS outflow_minor,
             COALESCE(SUM(settled_amount_minor), 0) AS net_minor'
        )
        ->orderBy('settled_currency')  // deterministic order; planner may switch to abs-amount-desc
        ->get();

    // Map each row to a PerCurrencyTile DTO with brick/money Money instances.
    // EUR row collapses to a single tile (which then renders identically to
    // the existing EUR-only-mode UI per the "specifics" insight).
    return $rows->map(fn ($r) => new PerCurrencyTile(
        currency: (string) $r->settled_currency,
        inflow: Money::ofMinor((int) $r->inflow_minor, (string) $r->settled_currency),
        outflow: Money::ofMinor((int) $r->outflow_minor, (string) $r->settled_currency),
        net: Money::ofMinor((int) $r->net_minor, (string) $r->settled_currency),
    ))->all();
}
```

The existing EUR-only `for()` method stays — Phase 3 adds `forByCurrency()` as a sibling. The dashboard's Livewire component picks one based on the `currency` toggle state. This keeps the EUR-only-mode path untouched and lets Pest test both modes independently.

### Example 4: Money formatter — locale-aware EUR + ISO-symbol non-EUR

```php
// Source: extension to Modules/Ledger/Public/ValueObjects/Money

public function format(?string $locale = null): string
{
    // Dutch-locale formatting for EUR (€-prefix, comma decimal); ISO-symbol
    // prefix for non-EUR currencies (e.g., '$12.99' for USD).
    if ($this->currency() === 'EUR') {
        return $this->inner->formatTo($locale ?? 'nl_NL');
    }
    // For non-EUR, en_US gives the conventional '$12.99' or 'US$12.99' depending
    // on the runtime locale extensions; verify against test snapshots and
    // override via a CurrencyFormatter if the symbol output drifts.
    return $this->inner->formatTo($locale ?? 'en_US');
}
```

Caveat: `MoneyLocaleFormatter` uses PHP's `NumberFormatter` (intl extension). Verify `ext-intl` is enabled in the Herd PHP build (it ships by default; greppable check). If absent, fall back to a simpler `ISOCurrencyProvider`-aware string composer.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Manual `fgetcsv` + cell-by-cell encoding conversion | `League\Csv\Reader` + `CharsetConverter::addTo()` (stream filter) | League CSV 9.x (current 9.28.0, Dec 2025) | Lazy streaming + automatic encoding conversion. Already established in `AsnCsvAdapter`. `[VERIFIED: csv.thephpleague.com/9.0/converter/charset/]` |
| moneyphp/money (used internally by genkgo/camt) | brick/money for project domain code | Phase 1 (FND-07) | Immutable VO API, BigDecimal arithmetic. Both libs coexist in vendor; the canonical Modules path uses brick. |
| Livewire 2 `$queryString` array property | Livewire 3+/4 `#[Url]` attribute | Livewire 3, refined in 4.x | Per-property opt-in, attribute-driven, type-safe aliases. `[VERIFIED: livewire.laravel.com/docs/4.x/attribute-url]` |
| Hand-rolled segmented buttons + `wire:click="setCurrency('eur')"` | `flux:radio.group variant="segmented" wire:model.live="currency"` | Flux 2 (v2.14.1, Apr 2026) | One attribute drives selection + URL sync + a11y. `[VERIFIED: vendor/livewire/flux stubs + fluxui.dev/components/radio]` |
| `Auth::user()` / `auth()->user()` in domain code | DI-injected `CurrentUser` (`Modules\Core\Public\Contracts\CurrentUser`) | Phase 1 D-12 | Test ergonomics + Larastan level 10 strict. |

**Deprecated/outdated:**
- **moneyphp/money for new code paths.** Coexists in vendor (genkgo/camt depends on it for CAMT.053 amounts) but project policy is brick/money in Modules/.
- **`Auth::*` facade calls in Modules/.** Forbidden by Phase 1 custom Larastan rule.
- **Property-style constructor DI on Livewire 4 Component subclasses.** Replaced by method-level DI per `01-05-SUMMARY.md` Auto-fix #5/#6 — collaborators land on `mount`/`save`/`submit`/`confirm`/etc. parameter lists.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | ICS CSV uses Windows-1252 OR UTF-8 encoding (planner picks `CharsetConverter` target after Wave 0) | Code Examples §1 | `[ASSUMED]` — `AsnCsvAdapter`'s precedent suggests legacy Dutch banks use Windows-1252; the ASN export turned out to be US-ASCII in 2026 (per `asn-sample-1.md`). The adapter's `CharsetConverter::addTo($reader, $source, 'UTF-8')` is safe regardless: if Wave 0 finds UTF-8, set `SOURCE_ENCODING = 'UTF-8'` and the filter becomes a no-op. Low risk — easy to flip post-Wave-0. |
| A2 | The ICS CSV has at most two row-shapes for FX charges (D-35 case a vs b) | Pattern 2 | `[ASSUMED]` — derived from analogous PayPal CSV behaviour. If Wave 0 reveals a third shape (e.g. three rows per FX charge: merchant + conversion + markup) the look-ahead buffer needs N-row generalisation. Medium risk; the buffer pattern generalises easily. |
| A3 | The ICS CSV's "auth code" column (if present) is a stable per-transaction reference suitable for `source_ref` | D-34 | `[ASSUMED]` — analogous to ASN's `Volgnummer`. Wave 0 confirms. If unstable, `source_ref` is NULL and the v3 fingerprint is the only dedup anchor — still safe. |
| A4 | ICS account currency is always EUR (settlement currency) | D-36 | `[VERIFIED: icscards.nl/klantenservice exchange-rate docs]` — "ICS converts to euros using the Visa/Mastercard rate" — settlement is in EUR. The card-charge currency varies; the account currency does not. |
| A5 | `brick/money` 0.11 → 0.13 are API-compatible on the surface used by `Modules/Ledger/Public/ValueObjects/Money` (`ofMinor`, `plus`, `minus`, `formatTo`) | Stack table | `[VERIFIED: vendor/brick/money/src/Money.php head + composer.json constraint inspection]` — `ofMinor` signature has been stable across recent minor releases. Low risk; only upgrade if needed. |
| A6 | Livewire 4's `#[Url]` attribute is available on Volt SFC components (not only class components) | Pattern 4 | `[ASSUMED]` — the Livewire 4 docs imply yes (attributes are PHP-level), but the cited page does not explicitly confirm Volt compatibility. If Volt SFC support is missing, fall back to a class-style component for `/transactions`. Low risk; both styles work. |
| A7 | The Settings page does NOT need a feature flag — shipping it is non-breaking for Phase 1/2 users | D-45 | `[ASSUMED]` — adding a new route + adding nullable column + reading with `?? 'eur_only'` fallback. Verified no Phase 1/2 code reads from a `user_preferences` table (none exists yet). Low risk. |
| A8 | `MoneyLocaleFormatter` requires `ext-intl` (`NumberFormatter`), which is enabled by default in Laravel Herd's PHP 8.3+ build | Code Examples §4 | `[ASSUMED]` — Herd ships `ext-intl`. Verify with `php -m \| grep intl` before relying on it; fallback is a hand-rolled formatter using `ISOCurrencyProvider` only. Low risk; remediation is well-scoped. |
| A9 | The ICS markup row, if separable in the CSV, can be safely folded into `settled_amount_minor` without breaking any Phase 1/2 invariant | D-40 | `[ASSUMED]` — D-40 explicitly says rolling it in is the design; markup-recovery is a future-phase concern. Low risk; the data is preserved in `rawPayload`. |
| A10 | No new composer dependencies are required for Phase 3 | Installation | `[VERIFIED: composer.json + composer.lock inspection]` — every library named in this research (`league/csv`, `brick/money`, `livewire/livewire`, `livewire/flux`, `spatie/laravel-data`, `pest`, `pest-plugin-arch`, `pest-plugin-snapshots`) is already in `composer.lock`. Zero risk. |

**Discuss-phase signal:** A1, A2, A3 will be answered by Wave 0 with no user input needed. A6 should be verified by a planner spike (~2 minutes — try `#[Url]` on a Volt component). A8 needs a one-line check during Plan 1 execution. The rest are low risk or verified.

## Open Questions (RESOLVED)

1. **Currency formatter symbol output for non-EUR — what does PHP's `NumberFormatter` produce on macOS/Herd today?**
   - What we know: `MoneyLocaleFormatter` uses `NumberFormatter(locale, CURRENCY)`. On `en_US`, USD renders as `$12.99`. On `nl_NL`, USD renders as `US$ 12,99` (note the `US$` qualifier prefix Dutch locale uses for non-domestic currencies).
   - What's unclear: which one matches the UI's "calm" aesthetic best — short prefix (`$12.99`) for compact rows, or locale-correct (`US$ 12,99`) for Dutch nationals. CONTEXT D-48 + Claude's discretion §"Currency formatting" defer this to the planner.
   - Recommendation: Plan 5 builds the dual-line transaction view; the planner picks one and documents it. Either is defensible. Use snapshot tests so the choice is auditable.
   - **RESOLVED:** Format non-EUR with locale `en_US` (e.g. `$74.43`); format EUR with locale `nl_NL` (e.g. `€68,86`). Per-transaction FX rate detail formats as `€0.929 / USD` via `NumberFormatter::CURRENCY` for the EUR base + ` / ` + ISO suffix (3 decimal places via `number_format($rate, 3, '.', '')`). Locked in plan 03-06 Task 2 (`Money::format()` parameterless default) and plan 03-07 (transaction detail FX-rate row).

2. **Does the `IdempotencyContractTest` dataset need an `ics-csv` row?**
   - What we know: Phase 2 added `asn-camt053` and `asn-mt940` dataset rows with same-file fallback. Phase 3 should follow the same pattern.
   - What's unclear: whether the ICS fixture corpus will support a true overlap pair (two months, one of which is a subset) by Wave 0 close.
   - Recommendation: Plan 1 (Wave 0) produces at minimum `tests/fixtures/ics-sample-1.csv`. The Pest dataset adds an `ics-csv` row with same-file fallback (mirroring `asn-camt053` / `asn-mt940`). If the user provides a multi-month export, derive `ics-month-a.csv` and `ics-month-a-and-b.csv` as overlap derivatives.
   - **RESOLVED:** Add an `ics-csv` row to the contract dataset using the same-file fallback shape (mirrors `asn-camt053` / `asn-mt940`). Wired in plan 03-02 Task 3.

3. **Is the `transactions.source_ref` column unique-able for ICS?**
   - What we know: Phase 1 schema has no `UNIQUE(account_id, source_ref)` — the only UNIQUE on the table is the v3 fingerprint composite. So ICS rows with non-null `source_ref` cannot collide via a unique-constraint exception even on the same `source_ref` value (the fingerprint guards instead).
   - What's unclear: nothing — this is the intended state. Listed here so planners don't accidentally add a UNIQUE on `source_ref` when the ICS adapter populates it from an auth code.
   - Recommendation: No action.
   - **RESOLVED:** Keep the existing non-unique `transactions.source_ref` column. Idempotency continues to be enforced by the `(source_id, external_id)` composite already used for ASN — ICS reuses that composite key via `SourceTransactionDto`. No schema change in Phase 3.

4. **Should the wizard pre-select `'ics-csv'` when the user has previously imported an ICS file?**
   - Out of scope for Phase 3 (D-38 is wizard wording, not stickiness). Mentioned because the two-step picker shape opens the door to remembering the last-used issuer.
   - **RESOLVED:** Deferred — no pre-selection in v1. The user manually picks issuer + format on every import (matches D-33 two-step picker UX). Revisit only if the user explicitly requests sticky selection in a later phase.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.5 | All code paths | Required by composer | Constrained `^8.5` | — |
| `ext-intl` | `MoneyLocaleFormatter` (non-EUR symbol prefix) | Likely (Herd default) | — | Hand-rolled formatter via `ISOCurrencyProvider::getCurrency()->getSymbol()` |
| `ext-mbstring` | `league/csv` `CharsetConverter` | Required by Laravel | — | — |
| SQLite 3.45+ | All persistence | Already verified Phase 1 | — | — |
| Laravel Herd (PHP 8.5 build) | Local dev | Phase 1 assumption | — | Use vanilla PHP-FPM if absent |
| `brick/money` 0.11+ | Multi-currency arithmetic | Installed | 0.11.2 | — |
| `livewire/flux` 2.x | Segmented control | Installed | v2.14.1 | Hand-rolled Tailwind buttons (worse a11y) |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** `ext-intl` (verify during Plan 1 / Wave 0 with `php -m \| grep intl`; fall back to hand-rolled formatter only if absent).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.7.0 + PHPUnit (engine) |
| Config file | `phpunit.xml` at repo root + per-module `tests/Pest.php` |
| Quick run command | `vendor/bin/pest --filter=<TestName> --memory-limit=1G` |
| Full suite command | `vendor/bin/pest --memory-limit=1G` |
| Phase-scoped run | `vendor/bin/pest --group=phase-3` (planner adds the group tag in Plan 1 Wave 0, mirroring Phase 2's group-registration step) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ING-04 | User uploads ICS CSV → transactions imported | feature | `vendor/bin/pest Modules/Import/tests/Feature/IcsCsvImportTest.php` | ❌ Wave 0 (new file) |
| ING-04 | Adapter parses a known fixture into the expected canonical DTO stream | unit + snapshot | `vendor/bin/pest Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` | ❌ Wave 0 (new file) |
| ING-04 | Adapter rejects an ICS CSV with wrong header signature | unit | included in `IcsCsvAdapterTest.php` (`it('rejects non-ICS CSV with HeaderSniffer message')`) | ❌ Wave 0 |
| ING-04 | Re-importing the same ICS file → 0 new rows (idempotency) | contract | `vendor/bin/pest tests/Contracts/IdempotencyContractTest.php --filter='ics-csv'` | ✅ extend dataset only |
| LED-03 | A USD ICS row lands with `currency=USD, amount_minor=1299, settled_currency=EUR, settled_amount_minor=1207, fx_rate_used='0.93...'` | unit (NormalizeStage) | `vendor/bin/pest Modules/Import/tests/Unit/NormalizeStageTest.php` | ⚠ extend existing file |
| LED-03 | EUR-native row from any adapter still mirrors settled = native, `fx_rate_used` is NULL | unit (NormalizeStage) | same | ⚠ existing assertion still passes |
| MC-02 | `/transactions?currency=eur` projects settled-EUR pair; no param projects native | feature (Livewire) | `vendor/bin/pest Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` | ❌ new |
| MC-02 | Saving `default_currency_view='original'` via `/settings` makes `/transactions` default to original on next visit | feature (Settings round-trip) | `vendor/bin/pest Modules/Core/tests/Feature/SettingsPageTest.php` | ❌ new |
| MC-02 | Dashboard original-mode GROUPs BY currency; EUR-only month collapses to one tile-row | feature (dashboard) | `vendor/bin/pest Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` | ❌ new |
| UI-06 | A USD row in `/transactions` original-mode renders two lines (native + muted settled); EUR-native row renders one line | feature (Livewire) | included in `TransactionsListCurrencyToggleTest.php` | ❌ new |
| UI-06 (D-48) | Transaction detail page surfaces `fx_rate_used` when populated | feature | `vendor/bin/pest Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` | ❌ new |
| MC-02 (arch) | No new `auth()` / `Auth::*` / facade calls slipped into Phase 3 code | arch | `vendor/bin/pest tests/Contracts/BoundaryArchTest.php` (regression preserved) | ✅ |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter=<TestName>` for the test(s) the task is making green
- **Per wave merge:** `vendor/bin/pest --group=phase-3 --memory-limit=1G` — must be all-green before merging the wave to main
- **Phase gate:** `vendor/bin/pest --memory-limit=1G` + `vendor/bin/phpstan analyse --memory-limit=1G` + `vendor/bin/pint --test` all green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv` — anonymised raw ICS export (Wave 0 deliverable)
- [ ] `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` — fixture record document (column map, anonymisation protocol, FX-row shape disclosure) mirroring `tests/fixtures/asn-sample-1.md`
- [ ] `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` — adapter unit tests + snapshot file under `__snapshots__/`
- [ ] `Modules/Import/tests/Feature/IcsCsvImportTest.php` — feature test for ING-04 success criterion 1
- [ ] `tests/Contracts/IdempotencyContractTest.php` — dataset extension (new `'ics-csv'` row with same-file fallback)
- [ ] `Modules/Core/tests/Feature/SettingsPageTest.php` — round-trip test for `default_currency_view` + `period_start_day`
- [ ] `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` — Livewire feature test for D-44 + D-47
- [ ] `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` — D-46 EUR-only-vs-original mode test
- [ ] `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` — D-48 detail-page FX-rate surface
- [ ] Phase-3 Pest group registration (similar to how Phase 2 registered the `phase-2` group)

*Wave 0 will also produce:* the redacted fixture file + an `ics-sample-1.md` mirroring `asn-sample-1.md`, answers to D-34 (source_ref availability) and D-35 (FX-row shape) and D-40 (markup separability), and the `IcsCsvHeaderProfile` + `IcsCsvColumnMap` constants.

## Security Domain

> Phase 3 has narrow security surface — file-upload primitives are already hardened in Phase 1. Below is the targeted ASVS slice.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (reuses Phase 1 Fortify auth) | Existing login flow; SettingsPage requires `auth` middleware |
| V3 Session Management | yes (reuses Phase 1 session policy) | 30-day session + remember-me from D-11; no new session surface |
| V4 Access Control | yes | Every Settings + Transactions Livewire surface scopes by `CurrentUser`; cross-user reads forbidden via `firstOrFail`/`where('user_id', ...)` (Phase 1/2 pattern) |
| V5 Input Validation | yes | (a) Livewire `#[Validate]` on Settings form fields + `in:eur_only,original` + `min:1\|max:28`; (b) HeaderSniffer rejects mis-declared uploads before parsing; (c) `Money::ofMinor` rejects non-ISO-4217 currency codes loudly |
| V6 Cryptography | no (no new crypto in Phase 3) | — |
| V7 Error Handling | yes | Per-row exceptions converted to ERROR `PreviewRowDto` per Phase 1 pattern; never leak stack traces to the user |
| V12 File / Resources | yes (file upload) | (a) sanitiseFilename in `UploadWizard::submit` (Phase 1, intact); (b) `max:10240` size limit; (c) `mimes` whitelist extended to include `csv` for ICS (already present) |

### Known Threat Patterns for Laravel + Livewire + CSV

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| CSV injection (formula-injection on export) | Tampering | N/A — Phase 3 has no CSV export path (deferred to v2 per REQUIREMENTS.md) |
| Path traversal via filename | Tampering | `UploadWizard::sanitiseFilename` strips path chars; extension is keyed off `sourceFormat`, not the user-supplied name (Phase 1 pattern) |
| Oversized upload (DoS) | DoS | `max:10240` (10 MB) Livewire validation rule; adapter is a Generator so a parsed file streams |
| Cross-user data leak via Settings page | Information Disclosure | SettingsPage reads/writes via `CurrentUser->user()` only; never queries `User::find($id)` from a request param |
| Cross-user data leak via `?currency=` URL | Information Disclosure | The URL parameter only controls the **rendering** projection, not the row scope. `TransactionListQuery::baseQuery` always filters by `where('transactions.user_id', $user->id)` (verified in existing source) |
| Foreign-currency code injection in `Money::ofMinor` | Tampering | brick/money throws `UnknownCurrencyException` on non-ISO codes; the ImportPipeline catches per-row exceptions and converts to ERROR; verifier ensures Wave 0 fixture contains only ISO-4217 codes |
| Settings form CSRF | Spoofing | Livewire-native CSRF protection (every wire:click + wire:submit is CSRF-protected by default) |
| XXE in CSV upload | N/A | CSV is plain-text; XXE applies only to the CAMT.053 XML path (already mitigated Phase 2 plan 03) |

## Sources

### Primary (HIGH confidence)

- **Phase 1/2 shipped artefacts** (in this repo) — `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php`, `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php`, `Modules/Ledger/Public/Services/TransactionListQuery.php`, `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php`, `Modules/Core/Models/User.php`, `Modules/Core/Internal/Console/InstallCommand.php`, `tests/Contracts/IdempotencyContractTest.php`, `tests/fixtures/asn-sample-1.md` — the load-bearing precedents for every Phase 3 pattern.
- **Phase 1 + 2 SUMMARY artefacts** — `.planning/phases/01-foundation-asn-csv-vertical-slice/01-05-SUMMARY.md` and `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-05-SUMMARY.md` — Livewire 4 method-level DI pattern, redirect-as-method pattern, JSON-locked PreviewCache, four-state preview wizard, per-row DB-transaction pattern.
- [Livewire 4 #[Url] attribute docs](https://livewire.laravel.com/docs/4.x/attribute-url) — `[VERIFIED]` Property-to-query-string binding semantics including `as`, `keep`, `history`, `except`.
- [Flux UI radio.group segmented variant](https://fluxui.dev/components/radio) — `[VERIFIED]` Segmented variant syntax + wire:model integration.
- [League CSV CharsetConverter](https://csv.thephpleague.com/9.0/converter/charset/) — `[VERIFIED]` `CharsetConverter::addTo($csv, $input_encoding, $output_encoding)` signature confirmed.
- [Packagist brick/money](https://packagist.org/packages/brick/money) — `[VERIFIED]` Latest 0.13.0 (2026-03-28), installed 0.11.2; `Money::ofMinor` / `Money::of` API stable.
- [Packagist league/csv](https://packagist.org/packages/league/csv) — `[VERIFIED]` Latest 9.28.0 (2025-12-27).
- [Packagist livewire/flux](https://packagist.org/packages/livewire/flux) — `[VERIFIED]` Latest v2.14.1 (2026-04-23).
- **Local Flux stub inspection** — `vendor/livewire/flux/stubs/resources/views/flux/radio/group/variants/segmented.blade.php` confirms the segmented variant is bundled and active in the installed package.
- **Local brick/money inspection** — `vendor/brick/money/src/Money.php` + `vendor/brick/money/src/Formatter/MoneyLocaleFormatter.php` confirm `ofMinor` / `of` static factories and `MoneyLocaleFormatter` API.

### Secondary (MEDIUM confidence)

- [ICS Cards exchange-rate documentation](https://www.icscards.nl/gold/wisselkoersen) — Confirms ICS converts foreign-currency charges to EUR using Visa/Mastercard rate + 2% markup; cited for D-40's "rate is effective post-markup" claim.
- [MartinSGill/ICS-Cards-Download-Statements (GitHub)](https://github.com/MartinSGill/ICS-Cards-Download-Statements/blob/master/DownloadStatement.py) — Third-party reverse-engineering of ICS Cards CSV output; lists columns `Datum / * / Omschrijving / Card-nummer / Debet / Credit / Valuta / Bedrag / payee / out / in`. NOT the canonical ICS export — this is a third-party scraper's output shape. Used as a hint that ICS exposes Datum/Omschrijving/Card-nummer/Valuta/Bedrag-style columns; **the actual ICS portal CSV is what Wave 0 captures**.
- [Yuki Support — ICS export files (NL)](https://support.yuki.nl/en/support/solutions/articles/80000786204-international-card-services-ics-export-files) — Confirms ICS provides a CSV download from Mijn ICS Business portal; does not document columns.
- [bunni.nl CSV ICS downloaden tutorial](https://bunni.nl/banktransacties/csv-ics-downloaden/) — Confirms the user-facing download flow at icscards.nl; no column spec.

### Tertiary (LOW confidence)

- [IeuanK/ICS-Exporter user-script](https://github.com/IeuanK/ICS-Exporter/) and [sietsevdschoot/ics-cards-downloadstatements](https://github.com/sietsevdschoot/ics-cards-downloadstatements) — Third-party scrapers; do not document the canonical CSV export. Used only to confirm the absence of public documentation.
- [bank2ynab supported banks list](https://github.com/bank2ynab/bank2ynab) — ICS is NOT in the supported-banks list; absence is consistent with "no canonical public CSV spec exists for icscards.nl".

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every dependency verified against `composer.lock` + Packagist + vendor inspection. Zero new dependencies required.
- Architecture: HIGH — every pattern is a direct extension of Phase 1/2 shipped code (verified by reading source).
- Pitfalls: HIGH for the four canonical ones (FX info loss, fixture absence, wizard snapshot brittleness, brick/money strictness); MEDIUM for the dashboard GROUP-BY edge case (depends on Wave 0 fixture coverage).
- ICS-specific format details: MEDIUM — intentionally deferred to Wave 0 per D-32. The research enumerates BOTH possible shapes (D-35 a/b) so the planner can structure the adapter without locking in either.
- Settings storage decision: MEDIUM — both options are valid; recommendation is Option A (column on `users`) but the door stays open per Phase 1 D-19.

**Research date:** 2026-05-13
**Valid until:** 2026-06-12 (30 days for the verified stack pins + framework patterns; revisit if Wave 0 surfaces an ICS shape that contradicts D-34 / D-35 / D-40, which would require a CONTEXT addendum, not a full re-research)
