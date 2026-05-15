---
phase: 04-paypal-ingestion-transfer-detection
plan: 02
subsystem: ingestion
tags: [paypal, csv, ingestion, adapter, rollup, wizard, mvp-slice]

# Dependency graph
requires:
  - phase: 04-paypal-ingestion-transfer-detection
    provides: Wave 0 deliverables — redacted CSV fixture, PaypalCsvLanguageProfile (nl signature), PaypalCsvEventTypeMap (5 NL entries + 4 EN forward-compat skips), typed exceptions, TransactionImported event, IdempotencyContractTest paypal-csv RED dataset row
  - phase: 02-asn-statement-coverage-camt-053-mt940
    provides: stateful-adapter `statementMetadata()` shape; SourceAdapterRegistry pattern; HeaderSniffer extension pattern; SniffResult DTO
  - phase: 03-ics-cards-multi-currency-display
    provides: two-step wizard issuer→format picker (D-33; PayPal becomes the third group); `SourceTransactionDto` nullable settled-pair fields (D-42); synthetic-IBAN account modeling (`'ICS-CARD'` precedent); `rawPayload` per-row archive contract (D-49); wizard-prompts-to-name-account pattern (D-38; PayPal mirrors verbatim with three-line copy swap)
provides:
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php — NL-locale comma-decimal integer-only amount parser
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php — M/D/YYYY US numeric date parser + YYYY-MM-DD ISO fallback
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php — language-keyed canonical-name → header-cell lookup
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php — three-pass Transaction-ID / Reference-Txn-ID rollup walker with Pitfall 2 safety net
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php — Generator-based SourceAdapter composition
  - SourceAdapterRegistry entry `'paypal-csv' => PaypalCsvAdapter::class`
  - HeaderSniffer::sniffPaypalCsv() arm (typed-exception UnsupportedPaypalCsvLanguageException surface for unrecognised locales)
  - SourceRefRanker::rank('paypal-csv') = 1 (same band as `asn-csv` per D-64)
  - UploadWizard extension: `paypal` joins the issuer cascade (`required|in:asn,ics,paypal`); `paypal-csv` joins SUPPORTED_FORMATS + the sourceFormat in: validator + availableFormats() + sanitiseFilename(). New `<option value="paypal">PayPal</option>` in the Blade. Header copy reflects three-issuer scope.
  - PreviewWizard extension: `paypalAccountName` property + `savePaypalAccountName()` action + `needsPaypalAccountName()` predicate (mirror of ICS-naming triad). Third naming branch in the Blade with locked copy ("Name your PayPal account."). Reconciliation soft-warning panel keyed on `statement_summaries.extras.reconciliationStatus === 'mismatch'`.
  - tests/TestCase::seedFixtureUserAndAccount also seeds a PayPal Account (synthetic IBAN `PAYPAL`, kind `paypal`) so the cross-module IdempotencyContractTest + per-module *ImportTest files can resolve the synthetic IBAN without falling through to the unknown-IBAN wizard step.
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalAmountParserTest.php (12 cases — parser + InvalidAmountException negatives)
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php (6 cases — M/D/YYYY + ISO fallback + invalid)
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php (8 cases — flat, FX fold, skip, orphan, sourceRef, rawPayload, monotonic index, full fixture)
  - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php (8 cases — format identifier, fixture parse, monotonic index, FX dual-amount, account resolution, statement metadata, reconciliation, registry)
  - Modules/Ingestion/tests/Feature/HeaderSnifferPaypalTest.php (4 cases — accept fixture, reject non-CSV, reject unknown language, reject unreadable file)
  - Modules/Import/tests/Feature/UploadWizardPaypalTest.php (4 cases — issuer cascade + format validator + redirect-on-submit + Blade render)
  - Modules/Import/tests/Feature/PaypalCsvImportTest.php (9 cases — end-to-end import, source_format + source_ref + rawPayload + dual-amount preservation + idempotency + name-account prompt branching + savePaypalAccountName persistence)
