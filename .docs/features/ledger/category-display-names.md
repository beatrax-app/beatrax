# `Ledger` — category names: what is stored versus what is shown

`categories.name` is not the string the reader sees. For a default
category it holds canonical English and the screen shows a translation
resolved per reader; for a category the user named or renamed it holds
their own words and the screen shows exactly those. One class decides
which of the two applies, and any code that reads, compares, or
searches a category name has to go through it.

This page exists because the distinction is invisible at the call
site: `SELECT name FROM categories` returns a plausible-looking string
in every case, and a query written against it ships working in English
and broken in the other twenty-five languages.

## The two states of the column

`Categorization`'s `DefaultCategoryTreeSeeder` writes the shared
category tree once, with `user_id = NULL`. Those rows used to be
seeded in whatever language the account was created in, which froze
them there forever — an account created in Dutch showed Dutch
categories to a reader who later switched to English, and no rename
surface existed to fix it.

Now the seeder writes the app's own English wording and sets
`categories.name_is_default = true`. The boolean is the whole
distinction:

| `name_is_default` | What `name` holds | What the reader sees |
|---|---|---|
| `true` | The app's canonical English for the row's `slug` | `categorization::categories.<slug>` in the reader's language |
| `false` | The user's own words | The same words, in every language |

The column defaults to `false`, so a row of unknown provenance — an
imported tree, a row some future writer creates — keeps its name
verbatim. That is what every row did before this existed, so nothing
silently changes meaning.

## The seam for what it is called: `CategoryDisplayName`

`Modules/Ledger/Public/Support/CategoryDisplayName` is the only place
that asks the question. It is `Public`, so every module may use it
directly.

```php
CategoryDisplayName::resolve($storedName, $slug, $nameIsDefault): string
CategoryDisplayName::columns($table, $alias = 'category'): list<string>
CategoryDisplayName::bareColumns($table = ''): list<string>
CategoryDisplayName::fromRow($row, $alias = ''): ?string
CategoryDisplayName::isDefaultRow($row, $alias = ''): bool
CategoryDisplayName::displayNamesBySlug(): array<string, string>
```

- **`resolve()`** is the rule itself. A rename or a row with no slug
  returns `$storedName` untouched. A default resolves
  `categorization::categories.<slug>` — and falls back to the stored
  English when that slug has no translation, so a category tree that
  grows a new slug renders English rather than a raw translation key
  on a budget screen. The rule is no longer written here: it lives in
  `Modules\Core\Public\Support\SeededDisplayName`, because two more
  seeded tables obey it — `currencies` by code, and
  `tax_deduction_categories` by corpus key ([deduction category
  wording](../tax/deduction-category-wording.md)). This method is the
  category-shaped way in.
- **`columns()`** spreads the three columns a join has to select
  (`name`, `slug`, `name_is_default`) with a shared alias prefix, so a
  query selecting two categories in one row — a category and its
  parent — can keep them apart. An empty alias is refused rather than
  emitted: it would select `_name`, which the bare `fromRow($row)`
  cannot see.
- **`bareColumns()`** is the same three parts unaliased, for a query
  selecting straight off `categories` with nothing to disambiguate,
  and for the `GROUP BY` beside such a select when `$table` is given.
  It pairs with the default `fromRow($row)`.
- **`fromRow()`** reads those three back off a `stdClass` and calls
  `resolve()`. It returns `null` when the row carries no name at all,
  which is how a `LEFT JOIN` miss stays distinguishable from a
  category genuinely named the empty string.
- **`isDefaultRow()`** hands back the flag on its own, for a caller
  that carries the provenance somewhere else instead of rendering it
  here — copy resolved in the reader's language rather than the queue
  worker's. `BudgetProgressQuery::expenseCategoryNaming()` and
  `DemoNotificationsSeeder` read it to keep a name resolvable at display
  time.
- **`displayNamesBySlug()`** inverts the question: instead of "what does
  this row show", "which slugs show what". It walks the keys of
  `categorization::categories` in the reader's language and resolves each
  through `resolve()`, so a slug with no line for the reader is absent —
  such a row shows its stored `name`, which is a column and matches in
  SQL. It exists for the search sites below, and it reads no database.

`Modules\Ledger\Models\Category` exposes the same answer as a
`displayName` attribute for Eloquent reads.

## The rule for anyone writing a query

**Never match, sort, or group on `categories.name` alone.** SQL cannot
see the translated string, because the translated string is not in the
database — it is in `Modules/Categorization/Resources/lang/<locale>/
categories.php`, keyed by slug.

Two shapes work, and which is cheaper depends on the query:

1. **Resolve, then compare in PHP.** Select
   `CategoryDisplayName::columns(...)` for the candidate rows, resolve
   each, and match. Simple, and right for a query that was going to read
   the rows anyway — a picker listing every category, say.
