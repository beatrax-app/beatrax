---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: planning
stopped_at: Phase 9 context gathered
last_updated: "2026-05-17T17:18:55.514Z"
last_activity: 2026-05-17
progress:
  total_phases: 11
  completed_phases: 8
  total_plans: 50
  completed_plans: 50
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-12)

**Core value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.
**Current focus:** Phase 08 — recurring-detection-fixed-payments-view

## Current Position

Phase: 9
Plan: Not started
Status: Ready to plan
Last activity: 2026-05-17

Progress: [██████████] 100%

## Performance Metrics

**Velocity:**

- Total plans completed: 43
- Average duration: ~16.8m (Phase 2 plans)
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 02 | 5 | - | - |
| 03 | 7 | - | - |
| 04 | 5 | - | - |
| 06 | 9 | - | - |
| 08 | 5 | - | - |

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
| Phase 03 P06 | 6 | 3 tasks | 8 files |
| Phase 03 P07 | 4 | 1 tasks | 5 files |
| Phase 04 P01 | 35min | 3 tasks | 11 files |
| Phase 04 P02 | ~50min | 3 tasks | 12 files |
| Phase 04 P03 | ~18min | 3 tasks | 21 files |
| Phase 04 P04 | ~7min | 2 tasks | 5 files |
| Phase 04 P05 | ~2min | 1 tasks | 3 files |
| Phase 05 P01 | 18min | 2 tasks | 13 files |
| Phase 05 P01b | 14min | 3 tasks tasks | 33 files files |
| Phase 05 P02 | 30min | 2 tasks tasks | 20 files files |
| Phase 05 P03 | ~26min | 2 tasks | 10 files |
| Phase 05 P04 | ~35min | - tasks | - files |
| Phase 05 P05 | ~3min | 1 tasks | 6 files |
| Phase 5 P05b | 13min | 2 tasks | 12 files |

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
- [Phase ?]: [Phase 03]: Plan 06: PerCurrencyTile DTO + ThisPeriodAtAGlanceQuery::forByCurrency() ADDED ALONGSIDE existing for(); HAVING filters zero-activity, ORDER BY alphabetical; Spatie Data DTO with readonly currency + inflow/outflow/net Money triple
- [Phase ?]: [Phase 03]: Plan 06: Money::format() widened to format(?string $locale = null) with EUR -> nl_NL / else -> en_US default. Every Phase 1/2 explicit-locale call site preserved; only new call sites benefit. Snapshots on ICU 77.1: EUR € 68,86 (NBSP), USD $74.43, negative -$74.43
- [Phase ?]: [Phase 03]: Plan 06: Dashboard.render() always computes $summary (top-spending + recent-transactions stay settled-EUR in both modes); only KPI tile section branches via @if ($tiles === null). Same card chrome reused verbatim; $fmt closure routes EUR nl_NL, else en_US (mirrors 03-05)
- [Phase 03-07]: TransactionDetail Livewire SFC via class-as-handler route: Route::get('/transactions/{transactionId}', TransactionDetail::class)->whereNumber()->name('transactions.show'). Page envelope via View::macro('extends', 'layouts.app') inside render() — no separate Blade wrapper file. mount() uses raw Query Builder exists() to clear PHPStan staticMethod.dynamicCall (same pattern as PreviewWizard::needsIcsAccountName in 03-04); render() uses Eloquent firstOrFail() for the typed-model read
- [Phase 03-07]: Cross-user 404 test added beyond the 4 plan-scaffolded cases — creates a second User row and assertStatus(404) against the first user's transaction URL. UserIdColumnArchTest covers the schema invariant; this test covers the runtime invariant on the new detail-page surface
- [Phase ?]: Phase 04 Plan 01: PayPal Activity Download CSV ships in NL locale; PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES['nl'] locks the 7-token discriminator (Datum, Tijd, Tijdzone, Omschrijving, Valuta, Transactiereferentie, Reference Txn ID)
- [Phase ?]: Phase 04 Plan 01: PaypalCsvEventTypeMap['nl'] locks 5 empirical event types + 4 EN forward-compatible 'skip' entries (Hold/Authorization/Reserve/Reversal of General Account Hold); no NL skip forms observed in this fixture
- [Phase ?]: Phase 04 Plan 01: TransactionImported event final readonly class (Transaction + User payload, NO ShouldHandleEventsAfterCommit / NO ShouldQueue); RecordTransactions dispatches sync in the outer DB transaction so Wave 2 listener sees just-inserted partners (Pitfall 1). RecordsTransactions contract widens to accept User $user
- [Phase ?]: Phase 04 Plan 01: Merchant Naam column PRESERVED verbatim in redacted PayPal fixture (deviates from plan's literal 'names → KAARTHOUDER'). In PayPal's NL Activity Download Naam is the COUNTERPARTY merchant name, not the cardholder. Per D-58 'merchant strings preserved verbatim'
- [Phase ?]: Phase 04 Plan 01: Empirical FX (D-60 e) is 4-row chain per USD purchase — USD parent + EUR Bankstorting + EUR Algemene valutaomrekening + USD Algemene valutaomrekening sharing parent Transaction ID via Reference Txn ID. Walker MUST detect FX by Currency != EUR on a row whose sibling is EUR Algemene-valutaomrekening (Pitfall 2)
- [Phase ?]: Phase 04 Plan 01: Empirical reconciliation (D-60 g) CLEAN — sum(Netto) = 0.00 in both EUR and USD across 86 rows. Pull-only funding model; PayPal balance never accumulates. No explicit opening/closing balance rows; adapter computes opening = closing − sum(net)
- [Phase 04]: Plan 02: PayPal Activity Download amount cells are NL-locale (comma decimal), not US-locale period decimal — empirically verified vs Wave 0 fixture. PaypalAmountParser locks the comma-decimal regex and explicitly rejects period decimal as a loud-failure signal that a future EN-locale account will need a second parser-arm rather than silent acceptance.
- [Phase 04]: Plan 02: PaypalTransactionRollup parent-vs-child classification keys on EVENT-TYPE action (`parent` vs `child-fee` vs `child-fx`), NOT solely on Reference Txn ID. Parent-classified rows with orphan billing-agreement refs stay parents (no orphan-child bump); child-classified rows with absent parents are promoted to standalone parents AND increment `orphanChildCount`. Preserves the empirical 41-logical-payment-group count in the Wave 0 fixture.
- [Phase 04]: Plan 02: Pitfall 2 safety net (FX-direction blindness) — rollup walker scans child-fx siblings and identifies the foreign leg by `Currency != 'EUR'`, NEVER by row order. The Cloudflare USD chain (parent appears BELOW its children in the empirical CSV-time order) is the load-bearing test case proving the walker is row-order-blind.
- [Phase 04]: Plan 02: PaypalCsvAdapter constructor composed over PaypalTransactionRollup only (which already DIs parsers + column map + event-type map). Removed redundant parser injection points; single-responsibility delegation.
- [Phase 04]: Plan 02: TestCase::seedFixtureUserAndAccount now also seeds a PayPal Account (synthetic IBAN `PAYPAL`, kind `paypal`, EUR). Cross-module IdempotencyContractTest + per-module *ImportTest files now resolve the synthetic IBAN without per-test setup boilerplate.
- [Phase 04]: Plan 02: PreviewWizard hosts a method-triad per non-IBAN issuer (save{X}AccountName + needs{X}AccountName + {X}_OWN_IBAN constant + Blade @elseif branch). Three branches in place — IBAN naming, ICS-card naming, PayPal naming — each a verbatim mirror swapping only synthetic-IBAN + kind + slug-suffix + Blade copy.
- [Phase 04]: Plan 02: Reconciliation soft-warning panel queries the preview-time-written `statement_summaries.extras` row via DatabaseManager + json_decode at render. Informational only — does NOT block Confirm. Same posture as Phase 2 multi-statement MT940 flag.
- [Phase 04]: Plan 03: `pair_transaction_id` self-FK on transactions ships with ON DELETE SET NULL + partial index `transactions_unpaired_transfer_idx` over (user_id, account_id, booked_at) WHERE pair_transaction_id IS NULL AND type IN ('transfer_out', 'transfer_in'). NOT in the v3 fingerprint tuple — no version bump or re-derive. Migration mirrors `add_raw_payload_to_transactions.php` anonymous-class shape verbatim.
- [Phase 04]: Plan 03: ClassifyTransactionType pipeline stage decouples typing from pair detection (D-76 / Pitfall 3). Sits between NormalizeStage and FingerprintStage. 5-step algorithm: preserve refund/fee/adjustment → cross-account-IBAN flip → PayPal event-type map → subtractive income detector (D-77) → keep NormalizeStage's amount-sign default. The stage NEVER queries the transactions table — grep gate enforces this in ClassifyTransactionTypeTest.
- [Phase 04]: Plan 03: ClassifyTransactionType uses raw `DatabaseManager::connection()->table('accounts')->count() > 0` for the cross-account-IBAN predicate (matches PreviewWizard::needsIcsAccountName / TopCategoriesByPeriodQuery pattern) — Larastan strict-rules `staticMethod.dynamicCall` forbids `Eloquent::query()->exists()`. PaypalCsvEventTypeMap is the only Public/ surface the stage imports beyond models.
- [Phase 04]: Plan 03: PaypalTransactionRollup now stamps `language` onto every emitted rawPayload (alongside `format` and `events`). Single-line change in `buildDto()`. ClassifyTransactionType's step 3 reads it to look up parent event types via `PaypalCsvEventTypeMap::transactionType()`. Threading language via a pipeline parameter would have leaked HeaderSniffer knowledge into Import.
- [Phase 04]: Plan 03: New bounded module `Modules/Transfers/` with EMPTY Public/ surface (D-80). Composer manifest, TransfersServiceProvider, Internal/Listeners/PairTransferCandidates. No migrations, no routes, no views, no Public/ surface. Phase 5's chain resolver is the projected first consumer — promote to Public when it arrives.
- [Phase 04]: Plan 03: PairTransferCandidates listener is synchronous + in-tx — no `ShouldHandleEventsAfterCommit`, no `ShouldQueue`, no nested `DB::transaction()` wrapper. Inherits the outer RecordTransactions transaction frame so same-import-batch partner rows pair atomically. WINDOW_DAYS = 3 (D-73 tolerance) as a private constant for greppability.
- [Phase 04]: Plan 03: Listener uses raw DatabaseManager whereBetween/whereIn/whereNull/orderBy chain for the partner lookup (strict-rules-clean), then loads the partner via `Transaction::query()->where('user_id', $user->id)->where('id', $partnerId)->firstOrFail()` for the symmetric save(). Two-step hybrid: search via raw query builder; write via Eloquent (preserves timestamps + BEFORE-UPDATE type trigger).
- [Phase 04]: Plan 03: Listener defensively asserts `$event->transaction->user_id === $event->user->id` and throws RuntimeException on mismatch (T-04-W2-02). Same-user invariant is also enforced via `->where('user_id', $user->id)` on every Account + Transaction query. Cross-user feature test exercises both layers.
- [Phase 04]: Plan 03: Phase 4 SC#3 validated at the listener-contract level (synthetic-fixture Pest test), not via back-to-back ASN CAMT.053 + ICS PDF import — the Phase 2 + Phase 3 redacted fixtures don't share a synthesised iDEAL settlement counterparty-IBAN pair. Real-data overlap deferred until the user uploads matching exports; until then the SC#3 contract is proven at the listener level which is the exact same code path the production pipeline traverses.
- [Phase 04]: Plan 03: New-module test discovery requires THREE coordinated changes — phpunit.xml testsuite entries + composer.json autoload-dev psr-4 entry + tests/Pest.php per-module wire-up map row. Per-module Pest.php is documented inert. Pattern locked in tests/Pest.php's foreach loop; future modules add one row each.
- [Phase 04]: Plan 03: Pre-existing TransactionTypeTest::it-rejects-an-invalid-transaction-type failure logged to `deferred-items.md` for the verifier. Reproducible on `b57c0dd` before any Wave 2 change; trigger fires correctly outside the Pest harness (direct `php -r` verification). Environment-shaped (Pest parallel-mode SQLite trigger handling on this machine). Out of scope per Wave 2's deviation rules.
- [Phase 04]: Plan 04: TransactionDetail Reclassify action lives at `Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php::reclassify()`. Captures `$tx->pair_transaction_id` BEFORE save() so the partner-id is available after nullification; wraps the row save() + partner update() in `$db->connection()->transaction()` so both writes commit or roll back together. Transfer-to-transfer reclassifies skip the unpair branch entirely so the listener's pair survives a no-op type swap.
- [Phase 04]: Plan 04: ThisPeriodAtAGlanceQuery::for() AND ::forByCurrency() now filter rollups by `transactions.type` instead of by amount sign. Income tile = SUM WHERE type='income'; expense tile = SUM WHERE type='expense'; net = SUM WHERE type IN ('income','expense'). Refunds / transfers / fees / adjustments stay out of both tiles per D-77. forByCurrency HAVING clause gets the same type-filter so original-currency mode never silently double-counts transfers in any currency band.
- [Phase 04]: Plan 04: Toast dispatch shape is `$this->dispatch('toast', message: $message)` — named-parameter form. No global toast-renderer exists; Blade view embeds inline `<span x-show="toast" x-text="toast">` next to Save button. Alpine listens via `x-on:toast.window` (Livewire 4 broadcasts component-dispatched events as window events). Phase 5's review-queue UX inherits this shape.
- [Phase 04]: Plan 04: Cross-user safety test on the action layer asserts `Exception::class` instead of `NotFoundHttpException` because the Livewire 4 test harness wraps mount() throwables at snapshot-serialization time. The canonical user-facing 404 invariant is asserted via the HTTP route (`$this->get(route('transactions.show', $tx->id))->assertStatus(404)`); the Livewire layer is defence-in-depth (asserts SOME exception fires AND the row stays untouched).
- [Phase 04]: Plan 05: ROADMAP Phase 4 SC #2 rewritten as deferred-with-trigger; REQUIREMENTS ING-09 entry rewritten + new 'Deferred / Future-Revisit (Phase 4 close-out)' section added between v2 Requirements and Out of Scope; traceability ING-09 row flipped Pending -> Deferred.
- [Phase 04]: Plan 05: BoundaryArchTest::noPaypalApiRoute appended via 'it(...)' Pest syntax with RecursiveIteratorIterator file-walk + comment-strip regex (mirrors UserIdColumnArchTest pattern). Scope routes/ + Modules/ only; .planning/ and tests/ excluded to avoid self-referential failure. Failure-mode proved via temporary sentinel under Modules/Ingestion/Internal/Adapters/Paypal/, never committed.
- [Phase 04]: Plan 05: Phase 4 close-out gate met — SC #1 GREEN (04-02), SC #2 DEFERRED-WITH-TRIGGER (04-05), SC #3 GREEN (04-03 + 04-04), SC #4 GREEN (04-04). ING-09 Reporting API revisit conditional on PayPal Business upgrade; arch test must be retracted in that future plan.
- [Phase ?]: [Phase 05]: Plan 01: laravel/horizon ^5.46 + predis/predis ^3.4 installed; container-resolved Gate contract inside HorizonServiceProvider::gate() avoids the larastan-strict noFacadeRule trip
- [Phase ?]: [Phase 05]: Plan 01: Stack-policy flip lives in .planning/research/STACK.md (canonical) + a pointer in .planning/PROJECT.md; Horizon removed from 'What NOT to Use' and reworded as 'Yes — recommended'; Sail/Docker row keeps its trap warning with an explicit Redis-only carve-out
- [Phase ?]: [Phase 05]: Plan 01: GSD codes (D-101, FND-01, etc.) stripped from runtime code/config comments; rationale described in plain technical language so the codebase stays agnostic from the planning system
- [Phase ?]: PDF font encoding: Type1 Helvetica with explicit /Encoding /WinAnsiEncoding directive so pdftotext renders € (required by IcsPdfAdapter's parseFourColumnSummary regex)
- [Phase ?]: Issue #9 fix lock: explicit skip predicate pattern for tests with external-service preconditions; CI grep gate prevents regression to swallow-on-throw alternative
- [Phase ?]: Public-surface promotion convention: Modules/<Name>/Public/Services/<Name>Lookup singleton-bound in <Name>ServiceProvider::register() — first applied to PairLookup
- [Phase ?]: Phase 05 Plan 02: chain_links.to_transaction_id conditional-NULL invariant enforced at schema layer via BEFORE INSERT/UPDATE trigger pair using SQLite JSON1 json_extract — replaces the resolver-side arbitrary first-expense workaround the original RESEARCH proposed
- [Phase ?]: Phase 05 Plan 02: card_statements back-population sign convention locked at write site — total_amount_minor preserves negative closing_balance_minor from statement_summaries verbatim; open_balance_minor is the absolute value. Test asserts both columns against fixture (-84732 / 84732)
- [Phase ?]: Phase 05 Plan 02: CardStatementStateMachine wraps read-then-write in transaction() with PRAGMA busy_timeout=5000; lockForUpdate is a SQLite no-op so the pragma is the load-bearing concurrency fence. Cross-user statement miss raises RuntimeException so partial writes never leak through
- [Phase ?]: Phase 05 Plan 02: chain_resolution_runs.user_id non-nullable (mirrors import_runs) — BelongsToUser still applies but the safer non-nullable shape eliminates NULL-distinct-in-UNIQUE bugs at the schema layer
- [Phase ?]: Phase 05 Plan 02: ChainLink.confidence intentionally left without explicit cast — SQLite decimal columns return numeric strings; resolver code converts via (float) at boundary so strict-rules cast.string stays satisfied (mirrors Phase 3 TransactionRowDto)
- [Phase ?]: Phase 05 Plan 02: Test invocation pattern for forward-only data migrations — require() the anonymous-class file and call up() directly. Artisan::call('migrate') is a no-op once the migration is recorded in the migrations table
- [Phase ?]: Phase 5 Wave 2: DispatchesChainResolution Public contract replaces direct Bus::dispatch in ConfirmImport (cross-module BoundaryRule)
- [Phase ?]: Phase 5 Wave 2: BusChainResolutionDispatcher routes via Dispatchable::dispatch() static helper to keep ShouldBeUniqueUntilProcessing lock fired
- [Phase ?]: Phase 5 Wave 2: Dispatcher::listen(JobFailed::class) replaces Queue::failing facade in ChainsServiceProvider
- [Phase ?]: Phase 5 Wave 2: IcsSettlementResolver matcher drops amount-tolerance filter; tolerance arm decides confirmed-vs-candidate inside resolveOne()
- [Phase ?]: Phase 5 Wave 2: tests/TestCase setUp routes cache.stores.redis to array driver for ShouldBeUnique uniqueVia() in no-Redis tests
- [Phase ?]: Phase 5 Wave 3 (Plan 05-04): PaypalFundingResolver real algorithm — deterministic D-106 arm reads raw_payload via raw DatabaseManager query-builder (avoids BoundaryArchTest exemption); fuzzy CHN-02 weights 0.5/0.3/0.2 with FUZZY_MAX_CONFIDENCE=0.99 so 1.0 stays exclusive to deterministic; signature_hash = sha256(normalized_merchant + '|' + funding_iban) at both arms
- [Phase ?]: Phase 5 Wave 3: ConfirmChainLink learning loop (D-87/D-88) wraps target row promotion + same-signature auto-promotion sweep in a single db transaction so a partial promotion never renders; resolver value preserved as 'auto' for user-confirmed rows, set to 'rule' only on auto-promoted siblings, distinguishing UI chip tiers per D-91
- [Phase ?]: Phase 5 Wave 3: ChainLinkQuery uses explicit BFS frontier + visited-set + depth counter (MAX_DEPTH=5) — to_transaction_id=NULL legs are skipped (issue #10 — exceeded-tolerance ICS bulk-settle candidates surface via candidatesForReview not the walker); D-91 confidence-tier mapping locked in confidenceTier() (Deterministic / Confirmed / Candidate); whereJsonContains works on the dev SQLite build, JSON1 fallback unused
- [Phase ?]: Phase 5 Wave 3: Action-layer 404 surface — explicit throw new NotFoundHttpException after where()->first() returns null, instead of firstOrFail(). Same HTTP 404 behavior + testable from action-level Pest tests (the ModelNotFoundException→NotFoundHttpException conversion happens at the HTTP kernel, invisible to unit tests). Applied in both ConfirmChainLink + RejectChainLink + ChainLinkQuery::forTransaction
- [Phase ?]: Chain drawer trigger button dispatches both chain-drawer:open (Livewire) AND modal-show (Flux) in the same user gesture — keeps the data layer and presentation layer independently testable
- [Phase ?]: Single-link ICS bulk-settle is NOT a fan-out — a node renders the fan-out container only when it has ≥2 outgoing ics_bulk_settle chain_links
- [Phase ?]: Blade partials with required context use explicit @props([...]) declarations + explicit @include arrays at every call site — issue #13 fix
- [Phase ?]: Top-nav badge fed by View Factory composer on core::livewire.top-nav via $this->app->make(ViewFactoryContract::class)->composer(...) — never the view() global helper (issue #12 fix)
- [Phase ?]: Wizard polling + dashboard failed-job toast read chain_resolution_runs WHERE user_id=? (exact match) — never failed_jobs.payload LIKE substring (issue #1 + #8 fix)
- [Phase ?]: ChainReviewQueue uses view-extends('layouts.app') — same Livewire SupportPageComponents pattern as TransactionDetail; allows Route::get class-as-handler wiring without a separate Blade wrapper

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

Last session: 2026-05-17T17:18:55.509Z
Stopped at: Phase 9 context gathered
Resume file: 
.planning/phases/09-subscription-drift-detection-alerts/09-CONTEXT.md