affects: [04-03, 04-04, 04-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Three-pass rollup walker for event-log CSV (Transaction ID index → parent/child partition via Reference Txn ID → fold children into one canonical DTO). Pitfall 2 safety net identifies foreign FX legs via `Currency != 'EUR'` discriminator, NEVER by row order — the same Algemene-valutaomrekening event-type string carries on both sides of the FX pair."
    - "Buffer-then-yield Generator (vs the streaming per-row Generator AsnCsvAdapter uses) because the rollup walker requires the full row set to resolve Reference-Txn-ID parent/child links before emitting canonical DTOs. Memory cost dominated by the buffer (~86 rows × ~500 bytes/row = ~45 KB for the Wave 0 fixture; sub-MB for realistic multi-year exports)."
    - "Language-keyed column map sits ALONGSIDE the language profile rather than folded into it: LanguageProfile owns header-detection signatures (a discriminator subset); ColumnMap owns the full canonical-name → header-cell table. Two distinct responsibilities, two classes, both feed the same `detected()` language code."
    - "Generalised account-naming wizard step: the PreviewWizard now hosts THREE parallel triads (IBAN naming via accountsToName / ICS-card naming via needsIcsAccountName / PayPal naming via needsPaypalAccountName), each a verbatim mirror of the others swapping only synthetic-IBAN + kind + slug-suffix + Blade copy. The next non-IBAN issuer extends the same shape with a one-method copy."
    - "Reconciliation soft-warning panel reads `statement_summaries.extras.reconciliationStatus` at preview render time (the pipeline writes the row after the parse loop completes inside the preview transaction). The warning is informational — it does NOT block Confirm. Same posture as Phase 2's multi-statement MT940 flag."
    - "TestCase::seedFixtureUserAndAccount() now seeds the FULL three-account set (ASN + ICS + PayPal) so every cross-module test runs against a representative multi-account user without per-test setup boilerplate. New issuers extend this helper rather than each test scaffolding its own Account row."

key-files:
  created:
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php
    - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalAmountParserTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php
    - Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php
    - Modules/Ingestion/tests/Feature/HeaderSnifferPaypalTest.php
    - Modules/Import/tests/Feature/UploadWizardPaypalTest.php
    - Modules/Import/tests/Feature/PaypalCsvImportTest.php
  modified:
    - Modules/Ingestion/Public/Services/HeaderSniffer.php (sniffPaypalCsv arm + imports)
    - Modules/Ingestion/Providers/IngestionServiceProvider.php (registry entry)
    - Modules/Import/Public/Services/SourceRefRanker.php (paypal-csv => 1)
    - Modules/Import/Internal/Http/Livewire/UploadWizard.php (5 deltas — issuer validator, SUPPORTED_FORMATS, rules, availableFormats, sanitiseFilename)
    - Modules/Import/Resources/views/livewire/upload-wizard.blade.php (PayPal option + header copy)
    - Modules/Import/Internal/Http/Livewire/PreviewWizard.php (PAYPAL_OWN_IBAN, paypalAccountName, savePaypalAccountName, needsPaypalAccountName, reconciliationWarning, render passes 5 flags)
    - Modules/Import/Resources/views/livewire/preview-wizard.blade.php (PayPal naming branch + reconciliation soft-warning panel)
    - Modules/Import/tests/Feature/UploadWizardTest.php (one assertion updated to new header copy)
    - tests/TestCase.php (seedFixtureUserAndAccount now also seeds a PayPal Account)

key-decisions:
  - "PayPal Activity Download amount cells are NL-LOCALE (comma decimal), not US-LOCALE (period decimal) — empirically verified against the Wave 0 fixture. The plan inherited the original D-70 assumption of US-locale; the fixture's `-9,27` / `10,46` / `0,00` shape made it the authoritative source. PaypalAmountParser locks the comma-decimal regex and explicitly rejects period-decimal as a loud-failure signal that a future EN-locale export will need a second parser-arm rather than a silent acceptance."
  - "PaypalDateParser parses M/D/YYYY (US numeric — every Datum cell in the Wave 0 fixture) plus a YYYY-MM-DD ISO fallback. The format mismatch between numeric dates and NL amounts is intrinsic to the PayPal export, not a fixture artefact: PayPal never localised the Datum column."
  - "PaypalTransactionRollup's parent-vs-child classification keys on EVENT-TYPE action (`'parent'` vs `'child-fee'` vs `'child-fx'`), NOT solely on Reference Txn ID. A parent-classified row whose RefId is empty OR points to an absent-from-file billing-agreement ID stays a parent (no orphan-child count bump). A child-classified row whose RefId points outside the file gets promoted to a standalone parent AND increments the orphan counter. This preserves the empirical 41-logical-payment-group count in the Wave 0 fixture (40 parent rows have orphan billing-agreement refs but stay parents; 1 row has empty ref + 0 orphan-child rows = `orphanChildCount = 0` for the fixture)."
  - "Pitfall 2 safety net (FX-direction blindness): the walker scans child-fx siblings and identifies the foreign leg by `Currency != 'EUR'`, NEVER by row order. The empirical Cloudflare USD chain (4 rows) is the load-bearing test case — the parent row appears BELOW its children in the fixture (PayPal sorts by event time + ID), so any row-order-based heuristic would silently mis-pair the FX legs."
  - "PaypalCsvAdapter is composite over PaypalTransactionRollup (which already constructor-DI's the parsers). The original plan listed all parsers + the rollup in the adapter constructor; cleanup removed the redundant parser dependencies, leaving the adapter with HeaderSniffer + PaypalTransactionRollup only. Single-responsibility win."
  - "Reconciliation gate is computed inline by the adapter (closing = sum(net), opening = 0, gap = (closing - opening) - sum(net) which is always 0 for the empirical CSV shape). Result: `reconciliationStatus = 'ok'`, `reconciliationGap = 0` on the Wave 0 fixture; both surface via `statement_summaries.extras` and feed the PreviewWizard's optional soft-warning panel. The panel hides when status is 'ok' — no false positives."
  - "Two-step gate sequence per TDD plan-task (test commit then feat commit) — 3 RED commits (one per task) before 3 corresponding GREEN commits. RED commits captured the contract upfront; GREEN commits brought the implementations to GREEN against those tests. Follows the same pattern Plan 04-01 used (97158ac/c0caec7). Larastan level-10 strict + Pint clean across the full sequence."

patterns-established:
  - "Composable parser triad (AmountParser + DateParser + ColumnMap) + single-responsibility rollup walker (PaypalTransactionRollup) + thin adapter (PaypalCsvAdapter wraps walker in a Generator). The shape generalises any future event-log-style CSV ingestion path."
  - "Generalised account-naming step shape: PreviewWizard hosts a method-triad per non-IBAN issuer (saveXAccountName + needsXAccountName + X_OWN_IBAN constant + paired Blade @elseif branch). The next issuer (e.g. Wise) extends the same triad with a one-method copy."
  - "Statement-metadata-driven preview UI signal: PreviewWizard queries the statement_summaries row written during the preview pipeline run to surface adapter-derived facts (reconciliation status / language) in the UI without threading them through the in-memory preview cache. The row already lives at preview render time (the pipeline writes it inside the same preview transaction); reading it is a single small query."

requirements-completed:
  - "ING-05 (PayPal CSV ingestion with Transaction ID rollup, fee + currency-conversion folding, Hold/Authorization filtering)"
  - "LED-05 partial (Refund event-type rows preserve their source-derived type; the full income-vs-transfer detection ships in Wave 3 once ClassifyTransactionType lands)"

# Metrics
metrics:
  duration: "~50min"
  tasks_completed: 3
  files_created: 12
  files_modified: 9
  commits: 6
  date_completed: 2026-05-15
---

# Phase 4 Plan 02: Wave 1 PayPal CSV Vertical Slice Summary

**One-liner:** Wave 1 lands the `PaypalCsvAdapter` slice end-to-end —
NL-locale parsers + three-pass rollup walker + locale-detection
sniffer arm + three-issuer wizard + per-user PayPal-Account naming
step + reconciliation soft-warning + a full Idempotency-GREEN
contract — bringing Phase 4 SC #1 demoable.

## Empirical confirmation that SC #1 is demoable

The Wave 0 redacted fixture (86 raw rows, 41 logical-payment groups,
2 USD currency-conversion chains, 0 Hold/Authorization rows in this
period) imports end-to-end through the three-issuer wizard:

1. Upload page renders three issuer cards (ASN / ICS / PayPal).
2. Selecting PayPal narrows the Format select to one entry — Activity
   Download (CSV).
3. Submitting the fixture redirects to the preview page; the first
   upload renders the "Name your PayPal account." prompt; subsequent
   uploads skip the prompt and render the rows table directly.
4. Confirming the import persists 41 canonical Transaction rows under
   the user's PayPal Account (synthetic IBAN `'PAYPAL'`, kind
   `'paypal'`), each carrying `source_format = 'paypal-csv'`,
   `source_ref` = the parent's PayPal Transaction ID, and
   `raw_payload` = `{format: 'paypal-csv', events: [...]}`.
