# `Transfers` — architecture

The `Transfers` module pairs the two legs of a self-transfer
(money the user moved between their own accounts) so the dashboard
treats the pair as a single internal movement, not as two
independent transactions. It owns the deterministic matcher behind
`transactions.pair_transaction_id` (the column itself is owned by
[`Ledger`](../ledger/architecture.md)'s migration) and the read-side
`PairLookup` queries — both the persisted-pair read and the
counter-leg search a neighbour runs before any pair exists.

## What this module is for

A `transfer_out` on the user's ASN account paired with a
`transfer_in` on their PayPal account is one logical event: the
user moved €100 from one of their pockets to another. Without
pairing, both legs appear in the per-account totals and the
dashboard double-counts the move. The matcher detects the pair on
the per-row import boundary and links them via the bidirectional
`transactions.pair_transaction_id` FK.

The same matcher runs in two contexts: per-row inside the import
transaction frame (via the `PairTransferCandidates` listener on
`Import::TransactionImported`), and as a bulk orphan-sweep inside
the chain-resolution job (after
`Chains::RetypeByAliasResolver` re-types rows the wizard's
interleaved upload flow left mis-typed at preview time).

What the module explicitly does NOT do:

- It never owns the `pair_transaction_id` schema. The column +
  migration belong to [`Ledger`](../ledger/code.md); this module
  populates it through a single sanctioned matcher.
- It never re-pairs a row that already has a partner. The
  matcher filters out already-paired legs first.
- It never decides chain semantics. A self-transfer is a self-
  transfer; the funding-chain interpretation (PayPal funded by
  ASN, ICS settled by ASN) is `Chains::PaypalFundingResolver`'s
  domain.

## Module boundary

`Public/` exposes a small cross-module surface:

- **Contracts/**
  - `PairsTransferLegs::pairOne($tx, $user): ?int` — the per-row
    hot path both the listener and the bulk sweep call.
  - `PairsTransferLegs::pairOrphansForUser($user): int` — sweeps
    every unpaired transfer leg for the user, used by the chain-
    resolution job after its `RetypeByAliasResolver` re-types rows
    the wizard's interleaved upload flow left mis-typed.
- **Services/**
  - `PairLookup::isPaired($txId, $user): bool` and
    `PairLookup::partnerId($txId, $user): ?int` — read the
    persisted `pair_transaction_id` for a row.
  - `PairLookup::counterLegOnAccount($match, $window, $user): ?int`
    — the counter-leg SEARCH, for a caller holding the far side's
    account and amount rather than a paired row's id. Two
    callers: `Chains::PaypalFundingResolver`'s deterministic
    arm, and this module's own `TransferPairer` forward arm.
  - `PairUnlinker` — implements [`Ledger`](../ledger/architecture.md)'s
    `UnpairsTransferLegs`. What happens to the surviving half when
    the other half is deleted: it stops being a transfer and
    becomes plain income or expense. Called by `Ledger`'s
    `DeleteTransaction` and by `Sync`'s merge replay.
- **Support/**
  - `CounterLegMatch` — what the far leg has to look like
    (account, amount, types, currency, whether an existing pair
    disqualifies it, which id to exclude).
  - `CounterLegWindow` — where it may sit in time, and which of
    several the caller wants.
- **Enums/**
  - `CounterLegOrder` — which of several counter-legs wins.
    `NearestToCentre` for chain resolution, `EarliestBooked`
    for the pairer.

`Internal/` houses the matcher + listener:

- **Internal/Services/TransferPairer** — concrete
  `PairsTransferLegs`. Deterministic matcher:
  - same user (every query filters on `$user->id`);
  - the two legs sit on two DIFFERENT accounts — a transfer
    crosses accounts, so the forward arm refuses a partner
    account that resolved back to the one the firing leg is
    already on, which the alias bridge does whenever that leg
    sits on the lowest-id account of the aliased kind;
  - amount equal-and-opposite, same currency;
  - `booked_at` within `CounterLegWindow::DEFAULT_DAYS` calendar
    days either side;
  - both legs typed `transfer_in` / `transfer_out`;
  - neither leg already paired.
  IBAN reconciliation walks both directions (forward = firing
  leg has a `counterparty_iban` matching the user's own
  account; reverse = firing leg has no IBAN, partner's IBAN
  resolves through `Import::ResolvesKnownCounterpartyIban`).
  The forward arm reconciles the IBAN and then hands the
  candidate search to `PairLookup::counterLegOnAccount`; only
  the reverse arm still runs a SELECT of its own, because it
  reads back ciphertext to match in PHP rather than in SQL.
- **Internal/Listeners/PairTransferCandidates** — listens for
  `Import::TransactionImported`; calls
  `TransferPairer::pairOne()` per row inside the import
  transaction frame. An event whose `user` does not match the
  transaction's `user_id` raises
  `Internal/Exceptions/MismatchedTransferUserException` rather
  than pairing across a user boundary.

### Decrypt-then-match (encrypted `counterparty_iban`)

`transactions.counterparty_iban` is a `SensitiveFieldRegistry` ciphertext
column once encryption is enabled for a user, so it can never sit in a SQL
equality or `whereIn` predicate — a ciphertext value never equals a
plaintext candidate. Both matcher arms decrypt before comparing:

- **Forward arm** (firing leg has a `counterparty_iban`): decrypts the
  firing leg's IBAN once into a plaintext local, then compares it against
  plaintext `accounts.iban` / hands it to `ResolvesKnownCounterpartyIban`.
- **Reverse arm** (firing leg has none): keeps its existing narrow SQL
  predicates (amount equal-and-opposite, currency, the
  `CounterLegWindow::DEFAULT_DAYS` window either side,
  unpaired, type — already a small candidate set), then decrypts each
  surviving candidate's `counterparty_iban` and matches it against the
  plaintext candidate-IBAN set in PHP.

Both arms treat a decrypt failure (`decrypted: false`) as "cannot match"
only when encryption is actually enabled for the user — for a
non-encryption user, `decrypted: false` is the expected pass-through
signal and the returned value is valid plaintext.

## Key services + events

- `TransferPairer::pairOne($tx, $user)` — the sole deterministic
  matcher. Used by both the listener and the bulk sweep so the
  pairing logic is one source of truth.
- `TransferPairer::pairOrphansForUser($user)` — sweeps every
  unpaired transfer leg for the user, pairing each one that now
  has a persisted partner.
- `PairTransferCandidates::handle($event)` — per-row hot path
  inside `Import::TransactionImported`. Calls
  `TransferPairer::pairOne()`; on a successful match, both legs'
  `pair_transaction_id` columns are written bidirectionally
  inside the same transaction frame.
- `PairLookup::isPaired($txId, $user)` / `PairLookup::partnerId($txId, $user)`
  — the read-side pair query, over the persisted
  `pair_transaction_id` column. Both answer "what is this row
  already paired to"; neither searches.
- `PairLookup::counterLegOnAccount(...)` — the search that answers
  "which row on THIS account, of THESE types, for THIS amount, in
  THIS window". It writes nothing, and whether an existing
  `pair_transaction_id` disqualifies a row is the caller's call:
  the pairer says yes, chain resolution says no, because a PayPal
  funding leg the matcher will never pair is still a valid answer.
- The two callers also disagree on which of several candidates
  wins, so `CounterLegOrder` is a parameter rather than a default.
  Both orderings end in `booked_at` then `id`: an equidistant or
  same-day pair used to resolve on whichever index SQLite picked,
  which made the chosen leg an accident of the query planner.

- `PairUnlinker::unpair($userId, $survivorId, $deletedType)` —
  the surviving half of a deleted pair stops being a transfer.
  Which way it goes is read off the SURVIVOR's own amount, not
  off the deleted leg's type: the two legs do not always carry
  opposite types (`Ingestion`'s `PaypalCsvEventTypeMap` types a
  withdrawal `transfer_in` on both sides), and a survivor that
  is no longer a transfer leg is left alone.

The module raises no events; it persists in response to the
upstream `TransactionImported`.

## Data flow

The per-row pair detection at import time:

```
Import::ConfirmImport persists transaction
  → dispatch TransactionImported($transactionId)
       → PairTransferCandidates::handle($event)
            → TransferPairer::pairOne($tx, $user)
                 → forward arm: resolve the partner account —
                      never the firing leg's own — then
                      PairLookup::counterLegOnAccount(
                        new CounterLegMatch($partnerAccountId,
                          -$amountMinor, [TransferOut, TransferIn],
                          $currency, unpairedOnly: true,
                          excludeTransactionId: $tx->id),
                        new CounterLegWindow($bookedAt,
                          CounterLegWindow::DEFAULT_DAYS,
                          EarliestBooked),
                        $user)
                 → reverse arm: SELECT the narrow candidate set,
                      then decrypt-and-match in PHP
                 → if match found:
                      UPDATE transactions SET pair_transaction_id = ?
                        WHERE id IN (a, b)  (bidirectional)
```

The bulk orphan-sweep inside the chain-resolution job:

```
Chains::ResolveChainLinksJob::handle
  → … other resolvers run …
  → for each unpaired transfer leg the wizard's interleaved
    flow left mis-typed:
       → RetypeByAliasResolver retypes
       → TransferPairer::pairOne (same matcher)
            → bidirectional update
```

The read-side from chain resolution:

```
Chains::PaypalFundingResolver deterministic arm
  → for each unfunded PayPal transfer_out / expense:
       → read the destination IBAN out of the stored PayPal event
       → resolve it to one of the user's accounts
       → PairLookup::counterLegOnAccount(
             new CounterLegMatch($accountId, -$amountMinor,
               [TransferIn], currency: null,
               unpairedOnly: false, excludeTransactionId: null),
             new CounterLegWindow($bookedAt, CounterLegWindow::DEFAULT_DAYS,
               NearestToCentre),
             $user)
       → write chain_links row
```

The resolver's other two arms (ASN-direct, fuzzy) do not go
through `PairLookup`: neither starts from a known account id, so
neither can ask this question.
