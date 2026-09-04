# Rule evaluation order — which rule wins, and why

A user can author any number of categorisation rules, and nothing stops
two of them from matching the same transaction and demanding different
categories. This page describes exactly how that is resolved: the order
rules are evaluated in, what makes a rule fire at all, and which of
several competing actions actually lands on the row.

The short version, because it is the opposite of what most readers
assume: **evaluation order is by ascending `priority`, and the LAST
matching rule wins.** A rule with `priority` 10 overrides a rule with
`priority` 1. See [Last writer wins](#last-writer-wins) below.

## Selecting and ordering the rules

`ActiveRuleSet` reads the candidate set that `RuleEngine::match()` walks, with:

    ->where('user_id', $user->id)
    ->where('active', true)
    ->orderBy('priority')
    ->orderBy('id')

Three things follow from that query and each of them matters.

**Rules are per-user.** There is no shared or global rule table at match
time; the seeded default corpus is copied per user rather than consulted
in place.

**Inactive rules are invisible, not skipped later.** `active = false`
removes a rule from the candidate set entirely, so deactivating a rule
cannot leave a half-applied effect behind.

**The ordering is total and deterministic.** `priority` ascending, then
`id` ascending. The `id` tiebreak is not decoration: `priority` carries
no uniqueness constraint, so two rules at the same priority are common,
and without the tiebreak SQLite would be free to return them in either
order and the outcome of a re-apply run could change between runs over
identical data.

## When a rule fires

Every rule in the candidate set is evaluated. There is no short-circuit
and no early exit — `match()` collects *all* matching rules into a
`list<MatchedRule>` and returns them together. A rule matching early does
not stop a later rule from being considered.

Each rule's conditions are read separately, ordered by `id`, and every
condition is evaluated to a bool. The rule's `combinator` then folds
those results:

- `all` — fires when the condition list is non-empty and **no** condition
  returned false.
- `any` — fires when **at least one** condition returned true.

Note the asymmetry in how each treats a rule with no conditions at all.
Under `all` the explicit `$results !== []` guard rejects it; under `any`
there is no true to find, so it also rejects it. **A rule with zero
conditions never fires under either combinator** — it cannot become an
accidental match-everything rule. That is deliberate, and it is the
reason the `all` branch carries a guard that looks redundant.

`RuleCombinator::coerce()` resolves an unrecognised or NULL stored
combinator to `all`, the conservative choice: the stricter fold is the
safer default for a value that arrived corrupt.

## How a condition is evaluated

A condition carries a `value_type`, an `op`, a `field`, and one or two
values. If either `value_type` or `op` fails to resolve to its enum, the
condition returns false rather than throwing — a corrupt row disables its
rule instead of taking down the run.

**Text** (`string`) matches against `merchant`/`counterparty` (both read
the counterparty name) or `description`; any other field name resolves to
null and never matches. Comparison is case-insensitive via `mb_strtolower`
and supports `equals`, `starts_with`, `contains`. An empty condition value
never matches, so a half-filled rule form cannot match everything.

The matching runs in PHP over the `mb_*` family rather than as a SQL
`LIKE`. That is a security property, not a performance choice: a
user-authored condition value never reaches a SQL pattern-match clause,
so `%` and `_` in a merchant name are literal characters rather than
wildcards.

**Amount** compares `settled_amount_minor` as an integer with `>`, `<`,
`equals` or `between`. A non-numeric stored value coerces to `0` rather
than throwing. `between` normalises its bounds with `min`/`max`, so a
rule authored with the bounds reversed still behaves as the user meant.

The bound is stored as bare minor units with no currency of its own, and
the rule form writes it at the reader's reporting currency scale. So the
comparison is made **only against rows settled in that same currency** —
a row settled elsewhere never matches an amount condition. Two minor-unit
integers from different denominations are not the same quantity: a bound
of `5000` written as EUR 50.00 would otherwise fire on a JPY 13,840
charge, and on a JPY 5,001 one worth about EUR 31. The rule falls silent
rather than converting, for the same reason a cross-currency roll-up
leaves out a currency it cannot price instead of counting it one to one
(see [minor units and zero-decimal
currencies](../ledger/minor-units-and-zero-decimal-currencies.md)).

That scope reaches the box the bound is typed in. The amount input on the
rule form kept a hardcoded `inputmode="decimal"` and a `0.00` placeholder
after the scoping landed, so a yen reader was invited to type a fraction
their own rule would refuse. Both now come from `MoneyInput` at
`BaseCurrency::value()` — the same currency `MapsRuleRows` parses the typed
figure at and `RuleEngine` tests rows against.

**Date** compares `posted_at` with `before`, `after` or `between`. Both
sides collapse to start-of-day first. That is what makes `between`'s
inclusive upper bound behave: without it, a transaction posted at 14:00
on the final day of the range would sort after a bound parsed as 00:00
and fall outside a range the user believes includes it.

A blank date value is refused before it is parsed, and the order matters.
`CarbonImmutable::parse('')` returns **now** rather than raising, so a
Date condition whose stored value is empty stopped being a date test at
all and silently became "posted today" — matching a different set of rows
on every run, and never the set its author wrote. `normalizeCondition()`
rejects an empty value on the write path, so the way in is a row written
around that path: a sync, or a restore. The column is `NOT NULL`, which
leaves the empty string as the only route and rules a null out.

A value that is *present but unreadable* is treated differently on
purpose: it still throws. `ReapplyRulesJob` catches that per row, counts
the row as errored and skips it, which is what keeps a malformed rule
visible to the operator. Turning that throw into a quiet `false` would
make a broken rule indistinguishable from one that simply matched
nothing — so the guard is on emptiness, never on parseability.

## Ordering actions within a rule

`ActiveRuleSet::actionsByRule()` reads every active rule's actions in one
query ordered by `position`, then `id`, and groups them under their owning
rule. As with rules, the `id` tiebreak matters because `position` has no
write-layer uniqueness.

They are read once for the life of the set rather than once per fired rule:
matching runs per transaction, so a query per rule per row cost 282.9 queries
for every row of a re-apply. See
[a read bounded by how much the user has](../../architecture/reads-bounded-by-the-user.md).

## Last writer wins

This is the part to get right.

`RuleApplier::applyAtReapply()` folds every matched rule's actions into a
map keyed by action type:

    $desiredByType[$action->type] = ['ruleId' => ..., 'action' => $action];

The assignment overwrites. Rules arrive in ascending `priority` order, so
for any given action type — `category`, `counterparty`, `note`,
`tax_tag` — the **last** rule to be visited is the one whose action
survives, and that is the rule with the **highest** `priority` number
(with the highest `id` breaking a tie).

So `priority` reads as "specificity", not as "urgency": a broad rule sits
at a low number and a narrow override sits at a high number. A reader who
assumes `priority = 1` means "wins" will author overrides that are
silently discarded — which is why `rule_form.priority_help`, in all 26
locales, names the highest number as the winner rather than stopping at
"lower numbers run first". That half-sentence was true and was the exact
half that invited the wrong conclusion.

Because the map is keyed by type, rules compose across types. A rule
setting only a category and a rule setting only a note both apply; they
only contend when they touch the same action type.

`applyAtImport()` reaches the same outcome by a different route: it folds
each action onto the `CanonicalTransaction` DTO in sequence, so a later
`withCategoryId()` simply overwrites an earlier one.

## What the two apply paths do differently

| | `applyAtImport` | `applyAtReapply` |
| --- | --- | --- |
| Target | in-memory `CanonicalTransaction` | a persisted row |
| Returns | the folded DTO | the fields actually changed |
| `tax_tag` action | ignored | applied |
| Provenance checked | no | yes — `manual` blocks the write |
| Events raised | no | `TransactionMutated` per field |

`tax_tag` does nothing at import because there is no persisted
`transaction_id` to attach a tag to yet; tagging waits for the re-apply
pass. A malformed action payload is logged and skipped on both paths
rather than aborting the transaction.

At re-apply, a field whose provenance is `manual` is skipped before any
write is attempted — see [Field provenance](field-provenance.md). Each
action type also reads its current stored value before writing, so a
repeat run over unchanged data reports nothing changed rather than
rewriting identical values; the reasons that read is necessary are on the
provenance page.

## Related pages

- [Field provenance](field-provenance.md) — what stops a rule from
  overwriting a value the user set by hand.
- [Re-applying rules to history](reapply-to-history.md) — the batch job
  that runs this evaluation over every existing transaction.
- [`Categorization` architecture](architecture.md) — the module boundary
  and public surface.
