# Chain resolution

Chain resolution is the load-bearing capability that makes beatrax different
from a per-account ledger viewer. Money in this system fans out through
multiple providers — a single Netflix subscription touches PayPal, an ICS
credit card, an ASN bulk-iDEAL settlement, and the ASN current account —
and the user wants to see, from any leg, the complete chain that connects
them.

This document describes the two chain shapes the resolver handles
(PayPal funding chains and ICS bulk-iDEAL settlement chains), the resolver
modules involved, the read-mostly contract the resolver respects, and how
the `pair_transaction_id` column links transactions across the chain.

## The two chain shapes

### PayPal funding chains

A PayPal charge is two transactions: the merchant-side debit out of the
user's PayPal balance, and the funding-side debit from whichever bank
account or credit card PayPal pulled the money from. The user sees both.
A naïve view shows them as two unrelated debits; chain resolution links
them.

The chain has two arms:

- **Direct-funded arm**: PayPal debits ASN (or any direct-debit-capable
  bank account) via SEPA Direct Debit. The user sees a "Bankstorting"
  entry on PayPal (the funding leg) and a matching debit on ASN. The
  resolver links these via amount + currency + booked-at proximity.
- **ASN-direct arm**: For some PayPal-funded charges there is no
  Bankstorting wrapper — the funding-side debit appears directly as the
  ASN line. The resolver in this case looks at the PayPal-side
  counterparty plus the amount and matches the ASN line directly.

Either way, the result is one `chain_links` row with kind
`paypal_funding` connecting the PayPal-side `transactions.id` to the
ASN-side `transactions.id`. The resolver lives in
`Modules\Chains\Internal\Resolvers\PaypalFundingResolver`.

### ICS bulk-iDEAL settlement chains

ICS Cards bills the user monthly via a single bulk-iDEAL settlement to
ASN — one EUR debit on ASN per ICS statement, covering every credit-card
transaction that month. The user's view becomes "one big debit on ASN +
many merchant lines on ICS" without anything linking them.

The chain has one arm here:

- The ICS statement-summary row (loaded from the PDF parser in Phase 3)
  carries the bulk-iDEAL amount and the settlement date. The ASN side
  has a matching debit with the description marker that identifies it
  as the ICS settlement (`ICS Cards`, `ICS KLANTENSERVICE`, etc).
- The resolver matches the two on amount + date proximity, then
  decomposes the ICS settlement into its constituent ICS lines via the
  `card_statement_credits` table (the per-line mapping that the PDF
  parser produces).

The result is one `chain_links` row with kind `ics_settlement` linking
the ASN side to the ICS statement; each ICS line carries
`pair_transaction_id` pointing back to the ASN settlement.

## The pair_transaction_id column

`transactions.pair_transaction_id` is the load-bearing FK that lets a
single line on the ledger answer "what's the other end of this chain?".
It is nullable (most transactions have no pair) and points to another
`transactions.id`. The convention:

- For PayPal funding chains: the PayPal-side row's
  `pair_transaction_id` points to the ASN-side row's `id`.
- For ICS bulk-iDEAL settlement: each ICS line's
  `pair_transaction_id` points to the ASN-settlement row's `id`.
- For self-transfers between user accounts (handled by `Modules/Transfers/`,
  not the chain resolver): each leg's `pair_transaction_id` points to
  the other leg.

The column is set only by:

- The chain resolver, for chain links.
- The transfer-detection job, for self-transfers.

Direct writes from anywhere else are caught by the
`noResolverWritesTransactions` arch invariant for the Chains module —
which actually permits writing `pair_transaction_id` specifically,
because that's the column the resolver is allowed to touch. The
invariant forbids writes to the rest of the row (amount, currency,
description, etc.), which is what the read-mostly contract is about.

## The known-counterparty-IBAN alias bridge

PayPal funding chains rely on the resolver recognising that
"`PAYPAL EUROPE`" on the ASN side and the merchant name on the PayPal
side belong together. The bridge is the `known_counterparty_ibans`
table, which maps an IBAN observed on the ASN side to a
counterparty-alias-name observed on the PayPal side. The table is
seeded per user (the first time the user resolves a candidate via the
review queue), then reused for every subsequent chain.

This is what makes per-user learning work: the resolver gets
sharper with every confirmed candidate. The
`Modules\Chains\Internal\Resolvers\RetypeByAliasResolver` consults the
table on every resolution attempt.

## The resolver job

Chain resolution runs as a Laravel job:
`Modules\Chains\Internal\Jobs\ResolveChainsForUser`. The job is
scheduled per user after every successful import. It is
`ShouldBeUniqueUntilProcessing` — the per-user lock prevents two
concurrent resolutions for the same user from racing each other.

The job runs through three resolver passes in sequence:

1. `PaypalFundingResolver` — pairs PayPal-side transactions to their
   funding-side counterparts.
2. `IcsSettlementResolver` — pairs ASN bulk-iDEAL settlements to their
   ICS card-statement rows + decomposes into per-line links.
3. `RetypeByAliasResolver` — re-walks unresolved candidates against the
   `known_counterparty_ibans` aliases learnt from prior runs.

Each pass populates `chain_links` rows (the durable record of the
match) and sets `pair_transaction_id` on the affected transactions
(the fast lookup index). The job also produces a `chain_resolution_runs`
row recording the per-run metrics — number of links created, number of
candidates flagged for review.

A self-healing variant of the job runs from the wizard when the
chain-resolution-for-import dependency would otherwise race the first
import — see `Modules\Chains\Internal\Jobs\` for the wizard-aware
scheduling path.

## The candidate review queue

When the resolver finds two transactions that match on amount and
currency but not strongly enough on counterparty + date proximity, it
flags them as a candidate rather than auto-linking. The candidates
surface in `/chains/triage`; the user confirms or rejects each one. A
confirmed candidate creates the `chain_links` row AND inserts an alias
row into `known_counterparty_ibans` so subsequent runs match
automatically.

## The read-mostly contract

The chain resolver is the largest read-mostly subsystem in the
codebase. The arch invariants enforce its read-only posture against
the canonical ledger:

- **`noResolverWritesTransactions`** — files under
  `Modules/Chains/Internal/Resolvers/` cannot write the `transactions`
  table, except for the explicit `pair_transaction_id` carve-out the
  resolver job applies.
- The Chains module writes only to its own tables:
  `chain_links`, `chain_resolution_runs`,
  `known_counterparty_ibans`, `community_merchant_mappings` (when
  the community-dataset opt-in is enabled). It reads from `transactions`,
  `card_statements`, `card_statement_credits`, `counterparties`, and
  `statement_summaries`.

The contract matters because it makes chain resolution safe to re-run.
A second run of the resolver against the same transactions produces
the same `chain_links` rows; no transactions are modified except the
`pair_transaction_id` index update, which is itself idempotent (the
column either already points to the correct pair or gets set to it).

## Where to look in the code

- `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` —
  the PayPal funding chain logic.
- `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` —
  the ICS bulk-iDEAL decomposition logic.
- `Modules/Chains/Internal/Resolvers/RetypeByAliasResolver.php` —
  the alias-bridge re-walk.
- `Modules/Chains/Internal/Jobs/ResolveChainsForUser.php` — the
  per-user resolver job.
- `Modules/Chains/Models/ChainLink.php` — the chain-link ledger.
- `Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php`
  — the seven-step counterparty resolution that the Chains resolver
  reuses.
- `Modules/Chains/tests/` — the contract tests including the
  read-mostly invariants.
