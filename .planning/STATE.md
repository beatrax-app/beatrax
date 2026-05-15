---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Completed 03-05-PLAN.md (TransactionsList currency-view toggle + dual-line FX render)
last_updated: "2026-05-15T17:51:42.353Z"
last_activity: 2026-05-15
progress:
  total_phases: 11
  completed_phases: 2
  total_plans: 19
  completed_plans: 17
  percent: 89
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-12)

**Core value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.
**Current focus:** Phase 03 — ics-cards-multi-currency-display

## Current Position

Phase: 03 (ics-cards-multi-currency-display) — EXECUTING
Plan: 6 of 7
Status: Ready to execute
Last activity: 2026-05-15

Progress: [█████████░] 89%

## Performance Metrics

**Velocity:**

- Total plans completed: 17
- Average duration: ~16.8m (Phase 2 plans)
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 02 | 5 | - | - |

**Recent Trend:**

- 02-05 (Wave 3 ENRICHED state + cross-format dedup) — ~14 minutes, 3 tasks, 6 files created + 12 files modified (FingerprintStage::classify + ApplyEnrichments action + ConfirmImport rewrite + Blade four-state UI + cross-format dedup tests)
- 02-04 (Wave 2 MT940 vertical slice) — ~15 minutes, 4 tasks, 16 files created + 8 files modified (the hand-rolled MT940 toolchain: lexer + Tag61 + Tag86 + counterparty cleaner + adapter + DTOs + tests + snapshot)
- 02-03 (Wave 2 CAMT.053 vertical slice) — ~28 minutes, 3 tasks, 14 files created + 40 files modified (large modification count driven by the IBAN check-digit fixture refresh — a single-purpose deviation, see 02-03-SUMMARY.md "Major Deviation")
- 02-02 (Wave 1 fingerprint v3 foundation) — ~13 minutes, 3 tasks, 15 files created + 6 files modified
- 02-01 (Wave 0 enablement) — ~14 minutes, 3 tasks, 7 fixture files created + 3 config files modified
- Trend: —

*Updated after each plan completion*

