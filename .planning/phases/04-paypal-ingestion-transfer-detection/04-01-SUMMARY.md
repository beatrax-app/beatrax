---
phase: 04-paypal-ingestion-transfer-detection
plan: 01
subsystem: ingestion
tags: [paypal, csv, ingestion, wave-0, fixture, event, scaffolds]

# Dependency graph
requires:
  - phase: 02-asn-statement-coverage-camt-053-mt940
    provides: stateful-adapter `lastStatementSummary()` shape; SourceAdapterRegistry pattern; fingerprint v3; SniffMismatchException typed-exception shape (mirrored for the two new PayPal exceptions)
  - phase: 03-ics-cards-multi-currency-display
    provides: Wave 0 anonymisation pattern (raw under `/local/`, redacted fixture + fixture-record `.md` + Wave 0 findings doc in the phase directory); SourceTransactionDto nullable settled-pair fields (D-42) reused for PayPal currency conversion (D-63); `rawPayload` per-row archive contract (D-49) reused for PayPal event manifest (D-65); IBAN check-digit anonymisation convention (NL00ASNB0000000000)
provides:
  - scripts/anonymize_paypal_csv.php — idempotent two-pass deterministic counter-map redactor for PayPal Activity Download CSV (zero Composer deps)
  - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv — committed redaction of a real 2026-04-01 → 2026-05-15 NL-locale Activity Download export (86 rows; EUR + USD; 4 currency-conversion rows in 2 USD chains)
  - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md — fixture-record doc covering empirical column layout, event types observed, parent-child chain shapes, FX representation, reconciliation gate, source_ref availability, funding-source absence, transfer-to-bank absence
  - .planning/phases/04-paypal-ingestion-transfer-detection/04-WAVE-0-FINDINGS.md — D-60 (a)–(g) empirical report set
  - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php — language-detection profile (Wave 0 nl signature locked from the fixture)
  - Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php — empirical event-type → canonical-action map (5 nl entries observed; 4 EN forward-compatible skip entries; classify() / transactionType() raise typed exceptions on unknown event types)
  - Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php — typed exception, user-facing message
  - Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php — typed exception, user-facing message
  - Modules/Import/Public/Events/TransactionImported.php — final readonly per-row event (Transaction + User payload, no ShouldHandleEventsAfterCommit / ShouldQueue — sync in-transaction dispatch)
  - Modules/Ledger/Public/Actions/RecordTransactions: dispatch site for TransactionImported on every $effected === 1 row, constructor-DI Dispatcher, __invoke() widened to accept User $user
  - Modules/Ledger/Public/Contracts/RecordsTransactions: matching contract widen
  - Modules/Import/Public/Actions/ConfirmImport: forwards its existing $user into the recorder
  - tests/Contracts/IdempotencyContractTest paypal-csv dataset row — REDs on "first.inserted > 0" until Wave 1's PaypalCsvAdapter lands
  - Modules/Ledger/tests/Feature/RecordTransactionsDispatchesEventTest — 3 GREEN test cases pinning the dispatch contract for the future PairTransferCandidates listener
  - .gitignore carve-out so /local/paypal/.gitkeep is tracked while raw exports under /local/paypal/ stay ignored
