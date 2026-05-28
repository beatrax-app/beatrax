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
  - The cross-user 404 posture on every action.

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
vendor/bin/pest Modules/Import/tests/Unit/Parsers/Asn

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
