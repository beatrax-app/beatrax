# A check names the value it tests

A test for "is there anything here?" is written as an identity comparison
against the value the variable actually holds. `empty()`, `is_null()` and `==`
are not used.

```php
// no
@if (! empty($availableAccounts))
@if (! empty($row['note']))
if (is_null($rate))
if ($transaction->counterparty_id == $counterparty->id)

// yes
@if ($availableAccounts !== [])
@if (($row['note'] ?? '') !== '')
if ($rate === null)
if ($transaction->counterparty_id === $counterparty->id)
```

`ACheckNamesTheValueItTestsArchTest` enforces it over every PHP file the
repository walks, Blade templates included.

## Why

`empty()` is one spelling for six different states. It is true for `null`, for
`''`, for `'0'`, for `0`, for `0.0`, for `[]` and for `false`. A reader who
finds `! empty($row['note'])` cannot tell from the line which of those the
author meant to exclude, and neither can the next person to change the type of
`note`. The identity comparison says which one, and it stops being true when
the type moves under it — which is the point.

The `'0'` case is not hypothetical trivia. A string field holding the
character zero is a value a reader typed, and `empty()` discards it. Every
conversion in this tree was made against the declared type of the value rather
than against the shape of the line, precisely because that is the question
`empty()` refuses to ask out loud.

`is_null($x)` and `$x === null` ask exactly the same question, so the second
spelling buys nothing and costs a reader the moment it takes to notice the two
are the same. `==` asks a *different* question from `===` while looking like a
typo for it.

## What the tree already did

This was not a rule being imposed on a divided codebase. It was a rule the PHP
already followed and the Blade did not:

| Spelling | Production PHP | Test suites | Blade |
|---|---|---|---|
| `===` | 4,999 | — | — |
| `!==` | 2,714 | — | — |
| `=== null` | 1,804 | 348 | — |
| `=== ''` / `!== ''` | 1,362 | — | — |
| `=== []` / `!== []` | 585 | — | — |
| `empty()` | 0 | 0 | 46 |
| `is_null()` | 0 | 0 | 2 |
| `==` / `!=` | 0 | 0 | 1 |

Every one of the 49 offenders was in a `.blade.php` file, across 16 templates.
Not one PHP class in 9,835 walked files used a coercing check. The divergence
was between the two halves of the same codebase, and a template is the half
where the type of a variable is hardest to see — which is the worst place for
the spelling that hides it.

## The three shapes a conversion takes

The replacement is not one substitution, because `empty()` was not one
question. Each site was converted against the declared type of what it tests:

- **An array** — `! empty($filterAccounts)` became `$filterAccounts !== []`.
  Where the site already wrote `$filterAccounts ?? []`, the coalesce proves the
  type at the call site and the conversion is mechanical.
- **A string** — `! empty($row['note'])` became `($row['note'] ?? '') !== ''`.
  The coalesce is load-bearing: the key is optional in the array shape, and
  `empty()` was silently absorbing its absence.
- **A nullable timestamp** — `! empty($row['createdAt'])` became
  `($row['createdAt'] ?? null) !== null`. These are unix seconds read from the
  queue tables; `empty()` would additionally have discarded `0`, which is an
  instant this application never writes.

## Exemptions

None. There is no value `empty()` can test that an identity comparison cannot,
including a variable that may be undefined: `($x ?? []) !== []` answers that
one without a warning.

A method or a constant *named* `empty` is untouched and is not what this rule
is about — `RateSet::empty()`, `SearchFilters::empty()` and
`PreviewSectionStatus::Empty` all name a value rather than test one. The guard
separates them with the tokeniser rather than with a pattern: `empty` after
`::`, `->`, `function`, `const` or `case` is a name, and only a bare one is the
language construct.

## The Blade half is why this is tokenised

A `.blade.php` file ends in `.php`, so it lands in every PHP walk, and
`token_get_all` reads a whole template as one `T_INLINE_HTML` and reports it
clean without reading it. Since all 49 offenders lived in exactly those files,
a guard written the obvious way would have passed on day one and said nothing.
It reads every file through `Modules\Core\Public\Support\BladePhpSource::forPath`
instead, which answers a template with its PHP islands on the lines Blade wrote
them.
