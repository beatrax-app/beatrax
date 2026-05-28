# `Transfers` — specs

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
- **`PairLookup::partnerFor` returns `null` for an unpaired
  row.** Callers (e.g.
  `Chains::PaypalFundingResolver::asnDirectArm`) branch on
  null without inspecting the DB column directly.
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
  - [`Core`](../core/specs.md) — `User`, `Clock`.
  - [`Ledger`](../ledger/specs.md) — reads
    `transactions`; the `pair_transaction_id` column is
    Ledger-owned (migration sits there).
  - [`Import`](../import/specs.md) — `TransactionImported`
    event, `ResolvesKnownCounterpartyIban` contract.
- **Depended on by**
  - [`Chains`](../chains/specs.md) — `PairLookup` (read)
    and `PairsTransferLegs` (write) both invoked by
    `ResolveChainLinksJob` for the orphan-sweep + the
    PayPal ASN-direct arm.

## Configuration + feature flags

- `WINDOW_DAYS` (the per-side calendar-day window) is fixed
  in the `TransferPairer` source. Widening it would
  introduce false positives.
- No env flag changes the matcher's behaviour; it's a pure
  function over the user's transaction set.
- No per-user opt-out for transfer pairing today; the
  matcher runs on every import.
