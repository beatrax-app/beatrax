# `Import` — how to test

Practical recipes for exercising the `Import` module in isolation.

## Unit tests

- **Location:** `Modules/Import/tests/Unit/` (when present)
- **What they test:** each per-source parser against representative
  fixture files (live exports, not synthetic rows — the project's
  fixture policy); the `PaymentTypeClassifierStage` against ordered
  hinter lists; each individual `PaymentTypeHinter` for its source's
  rows; the `LongestCommonPrefix` pure function; the
  `PatternGeneralizer` output shape; the
  `KnownCounterpartyIbanResolver` user-scoped reads.

## Feature tests

- **Location:** `Modules/Import/tests/Feature/`
- **What they test:**
  - The preview phase end-to-end per source format (assert no DB
    writes; assert the preview cache key returns).
  - The confirm phase end-to-end (assert rows persisted; assert
    `TransactionImported` fired the expected count; assert chain
    dispatcher fired AFTER the outer transaction committed).
  - The `RunImport` / `ConfirmImport` idempotency on re-run
    against the same fixture.
  - The `HandleFileOpenedFromOs` extension filter (`.csv`
    handled here; other extensions pass through).
  - The seed listener idempotence on `UserInstalled` re-dispatch.
  - The merchant-alias UI flows (`AliasesSettingsPage`, the
    YAML exporter / importer).
  - The pending-enrichment flow (`ApplyEnrichments` strengthens
    a row's `source_ref` and appends to `enriched_from`).
  - The starting-balance aggregator picking the first non-empty
    detector.
  - The denomination a newly named account is opened in, per format
    and against a reader reporting in a third currency
    (`AnImportedAccountIsDenominatedByItsStatementTest`) —
    [an account is denominated by its statement](an-account-is-denominated-by-its-statement.md).
  - The cross-user 404 posture on every action.
  - `OwnAccountPrompt` answering as if a run it was not handed by its
    owner does not exist — no prompt, no statement currency, and a
    write guard that stays closed
    (`AnOwnAccountPromptAnswersNothingForAForeignRunTest`). Called
    directly rather than through `PreviewWizard`, because the wizard's
    own mount assertion is exactly what the class must not depend on.

## Integration tests

- **Location:** `Modules/Import/tests/Integration/` (when present)
- **What they test:** the full pipeline against a realistic
  multi-source month (ASN CAMT + PayPal CSV + ICS PDF imported
  in succession); the chain dispatcher firing exactly once
  per import; the cross-source dedup via the v3 fingerprint.

## Contract / arch invariants

- The repo-wide
  `noKnownCounterpartyIbansReadsOutsideResolver` — only
  `KnownCounterpartyIbanResolver` may query the table.
- The repo-wide `paymentTypeHinterRegistryFallbackIsLast` —
  asserts the `DescriptionKeywordFallbackHinter` is the last
  hinter registered under the
  `import.payment_type_hinter` tag.
- The repo-wide `startingBalanceDetectorRegistryOrdering` —
  asserts CAMT.053 first, MT940 second, ICS PDF third,
  PayPal CSV last under the `starting-balance.detector`
  tag.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Import/tests

# Just one source's parser
vendor/bin/pest Modules/Import/tests/Unit/PositionalCsvPaymentTypeHinterTest.php

# Just the preview/confirm cycle
vendor/bin/pest Modules/Import/tests/Feature --filter "PreviewConfirm"

# Stop on first failure
vendor/bin/pest Modules/Import/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A row that should have dedupped imports as a duplicate** —
  the v3 fingerprint inputs include normalised counterparty,
  posted-at, settled-at, amount minor, account id, source format.
  A change in any input produces a different fingerprint; the
  most common cause is a parser update normalising the
  counterparty differently across two runs. Compare the two
  fingerprints in `transactions.fingerprint`.
- **A payment type hinter not firing** — the registry order
  matters: source-specific hinters run before the fallback.
  Confirm the hinter is in `PAYMENT_TYPE_HINTER_FQNS` AND that
  `class_exists()` returns true at boot AND that the registry
  test passes. The `import.payment_type_hinter` tag is the
  observable surface.
- **`HandleFileOpenedFromOs` not picking up a `.csv` drop** —
  the listener is gated by extension. The drop path's path
  must end in `.csv`; the OS-supplied path is the literal one,
  so a `.CSV` (uppercase) is not handled today (add to the
  filter if needed). The `Receipts` listener owns `.eml` /
  `.mbox` drops.
- **The chain dispatcher fired but the resolver never ran** —
  confirm the queue worker is running (the dispatcher only
  enqueues). Tail `/dev/queue` for the
  `ResolveChainLinksJob`; tail `chain_resolution_runs` for the
  audit row.
- **A merchant alias accepted in the UI but not consulted by
  the resolver** — `MerchantNameResolver` walks five steps:
  per-user exact → per-user generalised → community exact →
  community generalised → null. A per-user exact match wins
  over every other; a per-user generalised match beats every
  community match. If the alias is per-user exact and still
  not winning, the canonical-row's description does not
  exactly match the alias pattern (check whitespace, case).
- **The preview cache returns 404 on confirm** — the cache
  TTL is short; the user may have left the wizard open past
  the TTL. Re-running the preview produces a fresh key.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

- **The preview phase writes no transactions.** Every stage
  produces an in-memory canonical row and the rows themselves go
  only to `PreviewCache`, a JSON-only cache keyed by the
  **import run id** with a 30-minute TTL. Two things are still
  written: the `import_runs` row the phase is keyed on, and the
  statement summary the adapter reports through
  `RecordsStatementSummary`. Nothing reaches `transactions`
  until confirm.
- **The confirm phase is the SOLE sanctioned dispatcher of the
  Chains resolver.** Inside `ConfirmImport`, AFTER the outer
  DB transaction commits,
  `UpsertsCardStatements::upsertForImportRun` runs first, then
  `DispatchesChainResolution::dispatchForUser`. An in-
  transaction dispatch would let the worker see stale state.
  The two are not gated alike: the upsert runs on every
  confirm, the dispatch only when the run inserted or enriched
  at least one row. A re-import of an all-duplicate file must
  still heal a deleted `card_statements` row, but has nothing
  new for the resolver to chase.
- **`RunImport` and `ConfirmImport` are idempotent on re-runs
  by fingerprint.** The pipeline's `FingerprintStage` produces
  a v3 fingerprint that the persistence layer keys on; a re-
  imported file produces zero new rows and an
  `ImportConfirmResult` reporting 0 inserted. A re-upload whose
  SHA256 already belongs to a confirmed run short-circuits
  earlier still, returning an `ImportPreviewResult` with no rows
  rather than re-parsing.
- **The `PaymentTypeClassifierStage` first-match-wins rule.**
  The tagged hinters are ordered: per-source hinters first
  (CAMT.053 → MT940 → ASN CSV → ICS PDF → PayPal CSV), then
  `DescriptionKeywordFallbackHinter` last. The "fallback is
  last" invariant, the count and the whole order are asserted by
  `Modules/Import/tests/Feature/PaymentTypeHinterRegistryTest.php`.
- **`DetectStartingBalancesQuery` returns the first non-empty
  detector's result.** CAMT.053 first (canonical), MT940 second
  (legacy), ICS PDF third, PayPal CSV last (always declines).
- **`KnownCounterpartyIbanResolver` is the SOLE sanctioned
  reader of `known_counterparty_ibans`.** No other module / no
  other class queries the table directly; cross-module callers
  inject the contract.
- **The seed listener is idempotent.** Re-dispatching
  `UserInstalled` does not duplicate institution-IBAN aliases;
  `UNIQUE(user_id, real_iban)` is the schema-level
  guard.
- **`HandleFileOpenedFromOs` only consumes `.csv` paths.**
  Other extensions pass through to whichever subscriber owns
  them ([`Receipts`](../receipts/how-to-test.md) handles `.eml` /
  `.mbox`). The listener's extension filter is the gate.
- **`TransactionImported` fires once per persisted row.** Not
  once per file, and never for a duplicate or an enrichment. It
  is dispatched by `Ledger`'s `RecordTransactions` after each
  chunk commits, carrying the persisted `Transaction` and the
  `User`. Subscribers are `Anomaly`, `Receipts`, `Transfers` and
  `Search`; OS notifications are **not** among them — `Desktop`
  raises those off `NotificationDeliverable` instead.
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
  empty canonical-row list; the preview says the file holds no
  transactions and "Confirm import" is disabled, in the wizard
  and in `PreviewWizard::confirm()` behind it. There is nothing
  to confirm, and confirming it used to write a confirmed run
  reporting zero of everything.
- **A file the chosen parser cannot read at all** — a failure,
  not a row. `ImportPreviewResult::$fileFailureReason` carries
  it and the preview says so, naming the likely cause (a header
  row that does not match the chosen source) and the parser's
  own words where the exception declared them free of user
  data. Reported as a row it rendered as a table row of
  em-dashes above an enabled confirm button.
- **A file that reads until one row stops it** — the rows before
  the stop are present and the rest are absent, not present and
  failed. The preview says only part of the file was read, gives
  the count that did arrive, and still offers to confirm those.
  See `tests/fixtures/asn-partial-failure.csv`.
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
  - MT940 disagree, CAMT.053 wins by registration order.
- **A pending enrichment for a transaction that was deleted** —
  `ApplyEnrichments` is a no-op for missing target rows; the
  enrichment is dropped, logged, and the import continues.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`,
    `UserInstalled` event.
  - [`Ledger`](../ledger/how-to-test.md) — `RecordsTransactions`
    contract, `Account` / `Transaction` models,
    `CanonicalTransaction` DTO,
    `FingerprintComposer`.
  - [`Ingestion`](../ingestion/how-to-test.md) — `NormalizeStage`,
    source-adapter DTOs.
  - [`Categorization`](../categorization/how-to-test.md) —
    `AppliesAutoCategory` contract injected as a pipeline
    stage.
  - [`Counterparties`](../counterparties/how-to-test.md) —
    `ResolvesCounterparties` contract injected as a pipeline
    stage.
  - [`Chains`](../chains/how-to-test.md) — `UpsertsCardStatements`
    and `DispatchesChainResolution` contracts invoked
    post-commit by `ConfirmImport`.
  - [`Receipts`](../receipts/how-to-test.md) — pending enrichments
    written by `RecordReceipt` are consumed by `ApplyEnrichments`.
  - [`Desktop`](../desktop/how-to-test.md) — `FileOpenedFromOs`
    event + `PendingFileIntent` store.
  - [`Community`](../community/how-to-test.md) — the import preview
    reads `CommunityCorpusQuery` for suggested names.
- **Depended on by**
  - Every domain module that needs to suggest a payment type
    (a future module ships a `PaymentTypeHinter`).
  - [`Counterparties`](../counterparties/how-to-test.md) — consumes
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
