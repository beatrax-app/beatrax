# `Ingestion` — how to test

Practical recipes for exercising the `Ingestion` module in
isolation.

## Unit tests

- **Location:** `Modules/Ingestion/tests/Unit/`
- **What they test:** every per-source helper class against
  fixture inputs — amount parsers (Asn / Ics / Paypal),
  date parsers, column maps, header profiles, the MT940 lexer
  + Tag 61 + Tag 86 parsers, the PayPal CSV language profile +
  rollup, the PDF text extractor (smoke).
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
  PDF fixture.

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
  + column map for the new language, or change the PayPal
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