| Phase 02 P03 | 28 | 3 tasks | 14 files |
| Phase 02 P04 | 15 | 4 tasks | 16 files |
| Phase 02 P05 | 14 | 3 tasks | 6 files |
| Phase 03 P01 | 18 | 7 tasks | 20 files |
| Phase 03 P02 | 27 | 6 tasks | 28 files |
| Phase 03 P03 | 11min | - tasks | - files |
| Phase 03 P04 | 6min | 3 tasks | 9 files |
| Phase 03 P05 | ~6m | 2 tasks tasks | 6 files files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Foundation: BIGINT minor units for money + `brick/money` value objects (FND-04, FND-07) — irreversible if skipped
- Foundation: nullable `user_id` + `BelongsToUser` trait on every domain table from Phase 1 (FND-03) — multi-user-ready schema is cheap now, painful later
- Foundation: idempotent imports enforced at DB layer via UNIQUE on `(account_id, fingerprint)` and `(source_id, external_id)` (ING-06) — must exist before second source lands
- Foundation: multi-currency dual-amount columns in schema from Phase 1 (MC-01) — losing FX info is irreversible
- Foundation: no `ext-imap` anywhere; pure-PHP IMAP driver from day one (PLT-05) — PHP 8.4 removed `ext-imap` from core
- Foundation: SQLite WAL mode + `synchronous=NORMAL` from app startup (FND-06)
- Architecture: vertical-MVP per phase; Phase 1 must produce a working "see my ASN month" experience before Phase 2 begins
- Architecture: `nwidart/laravel-modules` for bounded modules (Ingestion / Ledger / Categorization / Recurring / Chains / Forecasting / EmailScan)
- Quality gates: Larastan level 10 strict + Laravel Pint + Pest must pass in CI (no frontend tests required)
- Phase 2: pin `genkgo/camt` to `^2.10` (installed 2.10.3) — supports CAMT.053.001.02 / 001.03 / 001.08; downstream adapter must detect sub-version on `xmlns` URI
- Phase 2: empirical CAMT.053 from ASN is sub-version `001.02`, not the `001.08` the research doc anticipated — Wave 2 adapter must not assume the newest variant
- Phase 2: ASN MT940 fixture is synthesised from the anonymised CAMT corpus because ASN no longer ships an MT940 download channel; cross-format MT940 pair is absent and the affected `CrossFormatDedupTest` scenarios will be `->skip()`-ed in Wave 3
- [Phase 02]: Plan 2: FingerprintComposer bumped to v3 — tuple drops source_ref and widens with booked_at (second-resolution) so CSV/CAMT entries for the same logical transaction hash identically; same-day-same-merchant-same-amount entries no longer collide
- [Phase 02]: Plan 2: diederik:rederive-fingerprints artisan command at Modules/Ledger/Internal/Console/ — registered behind runningInConsole() and statically forbidden from any Http/Routes namespace by a pest-plugin-arch BoundaryArchTest rule + a phase-2-grouped mirror; discharges threat T-02-02-01 in two layers
- [Phase 02]: Plan 2: transactions.enriched_from JSON column cast as AsArrayObject for the append-only provenance trail; import_runs.enriched_count integer column for the wizard results summary; transactions composite UNIQUE recreated over the v3 tuple so DB-layer enforcement matches the SHA-256 hash
- [Phase 02]: Plan 3: Option B (ImportPipeline reads adapter state post-iteration) rather than Option A (ParseStage returns tuple). Adapters become stateful singletons holding the last `?StatementSummaryData`; acceptable in single-user sequential-request app, documented in 02-03-SUMMARY.md.
- [Phase 02]: Plan 3: Disable XSD validation in the CAMT.053 adapter via `Config::disableXsdValidation()`. Bundled XSDs reject minimal test fragments and any future ASN extension; XXE security is enforced by a custom `libxml_set_external_entity_loader` (allow-list local + no-scheme URIs, reject every remote scheme), structural correctness by genkgo/camt's IBAN validator + MoneyFactory.
- [Phase 02]: Plan 3: Re-anonymise IBAN check digits across every fixture (NL00 → valid mod-97 cc). Forced by genkgo/camt's eager IBAN validation — no library bypass. Only the 2-digit check segment changes per IBAN; bank code, account number, BIC, counterparty names, SEPA refs, amounts, dates all preserved verbatim. 25 fixture files + 14 test files rewritten in one pass.
- [Phase 02]: Plan 3: StatementSummary model at `Modules/Ledger/Models/` matching existing Ledger model convention (Account / Category / Currency / ImportRun / Transaction all live there). `Modules/Ledger/Public/Models/` split deferred to a separate refactor plan if desired across the codebase.
- [Phase 02]: Plan 4: Hand-rolled MT940 toolchain — Lexer + Tag61Parser + Tag86Parser + CounterpartyCleaner + Adapter — no kingsquare/php-mt940 or other library dependency. Each class is single-purpose, tested independently, then composed via constructor DI in AsnMt940Adapter. The pattern proves the codebase can carry a streaming line-based parser end-to-end without a stateful runtime.
- [Phase 02]: Plan 4: Balance amounts (`:60F:` / `:62F:`) routed through `AsnAmountParser` via a small `Mt940BalanceTuple` internal DTO rather than the float-coerced `(int) round((float) $cell * 100)` shortcut. Keeps the project-wide integer-only money invariant airtight; the NoFloatMoneyArchTest allow-list stays untouched.
- [Phase 02]: Plan 4: ASN MT940 customer-reference regex is locked to 34 chars (the ASN-extended variant). The SWIFT-standard 16-char form would silently truncate references and break sourceRef extraction; the project explicitly produces and consumes the ASN dialect.
- [Phase 02]: Plan 4: Multi-statement MT940 files capture only the FIRST statement's `:20:` / `:25:` / `:28C:` / `:60F:` / `:62F:` into `statement_summaries`. Subsequent statements still yield every `:61:`/`:86:` entry; the FIRST statement's `extras.multiStatement` flag is set to true so a later UI can surface the rest. Keeps the writer's unique `(user_id, import_run_id)` contract honest.
- [Phase 02]: Plan 5: FingerprintStage::classify returns FingerprintDisposition variants (NewRow / Duplicate / Enriched) instead of a bool. Source-format rank function: asn-camt053=4, asn-mt940=2, asn-csv=1, unknown=0; NULL ref scores 0; non-null > null is the load-bearing cross-format rank rule. The deprecated `isExistingFingerprint(): bool` survives as a thin wrapper for one-version transition.
- [Phase 02]: Plan 5: ApplyEnrichments wraps each PendingEnrichment in its OWN per-row DB transaction with lockForUpdate, while ConfirmImport wraps the recorder + applier in a single OUTER transaction. The two-level transaction shape keeps lock scopes minimal AND keeps confirm-level atomicity intact.
- [Phase 02]: Plan 5: `source_format` records the CREATING format; `enriched_from` carries the multi-format history. A Phase 5 chain-resolution query that needs "rows touched by format X" MUST join against `enriched_from` JSON, NOT `source_format`.
- [Phase 02]: Plan 5: CrossFormatDedupTest::camt053_then_csv deviated from the plan's strict 'enriched=0' assertion. The 72-row February pair contains 34 CAMT entries with NULL EndToEndId; non-null CSV Volgnummer legitimately enriches those rows under the rank function. Test now asserts the precise duplicates / enriched split matches the fixture's NULL-EndToEndId count.
- [Phase 03]: Plan 1: ICS PDF source uses revolving-credit summary nomenclature (Vorig openstaand saldo / Totaal ontvangen betalingen / Totaal nieuwe uitgaven / Nieuw openstaand saldo / Bestedingslimiet / Minimaal te betalen bedrag), NOT the current-account tokens CONTEXT.md D-51 anticipated; CONTEXT.md addendum required before 03-02.
- [Phase 03]: Plan 1: empirical PDF disposition map — D-34 source_ref unavailable (NULL, fingerprint-only dedup), D-35 FX shape (b) two-line block, D-37 source PDF never renders full PAN (only last-four), D-40 markup rolled into settled, D-53 no Pagina X van Y page footer (page index lives inline on summary header line).
- [Phase 03]: Plan 1: tiny synthetic PDF generated via hand-crafted 849-byte PDF 1.4 byte stream (scripts/generate_tiny_ics_pdf.php); cupsfilter exceeded the 10 KB budget by ~7 KB. Plan 03-02's IdempotencyContractTest dataset will reference this tiny PDF.
- [Phase 03]: Plan 1: anonymisation script (scripts/anonymize_ics_text.php) committed in-repo with zero Composer deps — Phase 1's anonymisation was throwaway under /tmp; from Phase 3 onwards the redaction tool ships alongside the redacted fixture so future re-runs are auditable.
- [Phase ?]: Phase 03 Plan 02: IcsPdfAdapter uses 'ICS-CARD' as instance-wide synthetic own-IBAN (not per-user 'ICS-CARD-{id}'); AccountResolver already user-scopes lookups so per-user uniqueness was redundant and would have required cross-module reach.
- [Phase ?]: Phase 03 Plan 02: PdfTextExtractor declared non-final so unit tests can substitute it via anonymous-class extension returning fixture text verbatim. Production wiring is unchanged (constructor DI through SourceAdapterRegistry); only the test-double substitution pattern depends on the relaxed declaration.
- [Phase ?]: Phase 03 Plan 02: transactions.raw_payload JSON column added (deferred from the Phase 1 schema). Required by D-49; the migration suite had not declared it. CanonicalTransaction extended with nullable rawPayload field; Transaction model casts as 'array'; NormalizeStage threads source->rawPayload through. Archive-only — Phase 3 queries never read it.
- [Phase ?]: Phase 03 Plan 02: tiny synthetic PDF regenerated (981 bytes) to embed a transaction row matching the empirical layout ('12 apr. 12 apr. SYNTHETIC ICS TINY 1,00 Af') + statement-header date '15 april 2026'. The original 03-01 tiny PDF used a non-empirical shape that the production adapter correctly rejected.
- [Phase ?]: Phase 03 Plan 02: Singleton-forget cascade for test-substituted PdfTextExtractor — forgetInstance(SourceAdapterRegistry) + IcsPdfAdapter + ImportPipeline + ParseStage before re-resolving RunsImports. Pattern documented in the FX-row test case so future contributors don't trip the singleton-stale-extractor gotcha.
- [Phase ?]: PreviewWizard ICS-naming bypasses NamesAccounts service: the synthetic IBAN 'ICS-CARD' fails AccountNamer's ISO 13616 structural guard, so the wizard validates name + slug inline and inserts the Account row directly with kind='ics_card'.
- [Phase ?]: Cascading picker leaf wire-format is the source of truth — Source select (issuer property) is UX-only; HeaderSniffer / SourceAdapterRegistry / pipelines dispatch on the leaf sourceFormat. Future format additions extend availableFormats() in PHP without Blade changes.
- [Phase ?]: Raw DatabaseManager::table()->count() used in PreviewWizard::needsIcsAccountName instead of Eloquent's exists()/count() to clear PHPStan strict-rules staticMethod.dynamicCall — matches the Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery convention.
- [Phase ?]: Phase 03 Plan 04: /settings route uses Route::view + page-level Blade wrapper + @livewire alias — matches the existing dashboard/wizard/triage pattern in this codebase; class-as-handler alternative rejected for consistency.
- [Phase ?]: Phase 03 Plan 04: SettingsPage messages() maps periodStartDay.required + .integer + .min + .max ALL to the single locked string 'Choose a day from 1 to 28.' so any boundary failure yields the same calm sentence — UI-SPEC error-copy locked.
- [Phase ?]: Phase 03 Plan 04: tests seed default_currency_view='eur_only' explicitly in beforeEach rather than depending on the migration default — starting state never depends on fixture-history mutation.
- [Phase ?]: Phase 03 Plan 05: TransactionsList #[Url(as: 'currency', except: '')] string property + mount(CurrentUser) fallback to default_currency_view ('eur_only' → 'eur', else 'original'). Defensive render() mapping: only 'eur' maps to query currency='EUR'; every other value (including unrecognised junk) maps to null — no Livewire-property string reaches the SQL filter.
- [Phase ?]: Phase 03 Plan 05: TransactionRowDto.secondaryAmount nullable Money — drives D-47 dual-line render. TransactionListQuery projects secondary_minor/secondary_currency only in original mode (null filter); mapRow filters out EUR-native rows via settled_currency != display_currency. EUR-only mode never SELECTs the secondary pair.
- [Phase ?]: Phase 03 Plan 05: Money formatter routing landed in the Blade fmt closure (EUR → nl_NL, else en_US) rather than as a new Money::format() default. Keeps Money value object a pure brick/money wrapper; promote to a Public helper only when a third consumer arrives.
- [Phase ?]: Phase 03 Plan 05: snapshot Money formatter output: '€ 68,86' (EUR nl_NL with non-breaking space), '$74.43' (USD en_US no space). Negative: '€ -12,07' / '-$12.99'. ext-intl present (intl 8.5.0alpha1, ICU 77.1); no fallback formatter needed. ISO suffix verbose form not implemented in 03-05 — deferred.
- [Phase ?]: Phase 03 Plan 05: TransactionListQuerySecondaryAmountTest lives under tests/Feature/ because Ledger module's Pest.php only adds RefreshDatabase to Feature; secondaryAmount tests need live SQLite to seed transactions.

### Pending Todos

[From .planning/todos/pending/ — ideas captured during sessions]

None yet.

### Blockers/Concerns

[Issues that affect future work]

Phase-research flags carried from research/SUMMARY.md (to address during plan-phase for each):

- Phase 1: ASN CSV exact column layout needs empirical validation against a real export
- Phase 2: ASN MT940 dialect quirks; CAMT.053 iDEAL settlement field layout
- Phase 3: ICS CSV/Excel exact column layout (no public documentation)
- Phase 4: PayPal event-type taxonomy and Reporting API feasibility for personal vs business accounts
- Phase 5: ICS bulk-settlement edge cases (refunds, carry-forward, partial payments) need real data
- Phase 6: Gmail / Microsoft Graph real-world rate-limit thresholds on backfill
- Phase 7: Google Play / PayPal receipt HTML structure changes over time (need recent samples)

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| *(none)* | | | |

## Session Continuity

Last session: 2026-05-15T17:51:42.349Z
Stopped at: Completed 03-05-PLAN.md (TransactionsList currency-view toggle + dual-line FX render)
Resume file: 
None