affects: [04-02, 04-03, 04-04, 04-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Wave 0 fixture-first protocol (Phase 2 + 3 precedent) — redacted CSV + fixture-record .md + Wave 0 findings doc all land BEFORE adapter code so the parser is written against empirical truth, not guesswork"
    - "Two-pass deterministic counter map for ID anonymisation (Pitfall 5) — `$realToSynthetic` populated in Pass 1 from the union of `Transactiereferentie` + `Reference Txn ID` columns; Pass 2 rewrites both columns through the same map so parent-child links survive"
    - "Order-insensitive header-signature detection (PaypalCsvLanguageProfile) — `array_diff($required, $columns)` lets PayPal reorder columns across exports without breaking detection"
    - "Forward-compatible EN entries in a language-keyed map (PaypalCsvEventTypeMap['nl']) — universally-filtered event types (Hold/Authorization/Reserve/Reversal) land as EN entries inside the nl map because PayPal often leaves those un-localised; first NL form encountered adds the localised entry"
    - "Constructor-injected Illuminate\\Contracts\\Events\\Dispatcher inside an action that fires a Public event (mirrors Modules/Categorization/Public/Actions/AssignCategory) — no `event()` helper, no `Event::` facade, project DI-only rule honoured"
    - "Synchronous in-transaction event dispatch — TransactionImported does NOT implement ShouldHandleEventsAfterCommit / ShouldQueue so Wave 2's PairTransferCandidates listener can observe just-inserted partner rows in the same outer DB transaction (Pitfall 1)"

key-files:
  created:
    - scripts/anonymize_paypal_csv.php
    - local/paypal/.gitkeep
    - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv
    - Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md
    - Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php
    - Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php
    - Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php
    - Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php
    - Modules/Import/Public/Events/TransactionImported.php
    - Modules/Ledger/tests/Feature/RecordTransactionsDispatchesEventTest.php
    - .planning/phases/04-paypal-ingestion-transfer-detection/04-WAVE-0-FINDINGS.md
  modified:
    - .gitignore (carve-out so /local/paypal/.gitkeep is tracked)
    - Modules/Ledger/Public/Actions/RecordTransactions.php (Dispatcher DI + User param + event dispatch)
    - Modules/Ledger/Public/Contracts/RecordsTransactions.php (contract widen)
    - Modules/Import/Public/Actions/ConfirmImport.php (forward $user to recorder)
    - Modules/Ledger/tests/Feature/RecordTransactionsTest.php (existing 6 cases updated to pass $this->user)
    - tests/Contracts/IdempotencyContractTest.php (paypal-csv dataset row)

key-decisions:
  - "Empirical PayPal Activity Download CSV is in NL locale (header tokens: Datum, Tijd, Tijdzone, Omschrijving, Valuta, Bruto␣, Kosten␣, Netto, Saldo, Transactiereferentie, Van e-mailadres, Naam, Naam bank, Bankrekening, Verzendkosten, Btw, Factuurreferentie, Reference Txn ID). UTF-8 BOM present. Two header cells (\"Bruto \" and \"Kosten \") ship with a verbatim trailing space — detection trims columns before comparison."
  - "Empirical event-type vocabulary (5 NL strings): Vooraf goedgekeurde betaling – rekening betaald door gebruiker (parent → expense, 39 rows), Express Checkout-betaling (parent → expense, 2 rows), Bankstorting naar PP-rekening (child-fee, 37 rows), Algemene kaartstorting (child-fee, 4 rows), Algemene valutaomrekening (child-fx, 4 rows). Hold/Authorization/Reserve/Reversal not observed — wired as EN forward-compatible 'skip' entries inside MAP['nl']."
  - "Empirical FX shape (D-60 e): 4-row chain per USD purchase — parent (USD native) + Bankstorting funding-source (EUR) + Algemene valutaomrekening EUR leg + Algemene valutaomrekening USD leg, all sharing the parent's Transaction ID via Reference Txn ID. The walker must detect FX via Currency != EUR on a row whose sibling carries Currency == EUR + Omschrijving == 'Algemene valutaomrekening' (Pitfall 2 — never pick 'first row in file' as the native leg)."
  - "Empirical funding source (D-60 d): absent column. PayPal expresses funding via a child row (Bankstorting / Algemene kaartstorting). The adapter must walk the parent's children for these event types when Phase 5's chain resolver needs to link a PayPal charge to its underlying ASN or ICS account."
  - "Empirical Transfer to bank (D-60 f): absent rows. The user's funding model in this period was pull-only (bank → PayPal at payment time); no PayPal → ASN sweeps appear. The fixture record will be extended when a sweep first lands; the empirical NL form (probably 'Algemene opname' or 'Overboeking naar bank') is deferred until observed."
  - "Empirical reconciliation gate (D-60 g): CLEAN. sum(Netto) over the export is 0.00 EUR and 0.00 USD — every parent debit is matched by an instant funding-source credit. No explicit opening/closing balance rows; the adapter computes opening = closing − sum(net), which is zero in both halves for this export."
  - "Merchant `Naam` column is PRESERVED VERBATIM in the redacted fixture (deviates from the plan's 'names → KAARTHOUDER' literal reading). In PayPal's NL Activity Download this column carries the COUNTERPARTY merchant name (Google Cloud EMEA Limited, Netflix.com, Jagex Limited, …), not the cardholder. Per D-58's 'merchant strings preserved verbatim' clause this is the right disposition; the cardholder name does not appear anywhere in PayPal's NL CSV. Documented in paypal-sample-1.md under Redactions applied."
  - "Idempotency-contract RED for paypal-csv lands as 'first->inserted > 0' assertion failure rather than as an exception bubble. The pipeline's catch-all (ImportPipeline::preview line 151) softly converts UnsupportedFormatException → a single error preview row, so the second-pass confirm completes with 0 inserted. RED is in the exact place Wave 1 will fix (registering PaypalCsvAdapter in SourceAdapterRegistry); no SchemaException / other unexpected failure shape — matches the Wave 0 expected baseline."
  - "RecordTransactions widens its public contract (RecordsTransactions::__invoke now accepts User $user). Carrying the User through __invoke avoids one DB lookup per persisted row inside the dispatcher callback that emits TransactionImported. The single consumer (ConfirmImport) already had $user in scope; no orphaned call sites."

patterns-established:
  - "Two-step gate sequence per TDD plan-task (test commit then feat commit) — RED test committed at 97158ac before any production code changed; GREEN implementation committed at c0caec7. Pattern locks in the test-first contract for any future Wave 0 deliverable that pins a cross-module event shape."
  - "Anonymisation script ships a tail integrity-check that counts orphan-child rows but never errors on them (per D-61 they're a legitimate cross-period boundary case). Stronger orphan-detection would falsely abort runs against single-month exports. The fixture record .md documents the orphan count so future runs can spot drift."
  - "Idempotency check on the anonymiser is asserted on the second run's exit code AND on byte-equal output (`diff -q $output $regenerated` returns 0). The script declines to mint a new synthetic for already-synthetic-shaped IDs so re-running on its own output is byte-stable. This is the property D-58 / Pitfall 5 require for the committed fixture to be auditable."

requirements-completed: []  # 04-01 is a Wave 0 enablement plan — it lands fixture + skeletons + event scaffold, NOT a Green slice of any phase requirement. ING-05 stays open until Wave 1 (plan 04-02) lands PaypalCsvAdapter.

# Metrics
metrics:
  duration: "~35min"
  tasks_completed: 3
  files_created: 11
  files_modified: 6
  commits: 4
  date_completed: 2026-05-15
---

# Phase 4 Plan 01: Wave 0 Empirical Enablement Summary

**One-liner:** PayPal CSV Wave 0 lands the redacted Activity Download
fixture + empirical findings doc + language profile / event-type map
skeletons + the cross-module `TransactionImported` event scaffold
(dispatched sync in-transaction from `RecordTransactions`), with the
`IdempotencyContractTest` `paypal-csv` row in the expected Wave 0 RED
state for Wave 1 to bring GREEN.

## Empirical D-60 (a–g) findings

See [04-WAVE-0-FINDINGS.md](04-WAVE-0-FINDINGS.md) for the full
empirical report set. Top-level summary:

- **(a) Language:** `nl` (Dutch). Seven discriminator tokens locked
  into `PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES['nl']`. The
  `Reference Txn ID` token is universally English even inside the
  NL export — that's the strongest discriminator against any
  non-PayPal CSV.
- **(b) Event types:** 5 observed; 4 EN forward-compatible skip
  entries added defensively. See `PaypalCsvEventTypeMap` source for
  the full map.
- **(c) Chains:** single-level depth; ~41 logical-payment groups
  rolled up from 86 raw rows. Two orientations (parent has empty
  Ref + children point back to it; parent has orphan Ref + children
  still point back to it) — both must be tolerated.
- **(d) Funding source:** column absent; identity rides in child
  rows under the parent's Reference Txn ID.
- **(e) FX:** 4-row chain per USD purchase. Native leg = the non-EUR
  parent. Settled leg = the EUR `Algemene valutaomrekening` child.
- **(f) Transfer to bank:** absent in this period (pull-only funding).
- **(g) Reconciliation:** CLEAN — sum(net) = 0.00 in both currencies.

## Language + event-type vocabulary locked

`PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES['nl']`:

```php
['Datum', 'Tijd', 'Tijdzone', 'Omschrijving', 'Valuta', 'Transactiereferentie', 'Reference Txn ID']
```

`PaypalCsvEventTypeMap::MAP['nl']` (5 observed + 4 EN forward-compat):

```php
'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'parent',
'Express Checkout-betaling'                                       => 'parent',
'Bankstorting naar PP-rekening'                                   => 'child-fee',
'Algemene kaartstorting'                                          => 'child-fee',
'Algemene valutaomrekening'                                       => 'child-fx',
'Hold'                                                            => 'skip',
'Authorization'                                                   => 'skip',
'Reserve'                                                         => 'skip',
'Reversal of General Account Hold'                                => 'skip',
```

`PaypalCsvEventTypeMap::TRANSACTION_TYPE['nl']` (2 parent forms,
both expense):

```php
'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'expense',
'Express Checkout-betaling'                                       => 'expense',
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Anonymiser was wiping merchant `Naam` cells**

- **Found during:** Task 1 first-pass anonymisation
- **Issue:** The plan's "names → `KAARTHOUDER`" rule read literally
  would scrub the PayPal `Naam` column. In PayPal's NL Activity
  Download that column carries the COUNTERPARTY merchant name
  (Google Cloud EMEA Limited, Netflix.com, Jagex Limited, …), not
  the cardholder. The cardholder name does not appear anywhere in
  PayPal's CSV.
- **Fix:** Restored `Naam` to "preserved verbatim". CONTEXT.md
  D-58's "merchant strings preserved verbatim" clause is the
  authoritative reading; the plan's name-wiping rule was anticipating
  a column that doesn't exist in the NL export. Documented under
  "Redactions applied" in `paypal-sample-1.md`.
- **Files modified:** scripts/anonymize_paypal_csv.php +
  the redacted fixture (regenerated)
- **Commit:** 8060da3

**2. [Rule 1 — Bug] First-version idempotency check aborted on a
legitimate orphan-shape**

- **Found during:** Task 1 idempotency verification
- **Issue:** The first version of the post-run integrity check
  treated synthetic-shaped orphan references (parents outside the
  file → orphan-child references whose value happens to match the
  `O-<17-digit>` pattern after Pass-1 minted a synthetic for them)
  as a FATAL anonymisation bug, breaking idempotency on the second
  run (the second run sees those refs already minted, doesn't mint
  them again, and the check incorrectly flagged them as broken).
- **Fix:** Removed the "synthetic-shaped orphans are broken" branch.
  Per D-61, orphan-child references are a legitimate cross-period
  boundary case (the parent lives outside this report window). The
  script now counts orphans and logs them to stderr without
  aborting — only structural-bug orphans (which the simplified check
  cannot produce given the deterministic counter map) would abort.
  Verified idempotency: running the script on its own output
  produces byte-identical output.
- **Files modified:** scripts/anonymize_paypal_csv.php
- **Commit:** 8060da3 (the fix landed before the first commit)

### Auth gates

None. No authenticated services exercised in Wave 0.

## Self-Check

### File existence

- `scripts/anonymize_paypal_csv.php` — FOUND
- `local/paypal/.gitkeep` — FOUND (tracked by git)
- `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv` —
  FOUND (tracked by git; raw counterpart `local/paypal/raw-paypal-activity.csv`
  IS gitignored)
- `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.md` —
  FOUND
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-WAVE-0-FINDINGS.md`
  — FOUND
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvLanguageProfile.php`
  — FOUND
- `Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php` — FOUND
- `Modules/Ingestion/Public/Exceptions/UnsupportedPaypalCsvLanguageException.php`
  — FOUND
- `Modules/Ingestion/Public/Exceptions/UnknownPaypalEventTypeException.php`
  — FOUND
- `Modules/Import/Public/Events/TransactionImported.php` — FOUND
- `Modules/Ledger/tests/Feature/RecordTransactionsDispatchesEventTest.php`
  — FOUND

### Commit existence

- `8060da3` — feat(04-01): add PayPal CSV anonymiser, redacted Wave 0 fixture, and empirical findings — FOUND
- `1c618a7` — feat(04-01): scaffold PayPal language profile, event-type map, and typed exceptions — FOUND
- `97158ac` — test(04-01): add failing test for TransactionImported dispatch from RecordTransactions — FOUND
- `c0caec7` — feat(04-01): wire TransactionImported event from RecordTransactions; RED paypal-csv contract dataset — FOUND

### Gate sequence (TDD plan-task verification)

- Task 3 followed RED → GREEN pattern: `test(...)` commit (97158ac)
  landed BEFORE the `feat(...)` implementation commit (c0caec7).
  Larastan level-10 strict + Pint clean throughout.

### Quality gates

- `composer analyse` — exits 0
- `composer format:check` — exits 0
- `composer test --parallel` — 474 passed, 3 skipped, 1 failed (the
  expected paypal-csv RED inside `IdempotencyContractTest`); the
  full suite reports the failure cleanly without breaking unrelated
  tests.

## Self-Check: PASSED

## Pointer to Wave 1

Wave 1 (plan 04-02) implements the `PaypalCsvAdapter` slice. The
Wave 1 adapter expects:

- `PaypalCsvLanguageProfile::LANGUAGE_SIGNATURES['nl']` is the
  source of truth for the NL header signature.
- `PaypalCsvEventTypeMap::MAP['nl']` / `TRANSACTION_TYPE['nl']`
  are the source of truth for the empirical event vocabulary.
- The redacted fixture at
  `Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv`
  is the parser's empirical-truth target.
- `RecordTransactions` already dispatches `TransactionImported`
  synchronously inside the outer DB transaction — Wave 2's
  `PairTransferCandidates` listener subscribes to that event;
  Wave 1 does not need to add the dispatch.

The `IdempotencyContractTest` `paypal-csv` dataset row goes
GREEN the moment Wave 1 registers `'paypal-csv' => PaypalCsvAdapter::class`
in `SourceAdapterRegistry` and the adapter starts producing
canonical rows.
