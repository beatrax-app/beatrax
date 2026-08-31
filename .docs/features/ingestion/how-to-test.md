# `Ingestion` — how to test

Practical recipes for exercising the `Ingestion` module in
isolation.

## Unit tests

- **Location:** `Modules/Ingestion/tests/Unit/`
- **What they test:** every per-source helper class against
  fixture inputs — amount parsers (Asn / Ics / Paypal),
  date parsers, column maps, header profiles, the MT940 lexer
  - Tag 61 + Tag 86 parsers, the PayPal CSV language profile +
  rollup, the PDF text extractor (smoke).
- **The two PDF readers:** `PdfTextExtractorTest` covers the
  reader choice and the three refusals; the drawing-only and
  encrypted cases are asserted against *both* readers, because
  the reason shown has to be the same one on a phone and on a
  desktop. `TheInAppReaderRebuildsAColumnLayoutTest` renders the
  committed real-statement text into a per-cell, Flate-compressed
  PDF and reads it back — the shape a bank's report generator
  emits, which the committed one-string-per-row fixture does not
  exercise.
- **Common stubs:** unit tests are pure-function; no stubs
  needed. The exception types are asserted by class, not by
  message.

## Feature tests

- **Location:** `Modules/Ingestion/tests/Feature/`
- **What they test:**
  - The `HeaderSniffer` against representative shapes
    (`HeaderSnifferTest`, `HeaderSnifferPaypalTest`,
    `HeaderSnifferPaypalShapeTest`,
    `HeaderSnifferEmailFileTest`).
  - End-to-end preview regression tests against representative
    fixture files (`IcsPdfPreviewRegressionTest`,
    `PaypalCsvPreviewRegressionTest`).
  - The PayPal funding-leg typing
    (`PaypalFundingLegTypingTest`) — confirms the parent-child
    rollup produces the correct payment-type hints.

## Integration tests

- **Location:** `Modules/Ingestion/tests/Integration/`
- **What they test:** the PDF text extractor end-to-end
  (`PdfTextExtractorSmokeTest`) against a real consumer-portal
  PDF fixture, plus the equivalence of the two readers — the
  same statement, parsed by `pdftotext` and by the in-app
  reader, has to yield the same rows field for field. This suite
  is the one that needs poppler on the host; a machine without
  it runs `pest --exclude-group=integration`.

## Contract / arch invariants

- The repo-wide `noContentSniffingFormatDetection` — forbids
  any adapter from inspecting file content to choose a format.
  The user-declared id is the only entry point.
