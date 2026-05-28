# `Transfers` — how to test

Practical recipes for exercising the `Transfers` module in
isolation.

## Unit tests

- **Location:** `Modules/Transfers/tests/Unit/`
- **What they test:** `TransferPairerOrphanSweepTest` — the
  bulk sweep path picks the right partner from a candidate
  set that includes legitimate pair candidates + already-
  paired rows + cross-user noise.

## Feature tests

- **Location:** `Modules/Transfers/tests/Feature/`
- **What they test:**
  - `PairLookupTest` — the read-side query under various
    paired / unpaired / cross-user states.
  - `PairTransferCandidatesTest` — the per-row listener
    end-to-end (firing on `TransactionImported`,
    bidirectional write).
  - `PairTransferCandidatesAliasBridgeTest` — the
    reverse-direction IBAN reconciliation via
    `Import::ResolvesKnownCounterpartyIban`.

## Contract / arch invariants

- The repo-wide
  `noPairTransactionIdWritesOutsideTransferPairer` — only
  `Internal\Services\TransferPairer` may write
  `transactions.pair_transaction_id`. Any other writer is a
  bypass that breaks the dedup-on-pair guarantee.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Transfers/tests

# Just the alias-bridge reverse direction
vendor/bin/pest Modules/Transfers/tests/Feature/PairTransferCandidatesAliasBridgeTest.php

# Stop on first failure
vendor/bin/pest Modules/Transfers/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A self-transfer not pairing at import time** — walk the
  match rules in order: same user, equal-and-opposite
  amount, same currency, ±WINDOW_DAYS, both legs typed
  `transfer_in` / `transfer_out`, neither already paired.
  The most common cause is a currency mismatch (one leg
  carries EUR, the other carries the same EUR amount but
  was imported with `currency = NULL` because of a parser
  bug); inspect both legs' `currency` columns.
- **A re-imported row pairing a second time** — should not
  happen; the dedup at `RecordsTransactions::record` means
  the second import doesn't fire `TransactionImported` for
  the dedupped row. If you see a second pair attempt,
  fingerprint dedup failed upstream — investigate the
  fingerprint inputs.
- **The bulk orphan-sweep not picking up a pair the per-row
  listener missed** — the sweep runs AFTER
  `Chains::RetypeByAliasResolver` (the resolver order in
  `ResolveChainLinksJob::handle` is load-bearing). Confirm
  the re-type ran (check the matching transactions's `type`
  column after the chain job).
- **`PairLookup::partnerFor` returns null but the
  `pair_transaction_id` column on the row is populated** —
  the partner row was deleted (cascadeOnDelete from the
  user side wiped it). The lookup returns null because the
  joined row is missing; the column FK target is stale.
- **The alias-bridge reverse direction not firing** — the
  partner's IBAN must resolve through
  `Import::ResolvesKnownCounterpartyIban` to the user's own
  account. Confirm `known_counterparty_ibans` has the right
  row (the `SeedDefaultKnownCounterpartyIbans` listener
  seeds two default rows; user-specific rows may need to
  be added manually for non-default institutions).
