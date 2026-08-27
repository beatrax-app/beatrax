# Chain resolution

Chain resolution is the load-bearing capability that makes Beatrax different
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

### PaypalFundingResolver — the three-arm algorithm

The resolver tries three arms in order per candidate row, taking the
first that matches:

1. **Deterministic arm.** Inspects the row's raw-payload event tape
   (the PayPal Activity Download) for `General Withdrawal` /
   `Bankstorting` / `Transfer to bank` events whose memo cells carry an
   IBAN matching one of the user's accounts. When an equal-and-opposite
   `transfer_in` in the same currency exists on that account within
   ±`DATE_WINDOW_DAYS` (3), writes a chain_link: confirmed at 1.000 when
   that row is the only such row in the window, candidate at 0.900 when
   it is not. The export names the destination ACCOUNT and nothing about
   the row on it, so a second incoming row of the same size is a second
   answer to the same question and belongs in the review queue — a
   webshop refund once took the real funder's place, confirmed, where
   nobody could disagree with it. The currency predicate matters for the
   same reason: both sides are compared as native `amount_minor`, so
   without it USD 50.00 answered EUR 50.00. That last query
   is not this module's: it is
   `Modules\Transfers\Public\Services\PairLookup::counterLegOnAccount`,
   which owns the transfer counter-leg search for this arm and for
   `Transfers`' own pairer. The window, the direction, the amount, the
   currency predicate, the already-paired exclusion and the ordering are
   all passed in from here, so widening the arm never widens the shared
   query — and nothing the other caller asks for reaches this one.
2. **ASN-direct arm.** Handles the shape where the funding-leg
   `Bankstorting` row is absent from the PayPal CSV entirely — the
   user's export ships only outgoing merchant payments, not the
   SEPA-pull deposits that funded them. Pairs the PayPal expense
   directly against an ASN-side `transfer_out` whose
   `counterparty_iban` alias-resolves (via `known_counterparty_ibans`,
   `target_account_kind = 'paypal'`) to one of the user's PayPal
   accounts, on equal settled amount within the same date window. Since
   `counterparty_iban` is encrypted, the query narrows on plaintext
   dimensions (user/type/amount/date, capped at 20 candidates) and then
   decrypts + matches each candidate's IBAN against the (small,
   plaintext) alias set in PHP. Exactly one match → confirmed,
   confidence 1.000; two or more → candidate, confidence 0.900 (closest
   by `booked_at` wins, user reviews the ambiguity). An ASN row already
   cited as the `to` side of another non-rejected `paypal_funding` link
   is excluded, so two same-day same-amount PayPal expenses can't both
   claim one ASN debit.
3. **Fuzzy arm.** When both above miss, scores candidate `transfer_in`
   rows by a weighted blend of Levenshtein-normalized merchant
   similarity (0.5) + amount-band similarity (0.3) + date-window
   similarity (0.2). The merchant term must clear 0.6 on its own before
   the blend is taken: an exact amount on the day is already 0.5 of the
   0.6 bar, so without a floor of its own the merchant term decided
   nothing and "Netflix International BV" scored 0.625 against
   "Nationale Nederlanden". It carries the ASN arm's already-claimed
   exclusion too, so two PayPal expenses cannot both name one funding
   leg. The date bounds are DATE strings, matching the DATE column they
   compare against — datetime bounds cut the window to [-2, +3] days.
   The best score at or above 0.6 surfaces as a
   candidate with confidence in `[0.6, 0.99]` — the ceiling is
   deliberately below 1.0 so the deterministic arm stays the only path
   to a round-confidence link. The similarity term reads the **decrypted
   `counterparty_name`**, not `counterparty_normalized`: that column is a
   keyed one-way digest for an encrypted user, and two digests of one
   merchant spelled two ways are as far apart as two unrelated ones, which
   would have taken every candidate below the 0.6 floor.

Every arm computes the same `evidence.signature_hash` —
`sha256(counterparty_normalized|funding-account IBAN)`, over the value the
column stores rather than the readable name the fuzzy arm scores — so
`ConfirmChainLink`'s auto-promotion learning loop has one key to count
confirmations over regardless of which arm produced the match. A funding
account with no IBAN answers to `account=<id>` instead, because the empty
key names nothing; every arm also writes the key it used to
`evidence.matched_iban`, which is where `CounterpartyKeyBackfill`'s
re-signing sweep looks for it first.