- The repo-wide `noIngestionWritesToTransactions` — forbids
  any class in `Modules\Ingestion\` from importing
  `Modules\Ledger\Models\Transaction` for write. Adapters are
  read-only over the file.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Ingestion/tests

# Just one adapter family
vendor/bin/pest Modules/Ingestion/tests/Unit/Adapters/Ics

# Just the PayPal regression test
vendor/bin/pest Modules/Ingestion/tests/Feature/PaypalCsvPreviewRegressionTest.php

# Stop on first failure
vendor/bin/pest Modules/Ingestion/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **An adapter raised `InvalidAmountException` on a real
  export** — the source's locale produced a number shape the
  amount parser does not handle (e.g. NL exports using `,`
  decimal separator vs en exports using `.`). Add a fixture
  row, extend the parser, re-run.
- **`UnknownPaypalEventTypeException` on a real PayPal CSV** —
  PayPal added or renamed an event type. Add the new event
  name to `PaypalCsvEventTypeMap` mapping to the appropriate
  payment type; ship the change with a covering unit test.
- **`UnsupportedPaypalCsvLanguageException`** — the export was
  in an unsupported language. Either add the language profile
  - column map for the new language, or change the PayPal
  export language to one already supported (en, nl).
- **`PdfExtractionFailed` on a fresh ICS PDF** — the
  underlying text extractor produced zero positional hits.
  Open the PDF in a viewer; if it renders text-as-image (some
  consumer-portal exports do under certain print options), the
  user re-exports as a text-based PDF.
- **A CSV preview shows duplicate rows** — the adapter likely
  did not skip a mid-file repeated header row. Inspect the
  raw file for "header inside the body" and extend the
  adapter's header-skip pattern.
- **A regression fixture diverges after a parser update** —
  the fixture is the contract. Either the parser change was
  intentional (update the fixture's expected DTO list) or it's
  a regression (revert / fix the parser).

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **No content-sniffing format detection.** The user declares the
  source format up front; `SourceAdapterRegistry::for($formatId)`
  is the only entry point. An unknown id raises
  `UnsupportedFormatException`.
- **`HeaderSniffer::sniff` is a pre-parse validation, not a
  parser.** It reads the first chunk of the file, returns
  `SniffResult` describing the detected character set, header
  signature, and mismatch flags. The upload wizard surfaces a
  friendly error on mismatch before the adapter runs.
- **Every adapter implements `SourceAdapter`.** Stream-based
  `yield` so multi-megabyte files do not load entirely into
  memory.
- **Every failure mode is a typed exception.** Callers branch on
  exception class, not on message string. The Public
  exceptions enumerate the documented modes — adding a new
  failure mode requires a new exception class so the consumer
  surface stays explicit.
- **Adapter output is `SourceTransactionDto`.** Adapters never
  emit a `CanonicalTransaction` directly; the normalisation is
  `Import::NormalizeStage`'s job. This boundary keeps the
  per-source shape isolated.
- **The CAMT.053 adapter handles every sub-version ASN exports.**
  001.02, 001.03, 001.08. The underlying `genkgo/camt` library
  covers them; the adapter is a thin DTO-mapping layer.
- **The MT940 adapter handles the ASN-specific narrative
  conventions.** ASN's Tag 86 narrative shape differs slightly
  from the standard SWIFT MT940; the module-local
  `AsnMt940Tag86Parser` + `AsnMt940CounterpartyCleaner` handle
  the divergence.
- **The PayPal CSV adapter is multi-language.**
  `PaypalCsvLanguageProfile` detects en / nl etc.; the column
  map flips accordingly. An unsupported language raises
  `UnsupportedPaypalCsvLanguageException`.
- **The PayPal CSV adapter rolls up parent-child events.** A
  PayPal payment that produces a parent "Express Checkout
  Payment Sent" + a child "General Withdrawal" within the same
  CSV is collapsed into one logical `SourceTransactionDto` by
  `PaypalTransactionRollup`.
- **An unknown PayPal event type raises
  `UnknownPaypalEventTypeException`.** The Public
  `PaypalCsvEventTypeMap` is the source of truth; adding a new
  event type is one edit in the map. The exception's message
  carries the unknown type so the user can report it.
- **The ICS PDF adapter raises `PdfExtractionFailed` for any
  unreadable PDF.** No silent fallback to "best-effort"
  parsing; the user uploads a corrected file.
- **Adapter parsers never write to the database.** This module
  is read-only over the file; persistence is the pipeline's
  responsibility downstream.

## Edge cases

- **A file in the wrong character set** — `HeaderSniffer`
  flags the mismatch via `SniffResult.mismatchFlags`. The
  wizard surfaces it; the user re-saves in UTF-8 (or
  Windows-1252 for legacy ICS exports).
- **An empty file** — adapter yields zero DTOs; pipeline
  produces an empty preview; confirm is a no-op.
- **A CSV with trailing repeated header rows** (user pasted
  exports together) — `PositionalCsvAdapter` skips header-shaped rows
  mid-stream.
- **A CAMT.053 with multiple statements in one file** — the
  adapter yields per-entry DTOs across all statements.
- **A PayPal CSV in an unsupported language profile** —
  `UnsupportedPaypalCsvLanguageException`. The user changes the
  PayPal export language to one in the profile set.
- **An ICS PDF whose text extraction produces zero positional
  hits** — `PdfExtractionFailed`; the user uploads a fresh
  PDF (sometimes the consumer-portal export hits an edge case
  on a long statement page).
- **A PayPal CSV row whose amount has no sign character** —
  `PaypalAmountParser` infers sign from the event type
  (`Express Checkout Payment Sent` → negative;
  `Refund` → positive); a missing event-type map raises
  `MissingPaypalTransactionTypeMapException`.
- **An MT940 file with an unbalanced Tag 86 narrative** — the
  lexer reports the offending line; the file is rejected.

## Cross-module collaborators

- **Depends on**
  - `genkgo/camt` (CAMT.053), `kingsquare/php-mt940` (MT940),
    `league/csv` (CSV); a PDF text extractor for ICS.
  - [`Ledger`](../ledger/how-to-test.md) — concrete
    `AccountResolver` impl (the contract is declared here but
    Ledger provides the implementation).
- **Depended on by**
  - [`Import`](../import/how-to-test.md) — `ParseStage` calls
    `SourceAdapterRegistry::for(...)->parse(...)`.
  - The upload wizard — calls `HeaderSniffer::sniff` for the
    pre-parse validation.

## Configuration + feature flags

- The stable format identifiers (`asn-csv`,
  `asn-camt053`, `asn-mt940`, `ics-pdf`, `paypal-csv`) are
  fixed in the provider's `SourceAdapterRegistry` factory
  closure. Adding a new format is a constant edit + an
  adapter class.
- `PaypalCsvEventTypeMap` is Public so adding a new event
  type is observable at the boundary.
- No env flag changes adapter behaviour; the parsers are pure
  functions over the file content.
