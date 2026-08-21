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

`RuleEngine::match()` reads the candidate set with:

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

**Date** compares `posted_at` with `before`, `after` or `between`. Both
sides collapse to start-of-day first. That is what makes `between`'s
inclusive upper bound behave: without it, a transaction posted at 14:00
on the final day of the range would sort after a bound parsed as 00:00
and fall outside a range the user believes includes it.

## Ordering actions within a rule

`RuleEngine::actionsFor()` reads a fired rule's actions ordered by
`position`, then `id`. As with rules, the `id` tiebreak matters because
`position` has no write-layer uniqueness.

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
silently discarded.

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
