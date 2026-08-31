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

Each rule carries an integer `priority`. `RuleEngine::match()` reads the
user's active rules ordered by `priority` ascending then `id` ascending,
evaluates **every** one of them, and returns all that fired — there is no
short-circuit and no early exit. `RuleApplier` then folds the fired rules'
actions in that same order, so the **last** matching rule wins: a rule at
priority 10 overrides a rule at priority 1. The full account, including
what makes a rule fire and how a rule with no conditions is handled, is
[Rule evaluation order](../features/categorization/rule-evaluation-order.md).
If no fired rule carries a category action, layer 2 takes over.

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

Memory rows carry an `occurrence_count` that increments on every match,
and a `last_seen_at`. `RuleEvaluator::lookupMemory()` orders by
`last_seen_at` descending, then `occurrence_count` descending, then `id`
descending, and takes the first row. Recency leads deliberately: on the
count alone a fresh correction at one observation could never beat a
stale memory at eighteen, and the reader would get the category they had
just corrected away.

## When nothing is applied

There is no confidence score and no numeric gate. A transaction is left
uncategorised when no rule fired with a category action **and** the
merchant memory has no row for its normalised counterparty — which is
also the case for a row whose counterparty could not be normalised at
all.

Uncategorised rows surface in the `/triage` queue where the user assigns
a category by hand; that action records a merchant memory (or strengthens
an existing one) and the next transaction from the same merchant lands on
its own. The posture is precision over recall — better to leave a
transaction uncategorised than to assign the wrong category and silently
mis-train the memory layer. The dashboard surfaces "X transactions need
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

A user can disable or modify any seed rule; deactivating one removes it
from the candidate set entirely rather than skipping it later. A user
rule that must beat a seed rule does so by carrying the higher
`priority`, since the last matching rule is the one whose category
lands.

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
  substring + counterparty + amount-range conditions. The trade-off is
  deliberate: visible explainability over the higher recall an LLM
  would offer.
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
  evaluation order, merchant-memory lookup, and conflict resolution.