Duplicate writes are blocked by `ChainLinkInsertHelper`'s pre-insert
guard, which is also what keeps a user-rejected PAIR rejected across
re-runs. The candidate query deliberately leaves a row whose only link
was rejected in the iteration: the funder the reader turned that one
down for may import a week later, and filtering the row out meant it was
never looked at again.

### ICS bulk-iDEAL settlement chains

ICS Cards bills the user monthly via a single bulk-iDEAL settlement to
ASN — one EUR debit on ASN per ICS statement, covering every credit-card
transaction that month. The user's view becomes "one big debit on ASN +
many merchant lines on ICS" without anything linking them.

The chain has one arm here, spelled out in full below: the resolver
walks the ASN-side `transfer_out` rows, finds the `card_statements` row
they settle, and writes one `chain_links` row of kind `ics_bulk_settle`
per covered ICS expense — `from` is the one ASN payment, `to` is each
charge. `card_statement_credits` is the carry-forward ledger between
statements, not a per-line mapping.

### IcsSettlementResolver — the decomposition algorithm

`Modules\Chains\Internal\Resolvers\IcsSettlementResolver` is the
concrete implementation (`chain_links.kind = 'ics_bulk_settle'`). The
ICS-side leg of an ASN→ICS iDEAL settlement exists only as a
statement-level `card_statements` row — the Mijn ICS PDF carries no
per-row settlement entry, only a single ASN-side `transfer_out`
against the ICS institution's IBAN. The resolver therefore iterates
ASN-side `transfer_out` rows and uses the alias bridge
(`ResolvesKnownCounterpartyIban`) to find the matching ICS account,
then matches against that account's open `card_statements`:

For each unresolved ASN-side `transfer_out` T whose `counterparty_iban`
resolves to an `ics_card`-kind Account A:

1. Find a candidate `open`/`partially_settled` `card_statements` row S
   on A within ±`PERIOD_WINDOW_DAYS` (10) of T's `posted_at`, choosing
   the row whose `period_end` is closest by seconds-precision distance
   (not integer days — day-truncation previously let `orderBy('id')`
   pick an older row over one that was actually closer).
2. Pull every `expense` row on A inside S's period that doesn't yet
   carry a confirmed `ics_bulk_settle` chain_link.
3. Subtract any prior credit already carried into S from
   `card_statement_credits`.
4. Compute the unaccounted delta: `sum(expenses) + prior_credits +
   T.settled_magnitude` (expenses and the statement total are negative
   settled amounts; the transfer's magnitude is taken because it's
   negative on the ASN side, so this reads as paid + credits − owed).
   Positive delta = user overpaid; negative = underpaid. Only credits
   denominated in the statement's own currency are summed.
5. If `|delta|` is within tolerance (max of ±€5 absolute or ±2% of the
   statement total, from `SettlementTolerance` — the forecast's booked-row
   dedup reads the same figures) **and at least one expense is covered** —
   write one confirmed `chain_links` row per expense and call
   `CardStatementStateMachine::applySettlement()`. If the resulting state
   is `overpaid`, emit a `card_statement_credits` row with
   `reason = 'overpayment'`. Covering no expense writes nothing at all:
   the candidate query excludes only a transfer that carries a confirmed
   link, so applying the settlement without one would re-apply it on
   every later pass.
6. Else — write one **candidate** chain_link with `to_transaction_id`
   NULL and `evidence.tolerance_used = 'exceeded'` for the review
   queue; confidence is banded between 0.6 and 0.99, higher when the
   delta is smaller relative to the statement total.

After the main pass, a second pass walks every `refund` row that
posted inside an already-closed (`settled`/`overpaid`) statement,
chains it back to the original purchase (same merchant + opposite-sign
amount inside the closed period, most-recent wins), and emits a
`card_statement_credits` row with `reason = 'refund_after_close'`
carrying forward to the next open statement on the same account. This
pass stays ICS-side because the Mijn ICS PDF does carry per-row refund
entries — only the bulk-settlement entry is absent.

`card_statement_credits` rows carry the surplus/refund amounts flowing
between statements: `from_statement_id` is the source (the one that
went overpaid or accepted a refund after close); `to_statement_id` is
the destination, nullable because a surplus may exist before the next
statement period rolls in — a follow-up resolver pass sets the pointer
once that statement lands. Only two `reason` values are allowed:
`overpayment` and `refund_after_close` (a DB-layer trigger pair rejects
anything else).

