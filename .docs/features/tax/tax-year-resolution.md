# Which tax year a tagged transaction counts toward

The obvious answer is "the year it was booked", and it is wrong often
enough that the module never asks the question that way. A December
invoice paid on 3 January belongs to the prior fiscal year. A refund
lands two years after the expense it reverses. Somebody filing in
February is working on last year's numbers, not this year's.

So a tag carries its own year, the cockpit defaults to a year that is not
necessarily the current one, and a leg-scoped tag reports the leg's
amount rather than its parent's. This page is the three of those and the
one deliberate non-error they share.

## Three rules, written down once

Every read resolves the year the same way:

```sql
COALESCE(tag.tax_year_override, CAST(strftime('%Y', t.booked_at) AS INTEGER))
```

An explicit `tax_year_override` on the tag always wins; otherwise the
booked date's year is used. The override lives on the tag, not on the
transaction, so reassigning a payment to another tax year never rewrites
the ledger — the transaction keeps the date it actually has, and only the
tax view moves.

Three surfaces read tagged rows: `TaxYearQuery::forUser()` (the cockpit,
and the CSV and PDF through it), `TaxYearQuery::availableYears()` (the
year switcher) and `TaxTagQuery::summaryForUser()` (the dashboard card).
Each of them must apply that year expression, [the leg-aware
amount](#amounts-follow-the-tags-scope-not-the-transactions-total) and
[the supersession filter](tag-write-contract.md#supersession) — and all
three now come from `Internal\Support\TaggedRowScope`, the only place
this module writes any of them. `Core`'s `NavCountsService` keeps a
fourth copy of the supersession filter for the sidebar badge: it counts
the same rows from outside the module, where `Internal` cannot be
reached.

They were written out per query instead, and the wording here said
keeping the copies identical was what stopped the drift. It did not. The
dashboard card was missing two of the three rules outright: it counted a
whole-tx tag the cockpit had superseded, and it summed the parent's
`settled_amount_minor` for a leg-scoped tag. The card read €1,362.39 over
19 items beside a cockpit reading €1,137.89 over 18 — the same €124.50
parent counted twice where the cockpit counted its €24.50 leg once. The
year switcher was missing the supersession filter too, so it offered a
year the cockpit then rendered as "€ 0,00 · 0 items". Three copies
agreeing is not the same as one rule, and a query that reaches for the
tag table without going through `TaggedRowScope` is the next one to drift.

`TagTransaction` bounds the override to ±10 years of
`Clock::now()->year` and throws `InvalidArgumentException` outside it —
wide enough for any real amendment, narrow enough that a typo'd year
cannot create a phantom entry in the year switcher a decade out. Time
arrives through the injected `Clock` so the boundary is testable with the
standard clock fake.

## The default year is seasonal

With no `?year=` in the URL, the default year resolves January–April to
the *previous* year and May–December to the current one, matching the
Dutch `aangifte` filing season: someone opening `/tax` in February is
almost certainly working on last year's return.

This rule is written down once too, in
`FilingSeason::defaultYear()`. `TaxPage::mount()` (the cockpit),
`TaxSummaryCard::render()` (the dashboard card) and
`HandlesTaxTagging::resolveCurrentTaxYear()` (the tag picker's fallback)
all read it from there, and a unit test fails if any of them spells the
comparison out again. Three copies agreeing because someone pasted the
same line is not the same as agreeing by construction: the boundary month
could move in one and not the others, and the split would only be visible
between January and April.

`#[Url(as: 'year', except: 0)]` makes the resolved year deep-linkable and
back-button-safe. The `0` sentinel means "not yet resolved", which is why
`mount()` only computes the seasonal default when `$this->year === 0` — a
real `?year=` must survive mount untouched.

This seasonal year is **not** the year the batch-tag banner counts
against; that one is keyed to the row you just tagged. See
[the batch-tag suggestion](batch-tag-suggestion.md).

## Amounts follow the tag's scope, not the transaction's total

`TaxYearQuery::forUser()` LEFT JOINs `transaction_splits AS ts` on
`tag.transaction_split_id` and reports
`COALESCE(ts.settled_amount_minor, t.settled_amount_minor)`. For a
whole-transaction tag every `ts.*` column is NULL and the COALESCE falls
back to the parent; for a leg-scoped tag it reports the leg's own amount.

This is a correctness requirement rather than a nicety. An €80
transaction split €60 Groceries (tagged deductible) / €20 Household (not
tagged) must export **€60** — not €80, which over-claims, and not €0,
which loses the deduction entirely. See
[`Ledger` architecture](../ledger/architecture.md) for the split model
these legs come from.

The CSV's `original_amount` obeys the same rule, and needs its own
arithmetic to do so. `transaction_splits` records only the settled slice
— there is no native amount on a leg — so `TaxYearQuery::mapRow()`
derives one: the leg's native amount is the share of the parent's
`amount_minor` that its `settled_amount_minor` is of the parent's,
rounded half away from zero in integer arithmetic. For the common
single-currency row that reproduces the leg exactly; for a $100 charge
that settled at €90, a €30 leg reports $33.33. Reading `t.amount_minor`
straight through instead put the whole parent's `124.50` beside a
leg-sized `24.50` — on the one row shape an accountant opens directly.

Two more rules govern the totals:

- Every amount is summed from `settled_amount_minor`, an integer count of
  minor units. No monetary value in this class is ever a float. See
  [money representation](../ledger/money-formatting.md).
- `abs()` converts the signed stored amount to the unsigned "you spent X"
  figure the UI shows. Rows with `t.type = 'income'` accumulate into
  `incomeTotalMinor`, everything else into `deductionsTotalMinor`.

The category name shown for a leg-scoped tag always resolves from
`tag.deduction_category_id` — the tax deduction category — never from the
leg's own `category_id`, which is the spend category on
`transaction_splits`. They are different concepts with similar names and
conflating them puts a grocery category on a tax return.

### `summaryForUser()->totalMinor` is the deductions total only

`TaxYearSummary::$totalMinor` sums non-income rows and nothing else:

```sql
SUM(CASE WHEN t.type = 'income' THEN 0
         ELSE ABS(COALESCE(ts.settled_amount_minor, t.settled_amount_minor)) END)
```

Reading it as "all tagged money in the year" is the miscomputation to
avoid. It matches the cockpit's "Total deductions" KPI — the `CASE`,
the leg-aware `COALESCE` and the supersession filter are the three
reasons it does, and the card lost that agreement each time one of them
was missing. Folding income and deductions into one absolute sum produced
a figure that matched neither number on the page, which is what the
`CASE` exists to prevent. `TaxYearSummary::$count`, by contrast, does
cover every tagged item regardless of type — so the total and the count
deliberately do not describe the same set of rows.

## A year with no tags is not an error

`TaxYearQuery::forUser()` returns a `TaxYearData` with zeroed totals and
`categories: []` before it builds anything. `TaxCsvExporter::export()`
writes its 17-column header first and only then iterates, so an empty
year produces a valid header-only CSV rather than an empty file or an
exception. The PDF renders its empty table the same way.

That is deliberate. The year switcher and `?year=` deep links let a user
land on any year at all, including one before they had any data and one
they simply never tagged. Throwing there would turn a legitimate URL into
a broken page, and refusing the export would make "nothing was
deductible" indistinguishable from "the export is broken". A header-only
CSV opens in a spreadsheet and says, correctly, that the year is empty.

## Related

- [Tag write contract](tag-write-contract.md) — what a tag attaches to,
  and why the payload is written whole.
- [The batch-tag suggestion](batch-tag-suggestion.md) — the year that
  banner keys on, and why it is not this one.
- [`Tax` architecture](architecture.md) — the module surface as a whole.
- [`Ledger` architecture](../ledger/architecture.md) — transactions,
  splits and legs.