2. **Name the matching slugs first, then match in SQL.**
   `displayNamesBySlug()` says which slugs a term matches without touching
   the database; the row match is then
   `name LIKE ? OR (name_is_default AND slug IN (…))`, which is one
   bounded statement and keeps whatever `LIMIT` the query had.

Shape 1 costs the whole visible category set per call, so it belongs
nowhere a keystroke reaches. **Both Search sites use shape 2**: matching
moved to PHP when this seam landed and took their `LIMIT 3` with it, and
the ⌘K palette went from reading three category rows to reading every one
the user can see, on every character typed. The two arms are exactly the
union the PHP loop computed — a default row matches on its translation, a
row of any provenance matches on its stored `name` — with the one
difference that SQL's `LIKE` folds case for ASCII only, which is what
every other branch of `EntityNameSearch` and `resolveAccountNamesToIds`
already do.

**Keep matching the stored name as well.** Someone who has seen the
English name, or who types a slug-ish term, should not suddenly fail;
and it is the only thing that matches a renamed row. The reader's own
language has to work first, and the stored name is an addition to
that, never a substitute for it.

## Where this already bites

`Search` is the module that had both halves of the mistake, and it is
the shape to recognise:

- `SearchTokenFilters::resolveCategoryNameToIds()` backs the `category:`
  typed token. Matched against `name` alone, a Dutch reader searching
  `category:Boodschappen` found nothing while `category:Groceries`
  worked — the exact inversion of what was on their screen.
- `EntityNameSearch::categoryMatches()` backs the ⌘K palette's
  category section. Same inversion.

Both resolve the slugs first and match in SQL, which is shape 2 above;
`CategorySearchStaysBoundedTest` counts the rows each site reads and pins
the answers.

Both are pinned by
[`CategoryNameSearchFollowsTheReaderTest`](../../../Modules/Search/tests/Feature/CategoryNameSearchFollowsTheReaderTest.php),
which asserts each direction separately: the reader's language
narrows, the stored English still narrows, and a renamed category
narrows on its rename rather than on its slug's translation.

Read sites come in two shapes, and which shape a query is decides which
method it calls. A query joining a category onto another table —
`TransactionListQuery`, `CategoryOptionsQuery`, `PotBalanceQuery`,
`CounterpartyProfileQuery` — spreads `columns($table, $alias)` and reads it back with the matching
`fromRow($row, $alias)`. A query selecting straight off `categories` —
`CashBookPage`, `ReportBuilder`, `EntityNameSearch`, `SearchQuery`,
`CategorySpendTrendQuery`, `CategoryAncestry`, `TransactionsList`,
`BudgetProgressQuery` —
spreads `bareColumns()` and reads it back with the default
`fromRow($row)`. The two Search sites do both: they select the bare
columns for the rows SQL narrowed to, and they call
`displayNamesBySlug()` to do the narrowing.

`bareColumns()` exists because the seam used to cover only half its own
read sites: `columns($table, '')` emitted the `_name`, `_slug`,
`_name_is_default` prefix that `fromRow($row, '')` does not read, so
bare selects had no seam to call and rolled their own — one of them
written as raw SQL in a `selectRaw()`. Both shapes are
protected now. `aliasedValues()` throws when a column list and the
reader consuming it drift apart, and
[`CategoryColumnsRouteThroughTheSeamArchTest`](../../../tests/Contracts/CategoryColumnsRouteThroughTheSeamArchTest.php)
refuses a hand-written list anywhere in backend source — it derives the
parts it looks for from `bareColumns()`, so it keeps meaning what it
says as `PARTS` grows.

## Which one of them is it: `CategoryPathName`

`CategoryDisplayName` answers *what is this row called*. It does not answer
*which* row, and two categories may legitimately resolve to the same name —
a migrated tree and the seeded one can each hold a `Groceries`, and a leaf
like `Other` says nothing at all without the group above it. A flat list of
either is a row the reader picks blind, which on a budget screen is money
assigned to the wrong envelope.

`Modules/Ledger/Public/Support/CategoryPathName` is the seam for the
qualified form. It is `Public`, and it holds the separator so that one
category reads the same on every screen:

```php
CategoryPathName::SEPARATOR                                  // ' › '
CategoryPathName::join(?string $parent, string $leaf): string
CategoryPathName::fromParts(list<string> $parts): string
CategoryPathName::joinParent($query, $userId, $child, $parent)
CategoryPathName::columns($childTable, $parentTable, $alias = 'category')
CategoryPathName::fromRow($row, $alias = 'category'): ?string
```

