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

A `categorization_rules` row is a header: a `priority`, an `active`
flag and a `combinator`. Its tests live in `rule_conditions` and its
effects in `rule_actions`, both keyed to it, so one rule can check
several things and change several fields. A condition reads the
counterparty name or the description as text (`equals`, `starts_with`,
`contains`), the settled amount as an integer, or the posting date; an
action sets a category, reassigns a counterparty, writes a note, or
applies a tax tag. Every row carries `user_id` — rules are per-user,
never shared (see [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md)).

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

The merchant-name normalisation is one method,
`FingerprintComposer::normalize()`, and every reader of
`transactions.counterparty_normalized` copies what it returns. It
lower-cases, decomposes and drops the diacritics, replaces everything
that is not a letter, a digit, an ampersand or a space with a space,
collapses the runs and cuts the result at eighty characters. So two
spellings of a merchant that differ only in punctuation or accents
reach the same memory row — but a transaction id left inside the name
is digits, and digits survive, so those two do not. For a user with
at-rest encryption on, the result is then keyed into a one-way digest
under a single derivation domain, which is why
`merchants.normalized_name` has to be built the same way to join
against it.

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

Uncategorised rows surface in the `/uncategorized` queue where the user assigns
a category by hand; that action records a merchant memory (or strengthens
an existing one) and the next transaction from the same merchant lands on
its own. The posture is precision over recall — better to leave a
transaction uncategorised than to assign the wrong category and silently
mis-train the memory layer. The dashboard surfaces "X transactions need
categorising" so the work doesn't accumulate invisibly.

## The default seed set

On user creation, a default set of categorization rules seeds the
`categorization_rules` table from the fixture at
`Modules/Categorization/Database/Seeders/default-categorization-rules.php`.
It is a named-merchant list rather than a handful of patterns: roughly
ninety rules, covering most of the default tree's spending categories.
Netflix and Spotify to the subscription categories, Albert Heijn and
Jumbo to groceries, KPN and Ziggo to internet, Belastingdienst and CJIB
to fees, Geldmaat to cash withdrawal. Nearly every one reads the
counterparty name with `contains`; the exceptions are the two
cash-withdrawal rules, which anchor on a prefix, and the single rule
that reads the description — "IDEAL BETALING, DANK U", which files an
ICS bulk payment as an internal transfer rather than an expense.

The precision comes from every entry naming a universal brand and never
a person. Anything personal — an employer, a family member, a Tikkie —
the user authors against their own data, which is what the per-user
learning loop is for.

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

This module owns the storage — the `pending_enrichment_conflicts` table
and the `users.receipt_conflict_resolution` preference both arrive in
its migrations — while the code that records and settles a conflict
lives with the enrichment itself: `ApplyEnrichments` in `Import` writes
the pending row, and `ApplyReceiptConflictResolution` in `Receipts`
answers it. The user picks once: "receipt wins" (the default) or
"statement wins". Subsequent receipt-vs-statement category conflicts
apply that choice automatically.

## The category surface in code

Categories themselves live in `Modules\Ledger\Models\Category`. The
default tree is seeded once with `user_id = NULL` and shared by every
reader; a category a user adds is their own row beside it, and a re-run
of the seeder never demotes one to global. The categorizer's
`AssignsCategory` action sets `transactions.category_id` through
Ledger's own writer and stamps `auto_category_provenance`, a small JSON
column recording which rule — or which memory row — made the
assignment. `CategorizationProvenancePanel` draws that on the
transaction detail page, so a decision the categorizer made is
reversible at the rule or memory level and not only at the row.

## What categorization does NOT do

- **No cross-user training.** Merchant memories are strictly per-user.
  The optional Community module (opt-in only) exposes a separate
  community-merchant-mapping dataset, but the categorizer never reads
  from it unless the user explicitly imports an entry into their own
  rules table.
- **No LLM inference.** The conditions are deterministic text, amount
  and date comparisons evaluated in PHP — no regular expression, no
  fuzzy match, and no user-authored value ever reaching a SQL
  pattern-match clause. The trade-off is deliberate: visible
  explainability over the higher recall an LLM would offer.
- **No re-categorisation of past transactions unless the reader asks
  for it.** Saving or editing a rule changes nothing that already
  landed. `/rules` offers a re-apply pass over history as an explicit
  action, and that pass leaves alone anything the reader decided by
  hand: a field stamped `manual`, a split, a reconciled row. The
  categorizer never re-walks history on its own.

## Where to look in the code

- `Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php`
  — the auto-category stage invoked from the ingestion pipeline.
- `Modules/Categorization/Public/Contracts/AppliesAutoCategory.php` —
  the public-contract injection point.
- `Modules/Categorization/Public/Contracts/AssignsCategory.php` —
  the manual-assign contract the triage UI uses.
- `Modules/Categorization/Models/CategorizationRule.php` — the
  rules table.
- `Modules/Counterparties/Internal/Pipeline/ResolveCounterpartyStage.php`
  — the pipeline stage this one hands off to; counterparty resolution
  is that module's, not this one's.
- `Modules/Categorization/Database/Migrations/` — the schema for
  rules, conflicts, and the per-user conflict-resolution preference.
- `Modules/Categorization/tests/` — the contract tests covering
  evaluation order, merchant-memory lookup, and conflict resolution.
