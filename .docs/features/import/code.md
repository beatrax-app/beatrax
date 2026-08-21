# `Import` — code

The file-level map for the module.

## Directory layout

```
Modules/Import/
├── Public/
│   ├── Contracts/
│   │   ├── RunsImports.php
│   │   ├── ConfirmsImports.php
│   │   ├── NamesAccounts.php
│   │   ├── AppliesEnrichments.php
│   │   ├── PaymentTypeHinter.php
│   │   ├── DetectsStartingBalance.php
│   │   └── ResolvesKnownCounterpartyIban.php
│   ├── Actions/
│   │   ├── RunImport.php
│   │   ├── ConfirmImport.php
│   │   ├── ApplyEnrichments.php
│   │   ├── CreateMerchantAlias.php
│   │   └── MergeMerchantAliases.php
│   ├── Dto/                  (preview DTO, result DTO, hint DTOs, etc.)
│   ├── Enums/                (SourceFormat, PaymentType, ImportRunStatus)
│   ├── Events/
│   │   └── TransactionImported.php
│   ├── Exceptions/
│   ├── Pipeline/             (ResolvesCounterparties — owned by
│   │                          Counterparties module; injected here)
│   └── Services/
│       ├── AccountNamer.php
│       ├── AliasMatchPreviewQuery.php
│       ├── BuildConsolidatedPreviewQuery.php
│       ├── DetectStartingBalancesQuery.php
│       ├── MerchantNameResolver.php
│       ├── PatternGeneralizer.php
│       └── UploadFilename.php
├── Internal/
│   ├── Pipeline/
│   │   ├── ImportPipeline.php
│   │   ├── PreviewCache.php
│   │   └── Stages/
│   │       ├── ParseStage.php
│   │       ├── ClassifyTransactionType.php
│   │       ├── PaymentTypeClassifierStage.php
│   │       └── FingerprintStage.php
│   ├── Parsers/
│   │   ├── Asn/
│   │   │   ├── Camt053Parser.php
│   │   │   ├── Mt940Parser.php
│   │   │   ├── CsvParser.php
│   │   │   └── (matching PaymentTypeHinter classes)
│   │   ├── Ics/
│   │   │   ├── PdfParser.php
│   │   │   └── IcsPdfPaymentTypeHinter.php
│   │   ├── Paypal/
│   │   │   ├── CsvParser.php
│   │   │   └── PaypalCsvPaymentTypeHinter.php
│   │   └── DescriptionKeywordFallbackHinter.php
│   ├── Detectors/             (4 starting-balance detectors)
│   ├── Services/
│   │   ├── KnownCounterpartyIbanResolver.php
│   │   ├── AliasYamlExporter.php
│   │   ├── AliasYamlImporter.php
│   │   └── LongestCommonPrefix.php
│   ├── Listeners/
│   │   ├── HandleFileOpenedFromOs.php
│   │   └── SeedDefaultKnownCounterpartyIbans.php
│   └── Http/Livewire/
│       ├── UploadWizard.php
│       ├── PreviewWizard.php
│       ├── ImportResults.php
│       ├── RenameCounterpartyPopover.php
│       └── AliasesSettingsPage.php
├── Database/
│   ├── Migrations/
│   │   ├── 2026_05_26_000003_add_payment_type_to_transactions.php
│   │   ├── 2026_05_26_000004_create_merchant_aliases_table.php
│   │   └── 2026_05_27_010001_create_known_counterparty_ibans_table.php
│   └── Seeders/
│       └── DefaultKnownCounterpartyIbansSeeder.php
├── Routes/
│   ├── web.php
│   └── console.php
├── Resources/views/
├── Providers/
│   └── ImportServiceProvider.php
└── tests/
```

## Public API

