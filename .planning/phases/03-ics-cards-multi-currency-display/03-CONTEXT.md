# Phase 3: ICS Cards + Multi-Currency Display - Context

**Gathered:** 2026-05-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 3 broadens ingestion to ICS Cards (CSV only) and lights up the multi-currency machinery that Phase 1 front-loaded but never exercised. The user can: upload an ICS Cards CSV export and see foreign-currency charges (e.g. a USD purchase) imported with both the native pair (`$X` / `USD`) and the settled pair (`€Y` / `EUR`) preserved on the same canonical row; toggle the `/transactions` list and the dashboard "this month at a glance" tiles between EUR-only and original-currency presentation, with the per-page choice overriding a global default stored in a new minimal `/settings` page; and rely on the schema to never lose FX information that the source provided (effective `fx_rate_used` derived from settled / original when both legs are present).

The wizard's upload UI is refactored from Phase 2's flat dropdown into a two-step grouped picker (issuer → format) so PayPal and Google Play groups in later phases land cleanly. The `SourceTransactionDto` contract gains nullable `settledAmountMinor`/`settledCurrency`/`fxRateUsed` fields so adapters can yield foreign-currency rows; Phase 1/2 ASN adapters keep returning `null` and the NormalizeStage substitutes settled=native, rate=null for them.

This phase does NOT add per-card visibility (single ICS Account in Phase 3 — the user only has one card), does NOT add PayPal / Google Play / email-receipt ingestion, does NOT touch chain resolution, recurring detection, or forecasting. Those are Phases 4 through 11 per ROADMAP.md.

</domain>

<decisions>
## Implementation Decisions

### ICS Export Format Coverage

- **D-31:** **CSV only.** No `phpoffice/phpspreadsheet` dependency in Phase 3. The ICS adapter mirrors `AsnCsvAdapter`'s lazy-Generator + BOM-safe + `league/csv` pattern. Excel ingestion is deferred — revisit if the user's ICS portal ever drops the CSV export.
- **D-32:** **Real-fixture-first Wave 0.** The user provides a raw ICS CSV export; **anonymisation is OUR job, not the user's**. Wave 0 receives the raw file, anonymises card numbers / cardholder names / any cross-referenceable identifiers, preserves dates / amounts / currencies / merchants verbatim, and writes the redacted fixture under `Modules/Ingestion/tests/fixtures/ics/`. The Wave 0 plan reports back on (a) column layout, (b) source_ref availability (D-34), and (c) FX-row structure (D-35) — and only then is the adapter plan written. Mirrors Phase 2 Plan 1's enablement wave.
- **D-33:** **Two-step grouped wizard picker.** Refactor `UploadWizard`'s flat `['asn-csv', 'asn-camt053', 'asn-mt940']` dropdown into a two-step picker: first "Which issuer?" (ASN / ICS), then "Which format?" (ASN → CSV / CAMT.053 / MT940; ICS → CSV). The `sourceFormat` field still stores the leaf key (`ics-csv`); `HeaderSniffer` still validates the declared format. PayPal and Google Play groups in later phases extend the issuer list without touching the format dropdown logic again.
- **D-34:** **`source_ref` strategy is deferred to Wave 0 fixture inspection.** If the ICS CSV exposes a stable per-transaction reference (auth code / slip number / txn ID), the adapter sets `source_ref` from that column. If not, `source_ref` is `NULL` and the v3 fingerprint tuple is the only dedup anchor. Either way, this matches the contract Phase 2 D-23/D-23a established (sources may legitimately have NULL source_refs; chain resolution adapts).
- **D-35:** **FX-row shape is deferred to Wave 0 fixture inspection.** Two possibilities the adapter must handle: (a) one CSV row with both original-currency and settled-EUR columns → adapter yields one `SourceTransactionDto`; (b) two CSV rows per FX charge (merchant line + FX-conversion line) → adapter rolls them up into one canonical row, similar to how Phase 4's PayPal event-log rollup will work. The wizard preview MUST continue to show one preview row per canonical transaction regardless of source-row count.

### ICS Account Modeling

