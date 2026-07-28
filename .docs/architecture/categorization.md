# Categorization

Categorization assigns each transaction a category (Groceries, Subscriptions,
Salary, Transfers, ...) at import time, with no LLM involvement and no
cloud dependency. Two layers cooperate: a rule-based categorizer that runs
first, and a per-user merchant memory that learns from manual
re-categorizations. The result is high-precision, low-recall on day one
(a clean default seed set covers common merchants) and rises toward
full coverage as the user's manual corrections accumulate.

The structural decisions this categorizer operates inside are
[ADR 0001](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0001-modular-architecture.md) (the Categorization module
sits behind a public-contract surface) and
[ADR 0002](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0002-di-only-rule.md) (the per-user rule and memory
collaborators are constructor-injected).

## The two layers

### Layer 1: Per-user categorization rules

A `categorization_rules` row encodes one match condition (description
substring, counterparty IBAN, counterparty name regex, amount range,
payment-type filter, ...) and one assignment (the `category_id` to
apply on match). Every row carries `user_id` — rules are per-user, never
shared (see [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md)).

Each rule carries a **specificity score** computed at save time from the
shape of the conditions: more conditions = higher specificity; narrower
conditions (exact IBAN match) = higher than broader conditions
(description substring); user-authored rules score higher than
default-seed rules of equivalent shape.

The categorizer walks the user's rules in **descending specificity order**
and stops at the first rule whose conditions match the transaction. The
matched rule's `category_id` is applied. If no rule matches, layer 2
takes over.

### Layer 2: Per-user merchant memory

The `merchant_memories` table holds one row per
(user, normalised-merchant-name, category) tuple. When the user manually
recategorises a transaction in the triage UI, the action records or
strengthens the matching merchant memory. The next time a transaction
with the same normalised merchant name arrives, the categorizer
consults the table.

The merchant-name normalisation strips trailing transaction IDs, common
suffixes (`PAY ID 12345`, `*MERCHANT REF`), and provider prefixes
(`PAYPAL *`, `ICS:`), then lower-cases. Two transactions from the same
merchant with different transaction-IDs in their descriptions both
match the same memory row.

Memory rows carry an `occurrences` counter that increments on every
match. A memory row with high occurrences plus 100% same-category
agreement counts as more confident than a row with one observation.

## The ≥40% confidence gate

The auto-category stage applies a category only if its match confidence
clears the ≥40% gate. Confidence is computed differently per layer:

- **Rule match**: confidence = `rule.specificity_score / max_possible_score`.
  A user-authored exact-IBAN rule clears the gate immediately. A
  default-seed substring rule does not, unless the substring is unusual.
- **Merchant-memory match**: confidence = `same_category_count / total_count`
  for the matched memory row. A new memory row (one observation) starts
  at 100% but is also one observation; the auto-category stage requires
  both confidence ≥40% AND a minimum observation count.

Transactions that fail the gate are left uncategorised. They surface
in the `/triage` queue where the user manually assigns a category;
that action records a merchant memory (or strengthens an existing one)
and the next similar transaction clears the gate.

The gate's purpose is high precision over high recall — better to leave
a transaction uncategorised than to assign the wrong category and
silently mis-train the memory layer. The triage UI is the explicit
catch-up surface; the dashboard surfaces "X transactions need
categorising" so the work doesn't accumulate invisibly.

## The default seed set

On user creation, a default set of categorization rules seeds the
`categorization_rules` table. The seeds cover obvious cases the
maintainer was willing to commit to as universal: SEPA Direct Debit
descriptions that contain the word "VVE" map to Service Charges; ASN
transactions tagged as `RENTE` map to Interest; ICS bulk-iDEAL
settlements map to Transfers. The seed is intentionally small — high
precision on a handful of universally-true patterns, leaving everything
else to the per-user learning loop.

A user can disable or modify any seed rule. The default rules carry a
flag distinguishing them from user-authored rules, and the specificity
calculation breaks ties in favour of user-authored rules so an explicit
user override always beats the seed.

## The receipt-vs-statement enrichment conflict resolver

A complication that the categorizer has to handle: the same transaction
can arrive twice from two sources (e.g. a Google Play purchase as a
PayPal CSV line + later as a Gmail receipt). The receipt typically
carries better category information (the receipt knows it was a Google
Play subscription; the PayPal CSV line only knows "PAYPAL EUROPE"). The
ingestion pipeline's fingerprint stage classifies the second arrival
as ENRICHED, and the enrichment carries the category-conflict to the
categorizer.

`Modules\Categorization\Internal\Pipeline\` includes the
`PendingEnrichmentConflict` table and the per-user conflict-resolution
preference. The user picks once: "receipt wins" (the default) or
"statement wins". Subsequent receipt-vs-statement category conflicts
apply the rule automatically.

## The category surface in code

Categories themselves live in `Modules\Ledger\Models\Category`. They
are per-user (`belongs_to_user` via the trait); the seed set is created
fresh per user on signup. The categorizer's `assigns_category` action
sets `transactions.category_id` and writes a small
`auto_category_provenance` JSON column recording which rule (or which
memory row) made the assignment — the triage UI surfaces this to the
user when they re-categorise, so the action is reversible at the rule
or memory level.

## What categorization does NOT do

- **No cross-user training.** Merchant memories are strictly per-user.
  The optional Community module (opt-in only) exposes a separate
  community-merchant-mapping dataset, but the categorizer never reads
  from it unless the user explicitly imports an entry into their own
  rules table.
- **No LLM inference.** The matchers are deterministic regex and
  substring + counterparty + amount-range conditions. The trade-off
  (visible explainability vs the higher recall an LLM would offer)
  was made explicitly during Phase 7.
- **No re-categorisation of past transactions when a new rule lands.**
  The triage UI lets the user retro-categorise on demand; the
  categorizer does not silently re-walk history when rules change.
  This preserves the audit trail.

## Where to look in the code

- `Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php`
  — the auto-category stage invoked from the ingestion pipeline.
- `Modules/Categorization/Public/Contracts/AppliesAutoCategory.php` —
  the public-contract injection point.
- `Modules/Categorization/Public/Contracts/AssignsCategory.php` —
  the manual-assign contract the triage UI uses.
- `Modules/Categorization/Models/CategorizationRule.php` — the
  rules table.
- `Modules/Categorization/Internal/Pipeline/ResolveCounterpartyStage.php`
  — the per-stage hand-off to counterparty resolution.
- `Modules/Categorization/Database/Migrations/` — the schema for
  rules, conflicts, and the per-user conflict-resolution preference.
- `Modules/Categorization/tests/` — the contract tests covering
  specificity scoring, the confidence gate, and conflict resolution.