- **Contracts/**
  - `RunsImports::preview(SplFileInfo $file, SourceFormat $format,
    User $user): ImportPreviewDto`.
  - `ConfirmsImports::confirm(string $previewId, User $user):
    ImportRunResultDto`.
  - `NamesAccounts::nameAccount(string $iban, string $name, User
    $user): Account`.
  - `AppliesEnrichments::apply(list<Enrichment> $enrichments, User
    $user): void`.
  - `PaymentTypeHinter::hint(CanonicalTransaction $tx):
    ?PaymentType` — tag `import.payment_type_hinter`.
  - `DetectsStartingBalance::detect(SplFileInfo $file):
    list<StartingBalanceDto>` — tag `starting-balance.detector`.
  - `ResolvesKnownCounterpartyIban::resolveFor(string $iban, User
    $user): ?Account`.
- **Actions/**
  - `RunImport`, `ConfirmImport`, `ApplyEnrichments` — the three
    sanctioned write paths.
  - `CreateMerchantAlias::__invoke($pattern, $friendlyName, $user)`,
    `MergeMerchantAliases::__invoke($sourceId, $targetId, $user)`.
- **DTOs/** — preview row, result row, hint payloads, starting
  balance, enrichment.
- **Enums/** — `SourceFormat::Asn_Camt053`, `Asn_Mt940`,
  `Asn_Csv`, `Ics_Pdf`, `Paypal_Csv`; `PaymentType` covering
  the documented payment-type taxonomy.
- **Events/**
  - `TransactionImported` — `(transactionId, userId,
    sourceFormat, importRunId)`. One per persisted row.

## Internal services

- `Internal/Pipeline/ImportPipeline::preview(...)` — orchestrator.
- `Internal/Pipeline/PreviewCache` — Laravel cache wrapper with
  the JSON-only DTO round-trip used by the wizard.
- `Internal/Pipeline/Stages/ParseStage::parse(...)` — delegates to
  the per-source parser based on the `SourceFormat`.
- `Internal/Pipeline/Stages/ClassifyTransactionType::classify(...)`
  — assigns the `type` (e.g. `expense`, `income`, `transfer_in`,
  `transfer_out`, `payment_to_merchant`).
- `Internal/Pipeline/Stages/PaymentTypeClassifierStage::__invoke(...)`
  — runs every tagged `PaymentTypeHinter` in order; first hit
  wins.
- `Internal/Pipeline/Stages/FingerprintStage::classify(...)` —
  computes the v3 fingerprint used for dedup.
- `Internal/Parsers/Asn/*` — CAMT.053 (genkgo/camt), MT940
  (kingsquare/php-mt940), CSV (league/csv) for ASN bank exports.
- `Internal/Parsers/Ics/PdfParser` — Mijn ICS consumer-portal PDF
  parser (the project's user is on the consumer portal — PDF-only,
  no CSV export).
- `Internal/Parsers/Paypal/CsvParser` — PayPal Activity Download
  CSV (league/csv).
- `Internal/Detectors/*` — four starting-balance detectors
  (CAMT.053 / MT940 / ICS PDF / PayPal CSV).
- `Internal/Services/KnownCounterpartyIbanResolver` — concrete
  resolver.
- `Internal/Services/AliasYamlExporter::export($user)` /
  `AliasYamlImporter::import($yaml, $user)` — bulk-edit surface.
- `Internal/Services/LongestCommonPrefix::find($strings)` — pure
  function used by the alias UI to suggest a generalised pattern.
- `Internal/Listeners/HandleFileOpenedFromOs::handle($event)` —
  filters by `.csv` extension; persists into `Desktop`'s pending
  intent store.
- `Internal/Listeners/SeedDefaultKnownCounterpartyIbans::handle($event)`
  — seeds two rows on `UserInstalled`.

## Models + migrations

The module's domain models are owned by other modules
(`Account`, `Transaction` live in [`Ledger`](../ledger/code.md)); the
own-owned tables:

- `merchant_aliases` — created by
  `2026_05_26_000004_create_merchant_aliases_table.php`. Per-user
  pattern → friendly-name + generalized_pattern.
- `known_counterparty_ibans` — created by
  `2026_05_27_010001_create_known_counterparty_ibans_table.php`.
  Per-user institution-IBAN → account-id alias.
- `transactions.payment_type` column added by
  `2026_05_26_000003_add_payment_type_to_transactions.php`.

## Provider wiring

`ImportServiceProvider::register()`:

- Binds the six Public contracts to their default implementations.
- Singletons every pipeline collaborator, the alias-resolution
  chain, every Public action, and the seeder.
- Tag-loops `PAYMENT_TYPE_HINTER_FQNS` under
  `import.payment_type_hinter` and
  `STARTING_BALANCE_DETECTOR_FQNS` under
  `starting-balance.detector`. Each FQN is gated by
  `class_exists()` so a missing class skips gracefully.
- Wraps the two registry consumers
  (`PaymentTypeClassifierStage`, `DetectStartingBalancesQuery`)
  in factory closures that pull `$app->tagged(...)` so the
  classifier / aggregator never names a concrete hinter / detector
  FQN.

`ImportServiceProvider::boot()`:

- Loads migrations, web/console routes, views.
- Registers five Livewire components under the `import.*`
  namespace.
- Subscribes `HandleFileOpenedFromOs` to
  `Desktop::FileOpenedFromOs` (extension filter is inside the
  listener).
- Subscribes `SeedDefaultKnownCounterpartyIbans` to
  `Core::UserInstalled`.