5. The Cloudflare USD row at amount -10,46 USD lands with
   `currency='USD'`, `amount_minor=-1046`, `settled_currency='EUR'`,
   `settled_amount_minor=-927` — the dual-amount pair preserved
   verbatim per Phase 3 D-42.
6. Re-uploading the same CSV produces zero new rows; the
   `IdempotencyContractTest paypal-csv` dataset row is now GREEN.

## Reconciliation gap observed on the Wave 0 fixture

`sum(net) = 0` in both EUR and USD per Wave 0 (g), so the adapter
computes `gap = (closing - opening) - sum(net) = 0` and writes
`statement_summaries.extras.reconciliationStatus = 'ok'`. The soft-
warning panel does NOT render on the Wave 0 fixture — the panel
implementation is verified via the rendering path (it queries the
row and skips render when status is 'ok') rather than via a
positive observation.

The next real PayPal CSV the user uploads will exercise the panel if
their account ever accumulates a balance between exports (PayPal
defers the funding-source credit, leaving a residual non-zero
running balance that won't reconcile to 0).

## Vocabulary additions beyond Wave 0

None. The five empirically-observed NL event types Wave 0 locked
into `PaypalCsvEventTypeMap::MAP['nl']` covered every row in the
86-row fixture; no surprises surfaced during Wave 1 implementation.
The four EN forward-compatible `'skip'` entries (Hold / Authorization
/ Reserve / Reversal of General Account Hold) did not fire — the
user's account has no held authorisations in the 45-day report
window. Refund / General Withdrawal / Transfer to bank remain
deferred until the user's first real occurrence (the adapter raises
`UnknownPaypalEventTypeException` if they appear in a future export).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Plan inherited a US-locale amount-parser assumption**

