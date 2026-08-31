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
│   │   ├── CapturesImportForSync.php
│   │   ├── PaymentTypeHinter.php
│   │   ├── DetectsStartingBalance.php
│   │   └── ResolvesKnownCounterpartyIban.php
│   ├── Actions/
│   │   ├── RunImport.php
│   │   ├── ConfirmImport.php
│   │   ├── ApplyEnrichments.php
│   │   ├── DiscardImport.php
│   │   ├── EnsurePaypalAccountAction.php
│   │   ├── EnsureGooglePlayAccountAction.php
│   │   ├── CreateMerchantAlias.php
│   │   └── MergeMerchantAliases.php
│   ├── Dto/                  (ImportPreviewResult, ImportConfirmResult,
│   │                          PreviewRowDto, PaymentTypeHint,
│   │                          StartingBalanceCandidate, dispositions)
│   ├── Enums/                (PaymentType, BankCsvFormatHint,
│   │                          ImportFailureReason, PreviewRowStatus,
│   │                          PreviewSectionStatus,
│   │                          EnrichmentConflictField)
│   ├── Events/
│   │   └── TransactionImported.php
│   ├── Pipeline/
│   │   └── NormalizeStage.php   (Receipts uses it without the chain)
│   └── Services/
│       ├── AccountDenomination.php
│       ├── AccountNamer.php
│       ├── AliasMatchPreviewQuery.php
│       ├── BuildConsolidatedPreviewQuery.php
│       ├── DetectStartingBalancesQuery.php
│       ├── EloquentAccountResolver.php
│       ├── MerchantNameResolver.php
│       ├── PatternGeneralizer.php
│       ├── SourceRefRanker.php
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
│   ├── Parsers/               (PaymentTypeHinter classes only —
│   │                           the readers live in Ingestion)
│   │   ├── Csv/PositionalCsvPaymentTypeHinter.php
│   │   ├── Banking/Camt053PaymentTypeHinter.php
│   │   ├── Banking/Mt940PaymentTypeHinter.php
│   │   ├── Ics/IcsPdfPaymentTypeHinter.php
│   │   ├── Paypal/PaypalCsvPaymentTypeHinter.php
│   │   ├── DescriptionKeywordHinter.php
│   │   ├── DutchNarrativeHinter.php
│   │   ├── DutchNarrativeKeywords.php
│   │   └── DescriptionKeywordFallbackHinter.php
│   ├── Detectors/             (4 tagged starting-balance detectors
│   │                           over a shared base class)
│   ├── Dto/                   (ImportRowIssue, PreviewSectionSummary)
│   ├── Enums/                 (ImportIssueKind)
│   ├── Exceptions/
│   ├── Sync/                  (NullImportSyncCapture — the bindIf
│   │                           default when Sync is absent)
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
  - `RunsImports::runFromUpload(string $localPath, string
    $sourceFormat, User $user, string $originalFilename,
    ?BankCsvFormatHint $formatHint = null): ImportPreviewResult`.
  - `RunsImports::runFromRemoteFetch(Generator $sourceRows, string
    $sourceFormat, User $user, string $idempotencyKey):
    ImportPreviewResult`.
  - `RunsImports::runAndConfirm(string $localPath, string
    $sourceFormat, User $user, string $originalFilename, ?BankCsvFormatHint
    $formatHint = null): ImportConfirmResult` — both phases in one
    call; the entrypoint the idempotency contract test drives.
  - `ConfirmsImports::__invoke(int $importRunId, User $user, bool
    $dispatchChain = true): ImportConfirmResult` — keyed on the
    import run id, not on a preview id.
  - `NamesAccounts::__invoke(string $iban, string
    $userSuppliedName, User $user): int` — the id of the Account
    row it created.
  - `AppliesEnrichments::__invoke(list<PendingEnrichment>
    $enrichments, User $user): int` — rows actually enriched,
    race-condition no-ops excluded.
  - `CapturesImportForSync::capture(ImportRun $importRun, User
    $user): void` — bound with `bindIf`, so a build without `Sync`
    still resolves the import path against a null implementation.
  - `PaymentTypeHinter::hint(CanonicalTransaction $tx, string
    $sourceFormat): ?PaymentTypeHint` — tag
    `import.payment_type_hinter`.
  - `DetectsStartingBalance::supports(string $sourceFormat): bool`
    plus `DetectsStartingBalance::detect(list<int> $importRunIds,
    User $user): list<StartingBalanceCandidate>` — tag
    `starting-balance.detector`. A detector is handed ImportRun ids
    and filters internally to its own format; it never re-reads the
    file.
  - `ResolvesKnownCounterpartyIban::resolveAccount(string $iban,
    int $userId): ?Account`.