- **D-36:** **Single ICS Account in Phase 3.** The user has only one ICS card today. The `accounts` table records one row with `type = 'ics_card'` (new value alongside existing `bank` / `paypal` etc.), nameable by the user during first upload, currency `EUR` (the settlement currency). No `card_last4` column, no per-card subtable.
- **D-37:** **No card_number extraction in Phase 3.** The CSV's card-number column (if any) is dropped at the adapter boundary and NOT preserved in `rawPayload`. Per-card visibility is a v2 problem.
- **D-38 (Claude's discretion):** **Wizard prompts to name the ICS Account on first upload.** Mirrors Phase 1's "unknown IBAN → name the account" step (D-14), but the trigger is "no Account of type `ics_card` exists yet" rather than IBAN-not-found. Subsequent ICS uploads skip the prompt. Implementation lives in `UploadWizard` alongside the IBAN-account-naming step.

### FX Rate + ICS Markup Handling

- **D-39:** **`fx_rate_used` is derived: `settled_amount_minor / amount_minor`** with `decimal(18,8)` precision (the column's existing shape). Populated whenever both legs are present on the source row. This is the **effective** rate the user actually paid — markup-inclusive — which is the rate Phase 9 drift detection will care about. The column stays `NULL` when no FX took place (EUR-native rows).
- **D-40:** **ICS markup is invisible at the canonical layer in Phase 3.** When the CSV exposes an explicit markup / fee row alongside the merchant line, the adapter rolls both into one canonical row whose `settled_amount_minor` already includes the markup. The markup figure remains recoverable from `rawPayload` for any later phase that wants to split it out. Revisit if Wave 0 shows that ICS markup is structurally separable in a way that materially changes Phase 9 drift detection.
- **D-41:** **EUR-native rows mirror native → settled.** The Phase 1 migration declared `settled_amount_minor` + `settled_currency` `NOT NULL`. For a EUR-native ICS row (or any future EUR-native source), the canonical row sets `settled_amount_minor = amount_minor`, `settled_currency = currency`, `fx_rate_used = NULL`. No schema change required.
- **D-42:** **`SourceTransactionDto` gains nullable `settledAmountMinor`, `settledCurrency`, `fxRateUsed` fields.** Phase 1/2 ASN adapters keep yielding `null` (every Phase 1/2 row was EUR-native). The Import pipeline's NormalizeStage substitutes `settled = native`, `fx_rate_used = null` when the source DTO leaves them `null`, then constructs the `CanonicalTransaction` (whose `settledAmountMinor` + `settledCurrency` remain `NOT NULL` per Phase 1's contract). This keeps the canonical layer's non-null guarantees intact while letting source-format adapters be honest about whether the source provided settled info or not. Cleanest typing path for Larastan level 10 strict.
- **D-43 (implicit / no change):** **The v3 fingerprint tuple uses native `amount_minor` + `currency`.** Two USD charges of the same merchant on the same day differ in seconds (the booked_at column) or in amount — same as Phase 2's EUR analogue. Fingerprint composer needs no change for multi-currency.

### Dual-Currency Display UX

- **D-44:** **Toggle scope = both global default + per-page override.** A new `default_currency_view` user-preference field (`'eur_only'` | `'original'`) is read from Settings as the default. The `/transactions` page surfaces a Flux segmented control that, when toggled, overrides the default via a `?currency=` URL query parameter (`?currency=eur` or no query for default). URL is the source of truth for the request; refresh-stable.
- **D-45:** **Ship a minimal `/settings` page in Phase 3.** Discharges Phase 1's deferred Settings UI question (D-19's "planner picks"). Surfaces: (a) `default_currency_view` toggle, (b) the existing `period_start_day` integer (migrated from install-only config to user-editable). Lives under `Modules/Core/Internal/Http/Livewire/SettingsPage.php`. Calm aesthetic per UI-05; one accent color.
- **D-46:** **Dashboard tiles respond to the toggle.** In EUR-only mode, `ThisPeriodAtAGlanceQuery` returns one set of in/out/net totals computed from `settled_amount_minor WHERE settled_currency = 'EUR'`. In original-currency mode, the query GROUPs BY currency and returns one tile-row per currency present in the period. EUR-only months collapse to a single row in original-currency view (visually identical to EUR-only view). The Blade view stacks tile-rows vertically.
- **D-47:** **Transaction rows render two-line stack when native ≠ settled in original-currency view.** Primary line: native (e.g. `$12.99 USD`). Secondary line in muted/`text-muted-foreground`: settled (`€12.07 EUR`). In EUR-only view, rows show only the settled amount on one line. The existing `TransactionListQuery`'s `?string $currency` projection (which already swaps `display_minor`/`display_currency` between native and settled) is consumed by the Blade view to drive both lines. For EUR-native rows in either view, only one line renders.
- **D-48 (Claude's discretion):** **FX-rate placement = transaction detail page only.** Success Criterion 2 requires the per-transaction FX rate to "surface when available". Inline on the list row adds visual density that conflicts with UI-05; tooltip-only hides information until hover. The transaction-detail drill-in page (post-Phase 1) is the natural home: it renders "Charged $12.99 USD · effective rate €0.929/USD · settled €12.07 EUR" alongside the existing detail fields. Planner may revisit if the user wants the rate more prominent.

### Claude's Discretion

- **D-38 above:** Exact wizard wording for the "name your ICS Account" step (e.g. "ICS card / Mastercard" suggested vs blank input).
- **D-48 above:** Exact rendering of the FX-rate string on the detail page (rate orientation `EUR/USD` vs `USD/EUR`; precision).
- **Currency formatting** — `brick/money` formatter selection (locale-aware vs ISO-symbol). Default to Dutch-locale formatting (€-prefix, comma decimal) for EUR; ISO-symbol-prefix (e.g., `$` for USD) for non-EUR currencies. Planner can override per UI sketch.
- **Settings page styling** — match the calm Linear/Notion aesthetic; reuse Flux primitives that already exist for the install command's text inputs.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints (PHP 8.5 + Laravel 13, DI-only, nwidart modules, Larastan level 10 strict, Pint, Pest, no frontend tests, localhost-only, calm aesthetic)
- `.planning/REQUIREMENTS.md` — Phase 3 covers ING-04 (ICS import), LED-03 (dual-amount schema; mostly populated work since Phase 1 declared the columns), MC-02 (EUR/dual toggle), UI-06 (per-row original-currency surface)
- `.planning/ROADMAP.md` §"Phase 3" — Goal + three success criteria

### Phase 1 + 2 Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, wizard preview-then-confirm, idempotency philosophy, MC-01 dual-amount columns in the schema (the wiring Phase 3 now uses)
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-CONTEXT.md` — Wave 0 anonymisation pattern, two-format adapter wiring, HeaderSniffer extension, `SourceAdapterRegistry` pattern (D-33 follows this)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-05-SUMMARY.md` — Import pipeline + idempotency (v2 originally; superseded by v3 in Phase 2)
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-05-SUMMARY.md` — Cross-format ENRICHED dedup; the rank function that the ICS adapter does NOT participate in (Phase 3 has no second ICS format)

### Research
- `.planning/research/STACK.md` — `league/csv` (CSV reader, BOM-safe streaming), `brick/money` (currency arithmetic; non-EUR support), `phpoffice/phpspreadsheet` (rejected for Phase 3 per D-31)
- `.planning/research/PITFALLS.md` — Multi-currency pitfalls; the "losing FX info that can't be reconstructed" warning is the load-bearing reason for D-39 / D-40

### Existing Source (read before extending)
- `Modules/Ingestion/Public/Contracts/SourceAdapter.php` — The contract the new `IcsCsvAdapter` implements
- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — Extend per D-42 (add nullable `settledAmountMinor` / `settledCurrency` / `fxRateUsed`)
- `Modules/Ingestion/Public/Services/HeaderSniffer.php` — Add ICS CSV signature
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` — Register `'ics-csv' => IcsCsvAdapter::class`
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` — Reference adapter (mirror its lazy-Generator style, BOM-safe input, exception handling)
- `Modules/Ledger/Public/Dto/CanonicalTransaction.php` — Settled pair already on the DTO; no canonical shape change
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` — `settled_amount_minor` (NOT NULL), `settled_currency` (NOT NULL char(3)), `fx_rate_used` (nullable decimal(18,8)) — schema already supports Phase 3
- `Modules/Ledger/Public/Services/TransactionListQuery.php` — Already accepts `?string $currency`; the Blade view just needs to bind a toggle to it (D-47)
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — Needs a GROUP-BY-currency variant for D-46's original-currency dashboard view
- `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php` — Where the `settled = native` substitution lands (D-42) for adapters that don't supply settled info
- `Modules/Import/Internal/Http/Livewire/UploadWizard.php` — Refactor from flat dropdown to two-step issuer→format picker (D-33). Validator extends `'in:asn-csv,asn-camt053,asn-mt940'` to include `'ics-csv'`
- `Modules/Core/Models/User.php` — Add `default_currency_view` attribute (string, default `'eur_only'`) for D-44/D-45
- `Modules/Core/Internal/Http/Livewire/` — Where the new `SettingsPage` Livewire component lands (D-45)

### External Documentation
- `league/csv` 9.x docs (https://csv.thephpleague.com/9.0/) — Streaming reader, BOM stripping, header offset
- `brick/money` README (https://github.com/brick/money) — Multi-currency `Money` value object, currency arithmetic, formatter
- Flux UI segmented control / switch component docs (https://fluxui.dev/) — For the per-page toggle (D-44)
- (Wave 0 captures any ICS-specific format docs once the real fixture arrives)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`SourceAdapter` contract** — `IcsCsvAdapter` implements `format(): string` + `parse(...): Generator<int, SourceTransactionDto>`, same shape as `AsnCsvAdapter`. Lazy generator pattern matters for users with multi-year ICS history.
- **`SourceAdapterRegistry`** — Add `'ics-csv' => IcsCsvAdapter::class` in `IngestionServiceProvider::register()`. No new public API.
- **`HeaderSniffer`** — Extend with ICS CSV signature (Wave 0 nails the column-header substring). UTF-8 BOM stripping logic already in place.
- **`SourceTransactionDto`** — Gets three new nullable fields per D-42. Phase 1/2 adapters need no code change since the new fields default to `null`. Spatie/laravel-data handles the nullable-prop ergonomics.
- **`CanonicalTransaction`** — No shape change. `settledAmountMinor` / `settledCurrency` are already on the DTO and are populated by NormalizeStage.
- **`NormalizeStage`** — Adds the `if ($source->settledAmountMinor === null) { ...mirror native, fx_rate_used = null }` branch (D-42 substitution). Single behavioural change, well-localised.
- **`TransactionListQuery`** — `?string $currency` parameter already projects either the native or settled pair. The toggle (D-47) just binds to it. No query rewrite.
- **`ThisPeriodAtAGlanceQuery`** — Needs a `groupByCurrency(): bool` mode for D-46's original-currency dashboard view. Currently sums settled-EUR only; that path stays as the EUR-only mode.
- **`UploadWizard`** — Format dropdown refactor (D-33) is the visible UI change. Same preview-then-confirm flow; same ENRICHED state code path (which does NOT fire in Phase 3 because ICS ships only one format).
- **Wizard "unknown account → name it" step** — Already exists for IBAN. Generalise the trigger from "IBAN not found" to "no Account of the appropriate type exists yet" (D-38).
- **`statement_summaries` table** (Phase 2) — ICS CSV may carry statement-level metadata (period, opening/closing balance). Wave 0 reports availability; if present, the adapter writes a row keyed off `import_run_id`. Same shape as CAMT/MT940 — no schema change.

### Established Patterns
- **DI-only** — no `auth()` / `Auth::user()` / global helpers / facades. The Larastan boundary rule + custom DI-enforcement rule (Phase 1) catch violations. The new `SettingsPage` Livewire component must inject `CurrentUser` and `DatabaseManager`, never `auth()`.
- **`Public/` vs `Internal/`** — `IcsCsvAdapter` lives under `Modules/Ingestion/Internal/Adapters/Ics/`. New DTOs / contracts that the Import module touches stay under `Public/`.
- **Lazy generators in adapters** — `league/csv`'s streaming reader yields one row at a time; the adapter wraps each yield in a `SourceTransactionDto`.
- **Integer-cent arithmetic** — every amount stays on integer minor units. `brick/money` for any cross-currency or display arithmetic (Phase 3 finally exercises non-EUR `brick/money` calls). The `NoFloatMoneyArchTest` allow-list stays untouched.
- **Pest test layout** — adapter tests live next to the adapter (`Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php`). Snapshot tests via `spatie/pest-plugin-snapshots` for the canonical-DTO stream from a known sample.
- **Idempotency in the UI** — preview wizard surfaces NEW / DUPLICATE / ERROR per row. ENRICHED (Phase 2's fourth state) is unreachable in Phase 3 (ICS ships one format), but the code path stays in place for later phases.

### Integration Points
- **Schema** — Zero new migrations strictly required. (Optional: a migration adding `default_currency_view` to `users` and `period_start_day` if it isn't already on the user table; planner chooses whether `users.default_currency_view` lives on `users` or in a separate `user_preferences` table — D-19 already opened that door.)
- **Composer dependencies** — Zero new direct dependencies. `league/csv` and `brick/money` are already installed.
- **`ext-imap` invariant (PLT-05)** — Untouched.
- **No queue infrastructure yet** — Phase 3 stays synchronous like Phases 1 and 2. ICS CSVs are statement-sized (one month at a time, hundreds of rows). Async ingestion arrives in Phase 6.

### Risks Phase 3 Specifically Owns
- **No real ICS fixture yet.** Wave 0 must land the anonymised fixture before adapter code is written. If the user's CSV reveals a structure that contradicts D-34 / D-35 / D-40, the adapter plan revises accordingly (and CONTEXT.md gets a follow-up entry).
- **Two-step wizard picker is a UI refactor** — the Phase 1/2 single-dropdown surface is reshaped. Snapshot tests of the wizard need re-baselining. The planner explicitly carves a task for "regenerate wizard snapshots".
- **`ThisPeriodAtAGlanceQuery` group-by-currency mode is new work** — the current query has only one summation path. Adding a GROUP BY without breaking the existing EUR-only path needs an unit test for both modes.
- **`default_currency_view` user preference must round-trip through Settings page → DB → query → URL override** — four boundaries; planner adds a Pest feature test that exercises the full path.

</code_context>

<specifics>
## Specific Ideas

- **Fixture anonymisation is OUR responsibility, not the user's.** The user hands over raw ICS CSV (which may include card numbers, cardholder name, real merchants/amounts). Wave 0's first task is to redact card numbers (last-4 OK if needed for visual realism, full PAN never), strip the cardholder name, randomise any cross-referenceable identifiers — but preserve dates, amounts, currencies, and merchant strings verbatim so the adapter is exercised against truth. This is the exact same posture Phase 2 took with the CAMT IBAN check-digit re-anonymisation (02-03's "Major Deviation").
- **`fx_rate_used` is the effective post-markup rate.** Honest representation of what the user paid. The "market rate vs markup" split is rejected for v1 — the project is a personal finance tool, not an FX-cost auditor. Phase 9 drift detection can still spot a rising effective rate over time and flag it.
- **Dashboard tiles in original-currency mode collapse cleanly for EUR-only months.** A month with no foreign-currency transactions in original mode renders identically to EUR-only mode (single row). This is a feature: the toggle never adds visual clutter when there's nothing to disclose.
- **The Settings page is the long-deferred Phase 1 D-19 question now answered.** `period_start_day` moves from install-only into the page. Future phases can grow the page (e.g., Phase 6 OAuth credential management surface) without re-debating its existence.
- **Single ICS Account is a deliberate v1 choice.** The user has one card today. Multi-card support is a real future requirement but it depends on a fingerprint-tuple change (D-43's note → see deferred ideas). Don't ship that until card 2 actually arrives.

</specifics>

<deferred>
## Deferred Ideas

- **ICS Excel (.xlsx) ingestion** — explicitly rejected for Phase 3 per D-31. Revisit only if the user's ICS portal drops the CSV export. Would add `phpoffice/phpspreadsheet`.
- **Per-card visibility within one ICS contract** — Phase 3 ships single Account, no `card_last4` column. A future phase must (a) add `card_last4` (or `card_id`) to the transaction or to a `cards` subtable, (b) extend the v3 fingerprint tuple to include card identity (= v4 bump with re-derivation, like Phase 2 did v2→v3), (c) update the dashboard to optionally group totals by card. **Landmine:** importing a second card under the current single-Account model BEFORE this work would cause two cards posting the same merchant+date+amount to falsely de-duplicate via the v3 fingerprint. Plan this before card 2 is added to ICS.
- **Splitting ICS FX markup as a separate fee row** — Phase 3 rolls markup into the same canonical row (D-40). If Phase 9 drift detection or a future "where is my money going" view wants to surface FX cost explicitly, revisit. The data is preserved in `rawPayload` so retro-extraction is possible without re-import.
- **Market-rate-vs-effective-rate split (`fx_market_rate` + `fx_markup_basis_points` columns)** — explicitly rejected for v1. Adds two columns we'd populate from external rate APIs (privacy hit) for marginal user value.
- **PayPal Reporting API + USD-funded PayPal** — Phase 4, not Phase 3.
- **Chain-resolution use of ICS source_ref** — if Wave 0 confirms an ICS transaction-ID column, Phase 5 can lean on it for PayPal → ICS chain joins. Captured here so the Phase 5 researcher knows to check.
- **OFX / QIF export of multi-currency rows** — out of v1 scope entirely (REQUIREMENTS.md → v2 Deferred).
- **Per-currency budgets / spending limits** — out of scope; this is a visibility tool, not envelope budgeting.
- **Settings UI for `period_start_day`** — landing in Phase 3 (D-45) as a co-discharge of Phase 1 D-19's deferred decision. Future Settings extensions (notifications, OAuth credentials, etc.) build on the same page.

</deferred>

---

*Phase: 3-ICS Cards + Multi-Currency Display*
*Context gathered: 2026-05-13*