Every signature hash is `sha256(account.iban|period_end|user=<id>)` —
the user id is folded in so two users sharing the same account-iban
literal (e.g. a synthetic `'ICS-CARD'` placeholder) don't collide on
the auto-promotion learning-loop counter.

`transactions.counterparty_iban` is an encrypted `SensitiveFieldRegistry`
column; the resolver decrypts it before handing it to
`ResolvesKnownCounterpartyIban`, which compares against the plaintext
`known_counterparty_ibans.real_iban` column.

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

Direct writes from anywhere else are caught by the repo-wide
`crossModuleRawTableWrites` invariant: every write a module makes to a
table another module owns is pinned to an allow-list by file, line, and
table, so a new one is a decision rather than a diff. Within the
resolver, `pair_transaction_id` is the only column of the row it may
touch — amount, currency, and description belong to the ledger.

## RetypeByAliasResolver — the wizard-order healing pass

`Modules\Chains\Internal\Resolvers\RetypeByAliasResolver` resolves a
race in the onboarding flow: when a source file is uploaded before the
destination-kind account exists (e.g. an ASN CSV uploaded while only
the `bank`-kind account exists, with the `paypal`-kind account created
moments later in a later wizard step), the preview-time
`ClassifyTransactionType` pipeline stage finds the alias row but no
matching destination Account, so the row falls through to the
amount-sign default (`expense`/`income`) and that type is persisted
into the ledger on confirm. Without a healing pass those rows stay
mis-typed forever and every downstream chain resolver iterates an
empty set.

The resolver re-applies the classifier's cross-account rule against
the now-complete account graph, per user: for every `expense`/`income`
row whose `counterparty_iban` resolves (via `known_counterparty_ibans`)
to a target account kind belonging to the same user, and that account
isn't the row's own account, it retypes the row by amount sign
(`amount_minor < 0` → `transfer_out`, otherwise `transfer_in`). It is
idempotent (a retyped row leaves the `expense`/`income` set and is
never a candidate again) and self-healing (a newly added alias retypes
matching historical rows on the next chain dispatch). It runs before
the pair-orphan sweep and the two resolvers above, inside
`ResolveChainLinksJob`, so they iterate the corrected ledger. It never
touches `pair_transaction_id` — pairing stays the orphan-sweep's job.

Because `counterparty_iban` is encrypted, this can't be one
correlated-subquery UPDATE (a ciphertext join-equality never matches).
Instead: load the user's `known_counterparty_ibans` and accounts into
small in-PHP maps (return early if there are no aliases at all, without
decrypting a single row); narrow candidate transactions on the same
cheap plaintext dimensions the original SQL used, via `chunkById()` so
a large first-run history sweep never holds every row in memory; then
decrypt each surviving candidate's IBAN once and look it up in the
in-PHP alias map. Matches are queued and applied via batched raw
`UPDATE ... WHERE id IN (...)` statements. This resolver is the one
documented exception to the read-mostly contract — it retypes
`transactions.type`, unlike every other Chains resolver, which writes
only `chain_links`/`card_statements`. The raw-SQL spelling once put the
write outside what the boundary grep could see;
`crossModuleRawTableWrites` reads SQL strings too, and pins this line
alongside every other cross-module write.

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

`ChainLink::$confidence` is intentionally left without an explicit
Eloquent cast — the SQLite decimal column returns a string, which
keeps the strict-rules cast lint satisfied; callers cast to `(float)`
at the point of use if a numeric comparison is required. `$evidence`
is cast `array` so resolver-emitted structured data (`signature_hash`,
`tolerance_used`, `unaccounted_delta_minor`, `statement_id`, ...)
round-trips through Eloquent without manual encode/decode.

## The read-mostly contract

The chain resolver is the largest read-mostly subsystem in the
codebase. The arch invariants enforce its read-only posture against
the canonical ledger:

- **`crossModuleRawTableWrites`** — every write `Modules/Chains/`
  makes to a table it does not own is pinned by file, line, and table.
  Today that is the single `RetypeByAliasResolver` retype of
  `transactions.type`; the resolver job's `pair_transaction_id` write
  goes through the Ledger model.
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