- **Actions/**
  - `RunImport`, `ConfirmImport`, `ApplyEnrichments` — the three
    sanctioned write paths.
  - `DiscardImport::__invoke($importRunId, $user)` drops a run's
    cached preview; `EnsurePaypalAccountAction` creates the
    synthetic PayPal account the Onboarding connector step needs
    before it can stage a run, and
    `EnsureGooglePlayAccountAction::__invoke($user, ?$nameOverride,
    ?$statementCurrency)`
    is its Google Play twin — the only thing in the app that mints
    an account on the `'GOOGLE-PLAY'` sentinel, which is what a
    parsed Play receipt resolves against. Both hold their sentinel
    as a constant reading `Ingestion`'s `SyntheticIban` enum.
  - `CreateMerchantAlias::__invoke($pattern, $friendlyName, $user)`,
    `MergeMerchantAliases::__invoke($sourceId, $targetId, $user)`.
  - `AccountDenomination::forStatement(?$statementCurrency)` is the one
    place an account's denomination is decided: the file's own currency,
    and the reader's reporting currency only when the file names none —
    [an account is denominated by its statement](an-account-is-denominated-by-its-statement.md).
- **Dto/** — `ImportPreviewResult`, `ImportConfirmResult`,
  `PreviewRowDto`, `PaymentTypeHint`, `StartingBalanceCandidate`,
  `PendingEnrichment`, `UnknownIban`, `AliasMatchPreviewResultDto`,
  the `FingerprintStage` dispositions and the consolidated-preview
  batch/section pair.
- **Enums/** — `PaymentType` (`Pin`, `Online`, `Transfer`,
  `DirectDebit`, `Cash`, `Fee`, `Refund`, `Unknown`) covering
  the documented payment-type taxonomy; `BankCsvFormatHint`,
  `ImportFailureReason`, `PreviewRowStatus`,
  `PreviewSectionStatus`; `EnrichmentConflictField`
  (`counterparty_name` / `description` / `currency` /
  `amount_minor`), the closed set of `transactions` columns a
  receipt may disagree with a statement about, plus
  `isFingerprintInput()` naming the subset the dedup tuple is
  composed over. `FingerprintStage` emits its conflict keys from
  it, `ApplyEnrichments` accepts them through it, and
  `Receipts\Public\Actions\ApplyReceiptConflictResolution`
  resolves them through it.
  `ImportRunStatus` is not one of them either: the `import_runs`
  table is `Ledger`'s, so the enum naming its states lives at
  `Modules\Ledger\Public\Enums\ImportRunStatus`.
  `SourceFormat` is NOT one of them, and never was. It belongs
  to `Ingestion` (`Modules\Ingestion\Public\Enums\SourceFormat`)
  — the module that owns the adapters the value selects — and
  stands in for the bare strings the adapter registry, the
  header profiles, the payment-type hinters and the
  starting-balance detectors would otherwise key on. Its cases are
  `AsnCsv`, `Camt053`, `Mt940`, `IcsPdf`, `PaypalCsv`, `Eml`,
  `Mbox` — every one of which keys either the adapter registry
  or `ParseStage`'s receipt arm, an invariant
  `tests/Contracts/OfferedFormatsResolveToAParserArchTest.php`
  holds. The `source_format` column stays open — a CSV preset
  registers its own format at runtime, so a value absent from
  the enum is a preset, not an error. A bank whose CSV a preset
  reads is named only by that preset's format id, which is why
  ING is `ing-nl-csv` and not an enum case.
- **Events/**
  - `TransactionImported` — carries `(Transaction $transaction,
    User $user)`, and is dispatched by `Ledger`'s
    `RecordTransactions` once per row actually inserted, after
    that chunk commits — never for a duplicate or an enrichment.
    `Anomaly`, `Receipts`, `Transfers` and `Search` subscribe.

## Internal services

- `Internal/Pipeline/ImportPipeline::preview($localPath,
  $sourceFormat, $accounts, $user, $importRunId, $formatHint =
  null)` — orchestrator. It returns an array, not a DTO;
  `RunImport` wraps that into an `ImportPreviewResult`.
  `ImportPipeline::previewFromGenerator($sourceRows, $sourceFormat,
  $accounts, $user, $importRunId)` is the connector-fed twin, with
  no format hint because a fetched feed has no CSV layout to
  disambiguate.
- `Internal/Pipeline/PreviewCache` — Laravel cache wrapper with
  the JSON-only DTO round-trip used by the wizard, keyed by the
  import run id on a 30-minute TTL.
  `PreviewCache::sectionSummary($importRunId, $sampleLimit)` is the
  small per-run entry the consolidated screen renders from without
  reading the row set back.
- `Internal/Pipeline/Stages/ParseStage::run($localPath,
  $sourceFormat, $accounts, $user = null)` — a generator of
  `SourceTransactionDto`, delegating to `Ingestion`'s
  `SourceAdapterRegistry`, or to the receipt arm for the `eml` and
  `mbox` formats.
- `Internal/Pipeline/Stages/ClassifyTransactionType::run($tx,
  $user)` — assigns the `type` (`expense`, `income`,
  `transfer_in`, `transfer_out`, and the terminal `refund` / `fee`
  / `adjustment` it leaves alone).
- `Internal/Pipeline/Stages/PaymentTypeClassifierStage::run($tx,
  $user, $sourceFormat)` — runs every tagged `PaymentTypeHinter`
  in order; first hit wins.
- `Internal/Pipeline/Stages/FingerprintStage::classify($tx, $user)`
  — computes the v3 fingerprint used for dedup and answers with
  the row's disposition (new / duplicate / enriched).
- `Internal/Parsers/*` — per-source `PaymentTypeHinter`
  implementations **only**. The readers themselves are
  `Ingestion`'s `SourceAdapter` implementations
  (`Camt053Adapter`, `Mt940Adapter`, `GenericCsvAdapter`,
  `PositionalCsvAdapter`, `IcsPdfAdapter`, `PaypalCsvAdapter`); the
  directory kept its name from when this module owned both.
- `Internal/Detectors/*` — four tagged starting-balance detectors
  (CAMT.053 / MT940 / ICS PDF / PayPal CSV) over the shared
  `StatementSummaryStartingBalanceDetector` base class.
- `Internal/Services/KnownCounterpartyIbanResolver::resolveAccount(
  $iban, $userId)` — concrete resolver; the sole reader of
  `known_counterparty_ibans`.
- `Internal/Services/AliasYamlExporter::export($user)` and the
  three-step importer — `AliasYamlImporter::parse($yamlContent)`,
  `AliasYamlImporter::diff($user, $entries)`,
  `AliasYamlImporter::apply($user, $entries,
  $conflictResolutions)` — the bulk-edit surface. There is no
  single `import()` call: parse and diff are what let the UI show
  conflicts before anything is written.
- `Internal/Services/LongestCommonPrefix::compute($patterns)` —
  pure function used by the alias merge dialog to prefill a
  pattern. Throws on fewer than two inputs and returns `''` when
  the shared prefix is under four characters.
- `Internal/Listeners/HandleFileOpenedFromOs::handle($event)` —
  filters by `.csv` extension; persists into `Desktop`'s pending
  intent store.
- `Internal/Listeners/SeedDefaultKnownCounterpartyIbans::handle($event)`
  — seeds two rows on `UserInstalled`.
- `Internal/Http/Livewire/ImportResults` — the screen the confirm
  redirects to. Besides the skipped/error lists it carries the
  chain-resolution progress surface, read from
  `chain_resolution_runs` by exact `user_id` inside `render()` and
  polled while the run is `pending` or `running`. See
  [chain resolution progress on the results page](architecture.md#chain-resolution-progress-on-the-results-page).

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