- **`join()` / `fromParts()`** are the string half. A parent that resolved
  to nothing is the same answer as no parent, so a leaf whose parent is out
  of the reader's view renders bare rather than with a dangling separator.
- **`joinParent()`** is the query half: a `LEFT JOIN` onto `categories`
  carrying the same visibility predicate as the child. Omitting that
  predicate prints another tenant's category name in front of the reader's
  own, which is what two hand-written copies of this join did.
- **`columns()` / `fromRow()`** wrap `CategoryDisplayName`'s pair for the
  child and the parent under one alias, so a read site adds the group with
  two edits rather than six.

- **`distinct()`** is the answer for when the group runs out. Two categories can
  qualify to the *same* path — both top level under one name, or two leaves
  under groups named alike — and then there is no further ancestor to add. It
  takes `id => path` and hands back `id => label` with no two labels alike,
  numbering by id ascending so the lowest id keeps its bare name. It re-checks
  each label it mints, so a category genuinely called `Groceries (2)` does not
  lose its own name to the suffix.

The separator is not `/`. `Rent / Mortgage` and `Cloud / Software` are
seeded category names, so `Housing / Rent / Mortgage` reads as three levels
when it is two.

### Where `distinct()` is applied, and where it is not

The ordinal is a property of the *set*, not of the row, so it is applied where a
set of categories is rendered — and every one of those sites reads the reader's
whole visible tree, which is what makes them agree with each other:

| Site | What it feeds |
|---|---|
| `CategoryOptionsQuery` | The ledger row picker, the split-leg picker, triage, the rule form |
| `CashBookPage` | `/cash`'s entry picker |
| `TransactionFilterOptions` | The ledger's category chip |
| `ReportBuilder::availableCategories()` | The report category filter |
| `BudgetProgressQuery::expenseCategoryNaming()` | The envelope grid and the move-money destinations |
| `CategorizationRuleQuery::resolveCategoryPaths()` | The rules list and the provenance panel |
| `CategorySpendTrendQuery::categoryNames()` | The trend legend |

Two of those read wider than they answer. The rule query and the trend query
were narrowed to the ids their caller asked about; both now read every visible
category and filter afterwards, because an ordinal counted over a subset is a
different number from the one the picker beside it shows.
`expenseCategoryNaming()` does the same across `kind` for the same reason.
`TransactionDetail` no longer reads its own current category either: it names it
off the option list its own leg pickers offer.

`EntityNameSearch` is the exception, and deliberately. Its category read is
bounded to three rows and `CategorySearchStaysBoundedTest` counts them — the
palette runs on every keystroke, and widening it to number the labels would cost
the whole category table per character. It disambiguates across what it returns.
Both rows of a colliding pair match the same term and the query takes the lowest
ids, so the bounded set holds them together in practice.

Sites whose unit of listing is **not** a category keep the plain path: a
transaction list, a pot row, a counterparty's category breakdown. Two rows there
naming one path are two transactions, not two things to choose between, and the
picker mounted on the ledger row is itself disambiguated.

The other half of this is that a same-path pair should mostly not exist. The
migration promoter matches a staged category onto one the reader already has at
that path rather than creating a second — see
[`Migration` architecture](../migration/architecture.md). What is left for
`distinct()` is a pair arriving by sync from a peer, an install that imported
before that fix, and a locale switch: two categories can read differently in
Dutch and identically in English, since only one of them re-resolves per
reader.

Deeper than one level — a full breadcrumb — is
`Modules\Ledger\Public\Services\CategoryAncestry`, which walks the parent
chain in one batched read, applies the visibility predicate at every level,
guards against cycles, and joins with the same separator. Reports and the
budgets grid use it; a read that already joins `categories` once and only
needs the group uses `joinParent()` instead.

## Migration

`2026_08_21_000020_add_name_is_default_to_categories` adds the column
and backfills it. It carries its own frozen copy of the seeder's
wording rather than importing the seeder's array, because what the
backfill needs is the wording the rows already out there came from,
not whatever the seeder says today.

It only touches `user_id IS NULL` rows: no rename can have reached a
global row, since the seeder writes the name once at creation, the
migration importer scopes every write to the user's own id, and no
rename UI exists for the shared tree. `down()` leaves the English in
place deliberately — re-guessing a locale to translate it back into is
how the original bug started.

## Related

- [`Ledger` architecture](architecture.md) — the `categories` table and
  the queries that read it.
- [`Categorization` architecture](../categorization/architecture.md) —
  the default tree, the seeder, and the rule engine that assigns
  categories.
- [`Search` architecture](../search/architecture.md) — the typed-token
  parser and the palette's entity section.
- [Invariants from shipped failures](../../conventions/invariants-from-shipped-failures.md)
  — the collected shape of bugs like this one.
