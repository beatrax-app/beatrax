# `Import` — specs

The behavioural contract for the `Import` module.

## Behavioral contracts

- **The preview phase writes nothing to the database.** Every
  stage produces an in-memory canonical row; the
  `PreviewCache` is the only persistence and it's a JSON-only
  cache keyed by preview id.
- **The confirm phase is the SOLE sanctioned dispatcher of the
  Chains resolver.** Inside `ConfirmImport`, AFTER the outer
  DB transaction commits, `UpsertsCardStatements::upsert` runs
  first, then `DispatchesChainResolution::dispatch`. An in-
  transaction dispatch would let the worker see stale state.
- **`RunImport` and `ConfirmImport` are idempotent on re-runs
  by fingerprint.** The pipeline's `FingerprintStage` produces
  a v3 fingerprint that the persistence layer keys on; a re-
  imported file produces zero new rows and an empty
  `ImportRunResultDto`.
- **The `PaymentTypeClassifierStage` first-match-wins rule.**
  The tagged hinters are ordered: per-source hinters first
  (CAMT.053 → MT940 → ASN CSV → ICS PDF → PayPal CSV), then
  `DescriptionKeywordFallbackHinter` last. The "fallback is
  last" invariant is asserted by the registry test.
- **`DetectStartingBalancesQuery` returns the first non-empty
  detector's result.** CAMT.053 first (canonical), MT940 second
  (legacy), ICS PDF third, PayPal CSV last (always declines).
- **`KnownCounterpartyIbanResolver` is the SOLE sanctioned
  reader of `known_counterparty_ibans`.** No other module / no
  other class queries the table directly; cross-module callers
  inject the contract.
- **The seed listener is idempotent.** Re-dispatching
  `UserInstalled` does not duplicate institution-IBAN aliases;
  `UNIQUE(user_id, institution_iban)` is the schema-level
  guard.
- **`HandleFileOpenedFromOs` only consumes `.csv` paths.**
  Other extensions pass through to whichever subscriber owns
  them ([`Receipts`](../receipts/specs.md) handles `.eml` /
  `.mbox`). The listener's extension filter is the gate.
- **`TransactionImported` fires once per persisted row.** Not
  once per file. Subscribers (`Desktop::DispatchOsNotification`)
  see one event per transaction the user confirmed.
- **A new payment-type hinter ships by adding the FQN to the
  provider constant and shipping the class.** The classifier
  stage and pipeline binding do not change; the registry test
  asserts the new hinter appears at the documented position.
- **A pending enrichment that strengthens a row's `source_ref`
  appends to `enriched_from` rather than overwriting.** The
  full provenance chain survives so a re-import with a
  different source format can show "first observed as
  ASN-CSV, enriched by PayPal-CSV receipt later".

## Edge cases

- **A `.csv` drop while no user is logged in** —
  `HandleFileOpenedFromOs` stores the path in
  `Desktop::PendingFileIntent`; the user logs in;
  `ContinuePendingFileIntentAfterLogin` (in `Desktop`)
  redirects to `/desktop/file-staging` which routes the file
  into `UploadWizard`.
- **A user uploading an empty file** — the parser returns an
  empty canonical-row list; the preview shows "0 transactions";
  confirm is a no-op.
- **Two imports racing on the same file** — both produce the
  same v3 fingerprints; the persistence layer's dedup keeps
  exactly one row per fingerprint.
- **A merchant alias whose pattern conflicts with an existing
  alias** — `CreateMerchantAlias` raises a friendly validation
  error; the schema's UNIQUE constraint is the second-layer
  guard.
- **A known-counterparty-IBAN whose target Account was
  deleted** — the resolver returns `null`; the calling chain
  resolver treats the row as if the bridge is absent.
- **A starting-balance detector returning a non-empty list
  for a file the user actually expected to be empty** — the
  aggregator returns the first non-empty list; if CAMT.053
  + MT940 disagree, CAMT.053 wins by registration order.
- **A pending enrichment for a transaction that was deleted** —
  `ApplyEnrichments` is a no-op for missing target rows; the
  enrichment is dropped, logged, and the import continues.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/specs.md) — `User`, `Clock`,
    `UserInstalled` event.
  - [`Ledger`](../ledger/specs.md) — `RecordsTransactions`
    contract, `Account` / `Transaction` models,
    `CanonicalTransaction` DTO,
    `FingerprintComposer`.
  - [`Ingestion`](../ingestion/specs.md) — `NormalizeStage`,
    source-adapter DTOs.
  - [`Categorization`](../categorization/specs.md) —
    `AppliesAutoCategory` contract injected as a pipeline
    stage.
  - [`Counterparties`](../counterparties/specs.md) —
    `ResolvesCounterparties` contract injected as a pipeline
    stage.
  - [`Chains`](../chains/specs.md) — `UpsertsCardStatements`
    and `DispatchesChainResolution` contracts invoked
    post-commit by `ConfirmImport`.
  - [`Receipts`](../receipts/specs.md) — pending enrichments
    written by `RecordReceipt` are consumed by `ApplyEnrichments`.
  - [`Desktop`](../desktop/specs.md) — `FileOpenedFromOs`
    event + `PendingFileIntent` store.
  - [`Community`](../community/specs.md) — the import preview
    reads `CommunityCorpusQuery` for suggested names.
- **Depended on by**
  - Every domain module that needs to suggest a payment type
    (a future module ships a `PaymentTypeHinter`).
  - [`Counterparties`](../counterparties/specs.md) — consumes
    `MerchantNameResolver` and `ResolvesKnownCounterpartyIban`.

## Configuration + feature flags

- The `import.payment_type_hinter` container tag — adding a
  hinter is a constant edit + a class ship.
- The `starting-balance.detector` container tag — same pattern.
- No env flag changes the pipeline's stages or ordering. The
  stage chain is intentional and lives in code, not in config.
- `users.auto_import_drop_folder` (per-user toggle, owned by
  `Core`'s migration) — when true, the watch-folder auto-trigger
  treats new files as a drop intent.
