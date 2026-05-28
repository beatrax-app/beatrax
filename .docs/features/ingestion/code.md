# `Ingestion` — code

The file-level map for the module.

## Directory layout

```
Modules/Ingestion/
├── Public/
│   ├── Contracts/
│   │   ├── SourceAdapter.php
│   │   └── AccountResolver.php
│   ├── Dto/
│   │   ├── SourceTransactionDto.php
│   │   ├── SniffResult.php
│   │   ├── AccountResolution.php
│   │   ├── KnownAccount.php
│   │   └── UnknownAccount.php
│   ├── Exceptions/
│   │   ├── InvalidAmountException.php
│   │   ├── InvalidDateException.php
│   │   ├── MissingPaypalTransactionTypeMapException.php
│   │   ├── UnknownPaypalEventTypeException.php
│   │   ├── PdfExtractionFailed.php
│   │   ├── SniffMismatchException.php
│   │   ├── UnsupportedFormatException.php
│   │   ├── UnsupportedPaypalCsvLanguageException.php
│   │   └── UnsupportedPaypalCsvShapeException.php
│   ├── Paypal/
│   │   └── PaypalCsvEventTypeMap.php
│   └── Services/
│       ├── HeaderSniffer.php
│       └── SourceAdapterRegistry.php
├── Internal/
│   └── Adapters/
│       ├── Asn/
│       │   ├── AsnCsvAdapter.php
│       │   ├── AsnCamt053Adapter.php
│       │   ├── AsnMt940Adapter.php
│       │   ├── AsnAmountParser.php
│       │   ├── AsnCamt053HeaderProfile.php
│       │   ├── AsnCsvColumnMap.php
│       │   ├── AsnCsvHeaderProfile.php
│       │   ├── AsnMt940HeaderProfile.php
│       │   ├── AsnMt940Lexer.php
│       │   ├── AsnMt940Tag61Parser.php
│       │   ├── AsnMt940Tag86Parser.php
│       │   ├── AsnMt940CounterpartyCleaner.php
│       │   └── Dto/
│       │       ├── Mt940BalanceTuple.php
│       │       ├── Mt940Narrative.php
│       │       └── Mt940StatementLine.php
│       ├── Ics/
│       │   ├── IcsPdfAdapter.php
│       │   ├── IcsAmountParser.php
│       │   ├── IcsDateParser.php
│       │   ├── IcsPdfExtractionMap.php
│       │   ├── IcsPdfHeaderProfile.php
│       │   └── PdfTextExtractor.php
│       └── Paypal/
│           ├── PaypalCsvAdapter.php
│           ├── PaypalAmountParser.php
│           ├── PaypalDateParser.php
│           ├── PaypalCsvColumnMap.php
│           ├── PaypalCsvLanguageProfile.php
│           └── PaypalTransactionRollup.php
├── Database/Migrations/   (empty — no DB schema owned here)
├── Routes/
│   ├── web.php
│   └── console.php
├── Providers/
│   └── IngestionServiceProvider.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── Integration/
```

## Public API

- **Contracts/**
  - `SourceAdapter::parse(SplFileInfo $file):
    iterable<SourceTransactionDto>`. Stream-based.
  - `AccountResolver::resolve(string $iban, User $user):
    AccountResolution`. Concrete impl in `Ledger`.
- **DTOs/**
  - `SourceTransactionDto` — close-to-source row shape with
    every field the adapter could observe (amount, currency,
    posted-at, settled-at, counterparty name + IBAN,
    description, source-specific `raw_payload` blob).
  - `SniffResult` — `(detectedCharset, headerSignature,
    mismatchFlags)`.
  - `AccountResolution` — discriminated union of
    `KnownAccount` + `UnknownAccount`.
- **Services/**
  - `HeaderSniffer::sniff(SplFileInfo $file): SniffResult`.
  - `SourceAdapterRegistry::for(string $formatId):
    SourceAdapter`. Throws `UnsupportedFormatException` on
    unknown id.
- **Exceptions/** — every documented failure mode is a typed
  exception so the wizard / pipeline can render a friendly
  message instead of a stack trace.
- **Paypal/**
  - `PaypalCsvEventTypeMap` — the canonical event-name → type
    mapping table. Public so adding a new event type is one
    edit visible at the boundary.

## Internal services

- `Internal/Adapters/Asn/AsnCsvAdapter` — Asn-specific CSV
  adapter built on `league/csv`. Header-aware (skips repeated
  bank-export header rows that appear mid-file when a user
  pasted statements together).
- `Internal/Adapters/Asn/AsnCamt053Adapter` — CAMT.053 adapter
  built on `genkgo/camt`. Handles every CAMT.053 sub-version
  ASN exports (001.02, 001.03, 001.08).
- `Internal/Adapters/Asn/AsnMt940Adapter` — MT940 adapter
  built on `kingsquare/php-mt940` augmented with the
  module-local lexer + Tag61 / Tag86 parsers for ASN-specific
  narrative shapes.
- `Internal/Adapters/Ics/IcsPdfAdapter` — Mijn ICS consumer-
  portal PDF adapter. Uses `PdfTextExtractor` (spatie/pdf-to-text
  or similar) for positional text extraction.
- `Internal/Adapters/Paypal/PaypalCsvAdapter` — PayPal Activity
  Download CSV adapter. Handles multiple PayPal languages via
  `PaypalCsvLanguageProfile` (en, nl, …) and the parent-child
  event rollup via `PaypalTransactionRollup`.
- Per-source helpers (amount parsers, date parsers, column
  maps, header profiles) — each adapter's
  parsing-and-normalisation utilities, kept Internal so the
  per-source shape can evolve without leaking through the
  contract.

## Models + migrations

The module owns no Eloquent models and no domain tables. All
ingested data lands in [`Ledger`](../ledger/code.md)'s tables via
the `RecordsTransactions` contract.

## Provider wiring

`IngestionServiceProvider::register()`:

- Singletons `HeaderSniffer`.
- Constructs `SourceAdapterRegistry` via a factory closure that
  maps the five stable format identifiers to their adapter
  instances. Adding a new source format is one edit in this
  closure plus shipping the adapter class.

`IngestionServiceProvider::boot()`:

- Loads migrations (the directory exists for forward
  compatibility; no migrations ship today).
- Loads web/console routes.
