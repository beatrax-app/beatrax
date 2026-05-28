# `Ingestion` — specs

The behavioural contract for the `Ingestion` module.

## Behavioral contracts

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
  exports together) — `AsnCsvAdapter` skips header-shaped rows
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
  - [`Ledger`](../ledger/specs.md) — concrete
    `AccountResolver` impl (the contract is declared here but
    Ledger provides the implementation).
- **Depended on by**
  - [`Import`](../import/specs.md) — `ParseStage` calls
    `SourceAdapterRegistry::for(...)->parse(...)`.
  - The upload wizard — calls `HeaderSniffer::sniff` for the
    pre-parse validation.

## Configuration + feature flags

- The five stable format identifiers (`asn-csv`,
  `asn-camt053`, `asn-mt940`, `ics-pdf`, `paypal-csv`) are
  fixed in the provider's `SourceAdapterRegistry` factory
  closure. Adding a new format is a constant edit + an
  adapter class.
- `PaypalCsvEventTypeMap` is Public so adding a new event
  type is observable at the boundary.
- No env flag changes adapter behaviour; the parsers are pure
  functions over the file content.
