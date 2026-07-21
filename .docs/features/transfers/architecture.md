# `Transfers` — architecture

The `Transfers` module pairs the two legs of a self-transfer
(money the user moved between their own accounts) so the dashboard
treats the pair as a single internal movement, not as two
independent transactions. It owns the deterministic matcher behind
`transactions.pair_transaction_id` (the column itself is owned by
[`Ledger`](../ledger/architecture.md)'s migration) and the read-side
`PairLookup` query other modules consume.

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
    `PairLookup::partnerId($txId, $user): ?int` — read-side
    queries consumed by `Chains::PaypalFundingResolver::asnDirectArm`.

`Internal/` houses the matcher + listener:

- **Internal/Services/TransferPairer** — concrete
  `PairsTransferLegs`. Deterministic matcher:
  - same user (every query filters on `$user->id`);
  - amount equal-and-opposite, same currency;
  - `booked_at` within ±WINDOW_DAYS calendar days;
  - both legs typed `transfer_in` / `transfer_out`;
  - neither leg already paired.
  IBAN reconciliation walks both directions (forward = firing
  leg has a `counterparty_iban` matching the user's own
  account; reverse = firing leg has no IBAN, partner's IBAN
  resolves through `Import::ResolvesKnownCounterpartyIban`).
- **Internal/Listeners/PairTransferCandidates** — listens for
  `Import::TransactionImported`; calls
  `TransferPairer::pairOne()` per row inside the import
  transaction frame.

### Decrypt-then-match (encrypted `counterparty_iban`)

`transactions.counterparty_iban` is a `SensitiveFieldRegistry` ciphertext
column once encryption is enabled for a user, so it can never sit in a SQL
equality or `whereIn` predicate — a ciphertext value never equals a
plaintext candidate. Both matcher arms decrypt before comparing:

- **Forward arm** (firing leg has a `counterparty_iban`): decrypts the
  firing leg's IBAN once into a plaintext local, then compares it against
  plaintext `accounts.iban` / hands it to `ResolvesKnownCounterpartyIban`.
- **Reverse arm** (firing leg has none): keeps its existing narrow SQL
  predicates (amount equal-and-opposite, currency, ±WINDOW_DAYS window,
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
  — read-side consumed by `Chains::PaypalFundingResolver` to
  determine the partner row when computing the ASN-direct funding
  arm.

The module raises no events; it persists in response to the
upstream `TransactionImported`.

## Data flow

The per-row pair detection at import time:

```
Import::ConfirmImport persists transaction
  → dispatch TransactionImported($transactionId)
       → PairTransferCandidates::handle($event)
            → TransferPairer::pairOne($tx, $user)
                 → SELECT candidate partner (amount opposite,
                                              ±WINDOW_DAYS,
                                              both legs unpaired)
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
Chains::PaypalFundingResolver::asnDirectArm
  → for each unfunded PayPal expense:
       → PairLookup::partnerId($candidateAsnLeg, $user)
       → confirm partner is the PayPal leg expected
       → write chain_links row
```