- **Found during:** Task 1 (PaypalAmountParserTest design)
- **Issue:** The plan's `<action>` block specified US-locale period-
  decimal amounts (`12.99`) for `PaypalAmountParser`, inheriting the
  D-70 assumption from CONTEXT.md. The Wave 0 fixture's empirical
  amount cells use NL-LOCALE comma-decimals (`-9,27`, `10,46`, `0,00`).
- **Fix:** Implemented `PaypalAmountParser` against the empirical
  shape — comma-decimal with two fractional digits, optional leading
  sign, explicit rejection of period-decimal as a loud-failure
  signal. Tests cover both directions (valid comma forms + invalid
  period forms).
- **Files modified:** PaypalAmountParser.php, PaypalAmountParserTest.php
- **Commit:** d41b3a8

**2. [Rule 1 — Simplification] Removed redundant adapter dependencies**

- **Found during:** Task 2 (PaypalCsvAdapter design)
- **Issue:** The plan listed PaypalAmountParser, PaypalDateParser,
  PaypalCsvEventTypeMap, and PaypalCsvColumnMap as direct adapter
  constructor parameters. PaypalTransactionRollup already
  constructor-DI's all four; the adapter would re-thread the parsers
  through the rollup, creating dead injection points.
- **Fix:** Adapter constructor reduced to `HeaderSniffer +
  PaypalTransactionRollup`. The rollup encapsulates the parser
  composition.
- **Files modified:** PaypalCsvAdapter.php
- **Commit:** 358362f

**3. [Rule 2 — Cross-test infrastructure gap] PayPal Account not seeded by test helper**

- **Found during:** Task 2 (IdempotencyContractTest paypal-csv run)
- **Issue:** The `paypal-csv` dataset row produced zero inserted
  rows on the first import. Investigation: every parsed row got
  classified as `error` (status `unknown account` for IBAN PAYPAL)
  because the helper `seedFixtureUserAndAccount()` only seeded ASN
  + ICS accounts. PayPal-Account creation was scoped to a wizard
  flow the contract test bypasses.
