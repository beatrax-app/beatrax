# `Ingestion` — architecture

The `Ingestion` module owns every source-format adapter: the
CAMT.053 / MT940 / CSV parsers for ASN, the PDF parser for ICS, the
CSV parser for PayPal. Each adapter turns its source format into a
stream of typed `SourceTransactionDto` instances ready for the
`ImportPipeline`'s `NormalizeStage`. The module also exposes the
`HeaderSniffer` the upload wizard calls to validate a file's shape
before parsing, and the `SourceAdapterRegistry` that maps stable
format identifiers to adapters.

## What this module is for

The project pulls financial data from four sources and the user
chooses which file goes through which adapter — there is no
content-sniffing fallback. The user-facing source-format picker on
the upload wizard is the contract; this module is the implementation.
Each adapter is responsible for the parsing-and-normalisation
boundary: it transforms the source's idiosyncratic shape (CAMT.053
ISO-20020 XML; MT940 `:61:` / `:86:` SWIFT tags; PayPal CSV with
language profiles + transaction rollup; ICS PDF with positional text
extraction) into a uniform DTO stream.

What the module explicitly does NOT do:

- It never auto-detects source format from content. The user
  declares the format up front. Auto-detection would silently mis-
  parse a file that happens to look CSV-ish but is something else.
- It never normalises the canonical-transaction shape — that is
  `Import::NormalizeStage`'s job. This module's output is
  `SourceTransactionDto`, a closer-to-source shape.
- It never persists anything. The adapter output is a stream the
  pipeline consumes; the only persistence path is through Ledger's
  `RecordsTransactions`.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `SourceAdapter::parse(SplFileInfo $file): iterable<SourceTransactionDto>`
    — the single adapter contract. Every per-source adapter
    implements it.
  - `AccountResolver::resolve(string $iban, User $user): AccountResolution`
    — abstract; the concrete resolver lives in
    [`Ledger`](../ledger/architecture.md) and is injected
    where ingestion code needs account routing.
- **DTOs/**
  - `SourceTransactionDto` — the close-to-source shape.
  - `SniffResult`, `KnownAccount`, `UnknownAccount`,
    `AccountResolution`.
- **Services/**
  - `HeaderSniffer::sniff(SplFileInfo $file): SniffResult` —
    pre-parse validation (header shape + character set + first-row
    sanity).
  - `SourceAdapterRegistry::for(string $formatId): SourceAdapter`
    — maps `'asn-csv'`, `'asn-camt053'`, `'asn-mt940'`,
    `'ics-pdf'`, `'paypal-csv'` to the adapter instance.
- **Exceptions/** — typed exceptions for every documented failure
  mode (`InvalidAmountException`, `InvalidDateException`,
  `MissingPaypalTransactionTypeMapException`,
  `UnknownPaypalEventTypeException`, `UnsupportedFormatException`,
  `SniffMismatchException`, `PdfExtractionFailed`,
  `UnsupportedPaypalCsvLanguageException`,
  `UnsupportedPaypalCsvShapeException`).
- **Paypal/** — `PaypalCsvEventTypeMap` (the canonical
  PayPal-event-name → PaymentType mapping table).

`Internal/` houses the adapters:

- **Internal/Adapters/Asn/** — `AsnCsvAdapter`, `AsnCamt053Adapter`,
  `AsnMt940Adapter`, plus parser helpers (`AsnAmountParser`,
  per-format header profile classes, the MT940 lexer + Tag61 +
  Tag86 parsers + `AsnMt940CounterpartyCleaner`).
- **Internal/Adapters/Ics/** — `IcsPdfAdapter`, plus parser
  helpers (`PdfTextExtractor`, `IcsAmountParser`,
  `IcsDateParser`, `IcsPdfExtractionMap`,
  `IcsPdfHeaderProfile`).
- **Internal/Adapters/Paypal/** — `PaypalCsvAdapter`, plus
  parser helpers (`PaypalAmountParser`, `PaypalDateParser`,
  `PaypalCsvColumnMap`, `PaypalCsvLanguageProfile`,
  `PaypalTransactionRollup`).

## Key services + events

- `HeaderSniffer::sniff($file)` — opens the file, reads the
  first few hundred bytes, returns a `SniffResult` describing
  the detected character set, header signature, and any
  mismatch flags. The upload wizard surfaces a friendly error
  before reaching the adapter.
- `SourceAdapterRegistry::for($formatId)` — keyed lookup;
  unknown id raises `UnsupportedFormatException`.
- `SourceAdapter::parse($file)` — each concrete adapter is
  stream-based (yields DTOs); memory-efficient against
  multi-megabyte CAMT XML or thousand-row PayPal CSV.
- `PaypalCsvEventTypeMap` — the canonical Public mapping
  table. Adding a new PayPal event type is a single edit in
  this Public file so the runtime hinter chain picks it up
  without crossing the module boundary.

The module raises no events; it's purely a parser layer.

## Data flow

The pre-parse sniff:

```
UploadWizard
  → HeaderSniffer::sniff($file)
       → read first chunk
       → return SniffResult(charset, headerSignature, mismatchFlags)
  → wizard validates against declared format
  → on mismatch: friendly error, don't run the adapter
```

The parse phase (called from `ImportPipeline::ParseStage`):

```
ImportPipeline.preview
  → ParseStage
       → SourceAdapterRegistry::for($sourceFormat)
       → SourceAdapter::parse($file)
            yield SourceTransactionDto per row
  → NormalizeStage (Import-owned) — turns the
                                     SourceTransactionDto into a
                                     CanonicalTransaction
```

The PayPal CSV path (most complex):

```
PaypalCsvAdapter::parse
  → PaypalCsvLanguageProfile::detect (en, nl, etc.)
  → read CSV via league/csv (header-aware)
  → for each row:
       → map columns via PaypalCsvColumnMap
       → PaypalAmountParser::parse
       → PaypalDateParser::parse
       → PaypalCsvEventTypeMap::lookup($eventType)
            → UnknownPaypalEventTypeException on miss
       → yield SourceTransactionDto
  → PaypalTransactionRollup::combine (parent-child PayPal events
                                       collapse to one DTO)
```
