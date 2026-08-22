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
  - `PairLookupTest` — `isPaired` / `partnerId` under various
    paired / unpaired / cross-user states.
  - `CounterLegSearchTest` — the shared counter-leg search from
    both sides: the pairer's candidate set row by row (currency,
    already-paired, non-transfer type, either transfer
    direction, the self-pair id guard, the window edge), that
    the pairer links exactly the id `counterLegOnAccount`
    returns for the same ask, and that both orderings are total.
    The pin cases were written and run green against the
    hand-rolled query the forward arm used to carry, so a
    regression in WHICH leg pairs fails here.
    `counterLegOnAccount` is also covered from its other
    caller's side, in
    [`Chains`](../chains/how-to-test.md)'s
    `PaypalFundingCounterLegParityTest`.
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
- **`PairLookup::counterLegOnAccount` finds nothing while
  `PairLookup::partnerId` returns an id (or the reverse)** —
  expected, not a bug. They answer different questions:
  `partnerId` reads the persisted `pair_transaction_id`,
  `counterLegOnAccount` searches account + types + amount +
  date window, and only consults that column when the caller
  passed `unpairedOnly: true` (the pairer does, chain
  resolution does not).
- **Two callers of `counterLegOnAccount` disagreeing about
  which leg is right** — also expected. The predicates and the
  ordering are all required parameters; read the call site
  before assuming the query is wrong.
- **The alias-bridge reverse direction not firing** — the
  partner's IBAN must resolve through
  `Import::ResolvesKnownCounterpartyIban` to the user's own
  account. Confirm `known_counterparty_ibans` has the right
  row (the `SeedDefaultKnownCounterpartyIbans` listener
  seeds two default rows; user-specific rows may need to
  be added manually for non-default institutions).

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Transfers` module.

## Behavioral contracts

- **`TransferPairer` is deterministic.** Same inputs produce
  the same pairing decision; the matcher has no per-instance
  state and no time-of-day dependence beyond the
  `±WINDOW_DAYS` calendar comparison.
- **The matcher is the SOLE sanctioned writer of
  `transactions.pair_transaction_id`.** Every write to the
  column flows through `TransferPairer::pair`; the per-row
  listener and the bulk orphan-sweep both call it.
- **Both legs are written bidirectionally inside one
  transaction.** A successful pair writes the FK on each leg
  pointing at the other, atomically; a partial write cannot
  land.
- **A re-fired `pair($transactionId, $user)` for an already-
  paired row is a no-op.** The matcher's first filter is
  "neither leg already paired".
- **Match rules:**
  - same user;
  - amount equal-and-opposite, same currency;
  - `booked_at` within ±WINDOW_DAYS calendar days;
  - both legs typed `transfer_in` / `transfer_out`;
  - neither leg already paired.
- **The IBAN reconciliation walks both directions.** A pair
  forms regardless of import order (the firing leg may have
  no `counterparty_iban` if it's a PayPal funding-leg that
  the CSV omits the funding IBAN for; the partner's IBAN
  alias-resolves through `Import::ResolvesKnownCounterpartyIban`).
- **The bulk orphan-sweep runs after
  `Chains::RetypeByAliasResolver`.** Rows the wizard's
  interleaved upload flow left mis-typed at preview time are
  re-typed before the pair sweep runs; this is why the
  resolver order in `ResolveChainLinksJob::handle` is
  load-bearing.
- **`PairLookup::partnerId` returns `null` for an unpaired
  row.** Callers branch on null without inspecting the DB
  column directly.
- **`PairLookup::counterLegOnAccount` takes every bound from
  its caller** — no default window, no assumed direction, no
  assumed currency, no assumed view on already-paired rows, no
  default ordering, and the amount is used as given rather than
  negated inside the query. Held by
  `Modules/Chains/tests/Contracts/PaypalFundingCounterLegParityTest.php`
  and `Modules/Transfers/tests/Feature/CounterLegSearchTest.php`,
  either of which fails if a caller grows its own copy of the
  query again.
- **Both counter-leg orderings are total.** `NearestToCentre`
  and `EarliestBooked` alike run out through `booked_at` then
  `id`, so two equidistant legs — or two legs booked at the
  same instant — resolve by rule and not by whichever index
  SQLite chose. Held by the two ordering cases in
  `CounterLegSearchTest`.
- **Cross-user reads / writes are invisible.** Every query
  filters by `$user->id`; a foreign user's transfer cannot
  pair with the current user's.

## Edge cases

- **A self-transfer with both legs in different currencies**
  — the matcher refuses (currency must match); the user
  sees both legs as independent (acceptable trade-off; FX
  swings would otherwise produce noisy pairings).
- **A self-transfer where the booked_at dates differ by
  more than WINDOW_DAYS** — no pair forms; the user can
  manually intervene if needed (currently a CLI / dev-mode
  escape hatch).
- **A user with three legs of the same amount in the
  window** — the matcher's deterministic order picks the
  closest in time; the third leg remains unpaired pending a
  later partner.
- **A re-imported row whose fingerprint already exists** —
  `Import::RecordsTransactions` dedups at the fingerprint
  layer; the listener never fires for a dedupped row.
- **A wizard-interleaved upload (account A first, then
  account B) where account A's `counterparty_iban`
  references account B's IBAN that didn't exist yet at
  preview** — the per-row listener didn't find a partner;
  the bulk orphan-sweep in
  `Chains::ResolveChainLinksJob::handle` runs after
  `RetypeByAliasResolver` and pairs them.
- **A reject of one leg's category that changes its
  `transactions.type`** — the pair's match-rule "both legs
  typed transfer_in / transfer_out" is a snapshot at write
  time; the FK stays even if the user later re-categorises.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`.
  - [`Ledger`](../ledger/how-to-test.md) — reads
    `transactions`; the `pair_transaction_id` column is
    Ledger-owned (migration sits there).
  - [`Import`](../import/how-to-test.md) — `TransactionImported`
    event, `ResolvesKnownCounterpartyIban` contract.
- **Depended on by**
  - [`Chains`](../chains/how-to-test.md) — `PairsTransferLegs`
    (write) invoked by `ResolveChainLinksJob` for the
    orphan-sweep, and `PairLookup::counterLegOnAccount` (read)
    by `PaypalFundingResolver`'s deterministic arm, which also
    names `CounterLegOrder::NearestToCentre`.

## Configuration + feature flags

- `WINDOW_DAYS` (the per-side calendar-day window) is fixed
  in the `TransferPairer` source. Widening it would
  introduce false positives.
- No env flag changes the matcher's behaviour; it's a pure
  function over the user's transaction set.
- No per-user opt-out for transfer pairing today; the
  matcher runs on every import.