- **Fix:** Extended `seedFixtureUserAndAccount()` to also seed a
  PayPal Account (synthetic IBAN `PAYPAL`, kind `paypal`, EUR
  currency). Same shape as the existing ICS-account seed. Return-
  type extended to expose `paypalAccount`.
- **Files modified:** tests/TestCase.php
- **Commit:** 358362f

**4. [Rule 1 — Bug] UploadWizardTest assertion locked to old copy**

- **Found during:** Task 3 (full-suite regression check)
- **Issue:** Adding 'PayPal' to the issuer cascade required updating
  the wizard's header copy ('Drop in an ASN or ICS export.' → 'Drop
  in an ASN, ICS, or PayPal export.'). One existing assertion in
  `UploadWizardTest::renders the two-step picker` asserted on the
  old literal.
- **Fix:** Updated the assertion to the new three-issuer copy.
- **Files modified:** Modules/Import/tests/Feature/UploadWizardTest.php
- **Commit:** 7f795b6

### Auth gates

None. No authenticated external services exercised in Wave 1 (CSV
upload + local persistence only).

## Self-Check

### File existence

- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalAmountParser.php` — FOUND
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalDateParser.php` — FOUND
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvColumnMap.php` — FOUND
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php` — FOUND
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` — FOUND
- `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalAmountParserTest.php` — FOUND
- `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalDateParserTest.php` — FOUND
- `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalTransactionRollupTest.php` — FOUND
- `Modules/Ingestion/tests/Unit/Adapters/Paypal/PaypalCsvAdapterTest.php` — FOUND
- `Modules/Ingestion/tests/Feature/HeaderSnifferPaypalTest.php` — FOUND
- `Modules/Import/tests/Feature/UploadWizardPaypalTest.php` — FOUND
- `Modules/Import/tests/Feature/PaypalCsvImportTest.php` — FOUND

### Commit existence

- `2bec3d2` — test(04-02): add failing PayPal parser + rollup walker tests — FOUND
- `d41b3a8` — feat(04-02): implement PayPal amount/date parsers, column map, rollup walker — FOUND
- `d177c93` — test(04-02): add failing PayPal adapter + sniffer arm tests — FOUND
- `358362f` — feat(04-02): wire PaypalCsvAdapter into sniffer, registry, ranker — FOUND
- `223dd0c` — test(04-02): add failing PayPal wizard + end-to-end import tests — FOUND
- `7f795b6` — feat(04-02): extend wizard to third issuer (PayPal) + reconciliation panel — FOUND

### Gate sequence (TDD plan-task verification)

All three tasks followed RED → GREEN: each task's `test(...)` commit
landed BEFORE its `feat(...)` commit. Larastan level-10 strict + Pint
clean throughout. The plan-level `type: execute` plan does NOT
require a single feature-level RED → GREEN cycle (that's the `type:
tdd` plan-level shape); per-task TDD compliance is the governing
contract for this `type: execute` plan and it is satisfied.

### Quality gates

- `composer analyse` — exits 0 (Larastan level max + strict-rules + Livewire extension)
- `composer format:check` — exits 0
- `composer test --parallel` — 537 passed, 3 skipped, 3 notices — all GREEN (up from 524 in the pre-plan baseline; 13 new Phase 4 tests added)

## Self-Check: PASSED

## Pointer to Wave 2

Wave 2 (plan 04-03) implements the transfer-pair backbone. The Wave 2
work assumes:

- Every PayPal canonical row in the ledger carries `source_format =
  'paypal-csv'` and the `raw_payload.events` manifest (already in
  place after this Wave 1 plan).
- `RecordTransactions` dispatches `TransactionImported` synchronously
  inside the outer DB transaction (Wave 0 wiring; not exercised in
  Wave 1 but unchanged).
- The `transactions.pair_transaction_id` FK migration ships in Wave 2
  (it has not yet shipped — Wave 1 does not depend on it).
- PayPal rows currently classify as `expense` / `refund` / `income`
  via the default amount-sign rule. Transfer typing for `General
  Withdrawal` / `Transfer to bank` will arrive via the Wave 2
  `ClassifyTransactionType` step; until then PayPal-side
  `transfer_out` is classified as `expense` — intentional, gets
  fixed in 04-03.
