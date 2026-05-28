# `Import` — architecture

The `Import` module is the orchestrator that takes a user-supplied
file (or an OS-opened drop) and walks it through the
preview-then-confirm wizard into the canonical ledger. It owns the
`ImportPipeline` stage chain, the per-source `PaymentTypeHinter` and
`StartingBalanceDetector` registries (tag-discovered), the
merchant-alias surface, the institution-IBAN alias bridge, and the
post-commit dispatch boundary that wakes up `Chains`.

## What this module is for

The detailed cross-cutting design lives in the
[ingestion-pipeline architecture topic](../../architecture/ingestion-pipeline.md);
this page describes the module's surface. The user uploads / drops a
file; the module previews it (no DB writes); the user confirms; the
pipeline persists through `Ledger`'s sole sanctioned writer; the chain
job dispatches.

What the module explicitly does NOT do:

- It never persists transactions itself. The pipeline ends at
  `RecordsTransactions` (Ledger's contract); this module never
  writes to `transactions`.
- It never reaches into another module's internals to consume a
  payment-type hint or starting-balance detector. The container
  tags (`import.payment_type_hinter`,
  `starting-balance.detector`) are how new hinters / detectors
  ship — append the FQN to a constant in the provider, add the
  class, the registry picks it up.
- It never re-resolves a previously-resolved counterparty IBAN
  without a documented reason. The `KnownCounterpartyIbanResolver`
  is the single sanctioned reader of the
  `known_counterparty_ibans` table.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `RunsImports::preview($file, $sourceFormat, $user)` — the
    preview phase entry point (no DB writes; caches the canonical
    rows for the confirm step).
  - `ConfirmsImports::confirm($previewId, $user)` — the confirm
    phase entry point (persists through Ledger; dispatches Chains).
  - `NamesAccounts::nameAccount($iban, $name, $user)` — the
    "name this unknown IBAN" hook used inline in the wizard.
  - `AppliesEnrichments::apply($enrichments, $user)` — re-imports
    that produce stronger `source_ref` values.
  - `PaymentTypeHinter::hint($tx)` — per-source hinter contract;
    discovered through the `import.payment_type_hinter` tag.
  - `DetectsStartingBalance::detect($file)` — per-source detector
    contract; discovered through the `starting-balance.detector`
    tag.
  - `ResolvesKnownCounterpartyIban::resolveFor($iban, $user)` —
    the bridge between an institution IBAN (PayPal Luxembourg, ICS
    at ABN AMRO) and the user's synthetic-IBAN account.
- **Actions/**
  - `RunImport` (impl. `RunsImports`).
  - `ConfirmImport` (impl. `ConfirmsImports`).
  - `ApplyEnrichments` (impl. `AppliesEnrichments`).
  - `CreateMerchantAlias`, `MergeMerchantAliases`.
- **Pipeline/**
  - `ResolvesCounterparties` lives in
    [`Counterparties`](../counterparties/architecture.md); the
    pipeline injects the contract.
- **DTOs/** — `ImportPreviewDto`, `ImportRunResultDto`,
  `PaymentTypeHintDto`, `StartingBalanceDto`, etc.
- **Enums/** — `SourceFormat`, `PaymentType`,
  `ImportRunStatus`.
- **Events/**
  - `TransactionImported` — raised by `ConfirmImport` per row
    after persist. Consumed by `Desktop` for OS notifications.
- **Services/**
  - `AccountNamer` (impl. `NamesAccounts`).
  - `MerchantNameResolver` — five-step matcher (per-user exact →
    per-user generalised → community exact → community generalised
    → null) consumed by `Counterparties`.
  - `PatternGeneralizer` — produces the generalised pattern for
    a raw description string.
  - `AliasMatchPreviewQuery`,
    `BuildConsolidatedPreviewQuery`,
    `DetectStartingBalancesQuery`.

`Internal/` houses the implementation:

- **Internal/Pipeline/ImportPipeline** — orchestrates the stage
  chain.
- **Internal/Pipeline/PreviewCache** — JSON-only cache of preview
  rows.
- **Internal/Pipeline/Stages/** — `ParseStage`,
  `ClassifyTransactionType`, `PaymentTypeClassifierStage`,
  `FingerprintStage`. The other stages
  (`NormalizeStage`, `ApplyAutoCategoryStage`,
  `ResolveCounterpartyStage`, `RecordsStatementSummary`) are
  owned by their respective modules and injected here.
- **Internal/Parsers/** — per-source parsers (`Asn/Camt053`,
  `Asn/Mt940`, `Asn/Csv`, `Ics/Pdf`, `Paypal/Csv`). Each ships
  its own `PaymentTypeHinter`.
- **Internal/Detectors/** — per-source starting-balance
  detectors.
- **Internal/Services/KnownCounterpartyIbanResolver** — concrete
  `ResolvesKnownCounterpartyIban`. Single sanctioned reader of
  `known_counterparty_ibans`.
- **Internal/Services/AliasYamlExporter / AliasYamlImporter** —
  bulk-edit surface for `/settings/aliases`.
- **Internal/Listeners/HandleFileOpenedFromOs** — filters
  `Desktop::FileOpenedFromOs` by `.csv` extension; routes the
  path into the wizard.
- **Internal/Listeners/SeedDefaultKnownCounterpartyIbans** —
  `UserInstalled` listener that seeds the two default
  institution-IBAN aliases.

## Key services + events

- `ImportPipeline::preview($file, $sourceFormat, $user)` —
  stages: parse → normalize → classify-transaction-type →
  payment-type → auto-category → counterparty-resolve →
  fingerprint. No DB writes; result cached.
- `ConfirmImport::confirm($previewId, $user)` — replays cached
  rows through `RecordsTransactions`; applies pending
  enrichments inside the same DB transaction; AFTER commit:
  `UpsertsCardStatements::upsert` then
  `DispatchesChainResolution::dispatch`.
- `KnownCounterpartyIbanResolver::resolveFor($iban, $user)` —
  reads `known_counterparty_ibans`; returns the user's matching
  `paypal` / `ics_card` Account (or null).
- `PaymentTypeClassifierStage` — collects tagged
  `PaymentTypeHinter` instances; first match wins; the
  description-keyword fallback is intentionally LAST so the
  registry test's "fallback is last" invariant holds.
- `DetectStartingBalancesQuery` — collects tagged
  `DetectsStartingBalance` detectors; returns the first
  non-empty list. CAMT.053 first (canonical), MT940 second
  (legacy), ICS PDF third, PayPal CSV last (always declines).
- `MerchantNameResolver::resolve($description, $user)` —
  five-step matcher consumed by `Counterparties::CounterpartyResolverService`.
- `HandleFileOpenedFromOs` — extension filter; persists into the
  `Desktop` pending-intent store.

## Data flow

The end-to-end import:

```
User uploads file (or drops a .csv onto the app)
  ├─ drop path: Desktop::FileOpenedFromOs → HandleFileOpenedFromOs
  │              → Desktop::PendingFileIntent
  │              → user logs in (if needed)
  │              → /desktop/file-staging → /imports/new
  └─ upload path: UploadWizard SFC

PreviewWizard
  → RunImport::preview($file, $sourceFormat, $user)
       → ImportPipeline::preview
            → ParseStage (per-source parser)
            → NormalizeStage (from Ingestion; injected)
            → ClassifyTransactionType
            → PaymentTypeClassifierStage (per-source hinters)
            → ApplyAutoCategoryStage (from Categorization)
            → ResolveCounterpartyStage (from Counterparties)
            → FingerprintStage
       → cache canonical rows under preview id
  → user reviews preview
  → ConfirmImport::confirm($previewId, $user)
       → BEGIN TX
            → for each cached row: RecordsTransactions::record
            → ApplyEnrichments::apply (pending source_ref strengthens)
       → COMMIT
       → UpsertsCardStatements::upsert  (Chains contract — Pre-Chain step)
       → DispatchesChainResolution::dispatch (Chains contract)
       → per row: dispatch TransactionImported
```

The institution-IBAN bridge seed:

```
UserInstalled
  → SeedDefaultKnownCounterpartyIbans
       → INSERT (PayPal Luxembourg → paypal kind)
       → INSERT (ICS at ABN AMRO → ics_card kind)
            both idempotent on UNIQUE(user_id, institution_iban)
```
