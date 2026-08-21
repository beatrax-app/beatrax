# Copy that carries a count

Any line where a number and a noun appear together is written as a plural line,
not as a sentence with a number dropped into it. This page is the *how*; the
[field history](invariants-from-shipped-failures.md#a-count-beside-a-noun-that-never-declared-itself-plural)
is why, and `tests/Contracts/CountedNounDeclaresItsPluralArchTest.php` is what
fails when it is not.

## The seam

`Modules\Core\Public\Support\Lang` is the only translator views and components
call. It has two methods and the difference between them is the whole subject:

| Call | When |
|---|---|
| `Lang::get($key, $replace)` | The line's wording does not depend on a number |
| `Lang::choice($key, $number, $replace)` | It does |

`choice()` fills `:count` from `$number` itself, so a line whose only
placeholder is the count needs no `$replace` at all. Any *other* placeholder in
the line — `:peer`, `:year`, `:name` — is still passed explicitly.

## Writing the line

The English line carries both forms, separated by `|`, and uses `:count` in
each — never a literal `1`:

```php
'records' => 'Copied :count record from :peer.|Copied :count records from :peer.',
```

A literal `1` in the first segment is wrong in Croatian, Serbian, Ukrainian and
Lithuanian, whose first form also covers 21. `TranslationParityArchTest` fails
on it in a translated file **and in the English one**: the source is measured
against every locale's rule table, because the shape of the `en` line is what a
translator copies.

Every locale then needs **as many segments as its own rule table selects
between**, which is not a constant:

| Segments | Locales |
|---|---|
| 1 | `tr` |
| 2 | `bg da de el en es et fi fr hu it nb nl pt sv` |
| 3 | `cs hr lt lv pl ro sk sr uk` |
| 4 | `sl` |

Order follows the locale's own selector, and it is not always "singular first":
`lv` selects segment 0 for **zero**, segment 1 for 1 and 21, segment 2 for the
rest. Ask Laravel's `MessageSelector` rather than assuming — that is exactly
what the parity test does.

Segments may legitimately repeat. Hungarian and Turkish leave a noun unmarked
after a numeral, so both Hungarian segments are the same words; that is the
correct translation, not padding.

## Where the numeral lives

Inside the line. The number and the noun it governs are one translatable unit:

```php
'saved_report' => ':count saved report|:count saved reports',
```

```blade
{{ Lang::choice('reports::index.saved_report', $rows->count()) }}
```

Not `{{ $n }} {{ Lang::choice('…saved_report', $n) }}` over a bare `saved
report|saved reports`. That spelling pins numeral-then-space-then-noun in the
template, where no translator can reorder it or put a case ending on the
numeral, and it splits one phrase across a key and a template. `choice()` fills
`:count` itself, so the call site does not grow — it shrinks. Styling that was
aimed at the numeral alone, `font-variant-numeric: tabular-nums`, moves to the
element wrapping the whole phrase, where it still only reaches the digits.

The exception is a control whose visible content **is** the number: a sidebar
badge renders `{{ $navCounts['chains'] }}` and carries the counted phrase in its
`aria-label`. That is a label, not a sentence being assembled from parts.

## A sentence carrying two counts

`Lang::choice()` selects on one number. A sentence with two counts is written as
a frame plus one pluralised line per count, and the frame's placeholders receive
already-chosen phrases:

```php
'summary_updated'      => 'Updated :fields across :transactions.',
'summary_fields'       => ':count field|:count fields',
'summary_transactions' => ':count transaction|:count transactions',
```

```php
Lang::get('categorization::rules.summary_updated', [
    'fields' => Lang::choice('categorization::rules.summary_fields', $fieldsUpdated),
    'transactions' => Lang::choice('categorization::rules.summary_transactions', $transactionsUpdated),
]);
```

Each count reaches its own selector, and a translator can reorder the frame
because each placeholder holds a self-contained phrase. Keep the frame free of
case government where the target languages decline — a colon list reads well in
all of them.

## Rewording is a fix

Moving the noun out of agreement position is as legitimate as pluralising, and
is the better answer where no plural selector can run:

- `core::components.file.count` is interpolated by Alpine in the browser, where
  no locale rule table exists. It reads `Files selected: :count`.
- A badge whose entire content is a quantity often reads better as a label.

What is not a fix is a comparison in PHP. `count($x) === 1 ? Lang::get($a) :
Lang::get($b)` is English's two forms written into code that no locale rule can
reach, and the third arm of the arch test fails on it.

## Related

- [Invariants written after a shipped failure](invariants-from-shipped-failures.md)
  — what "1 errors" cost and why the parity test could not see it
- [Money representation](../features/ledger/money-formatting.md) — amounts follow
  the reader the same way, through a different seam
- [`Notifications` — copy that follows the reader](../features/notifications/reader-language-copy.md)
  — a stored notification keeps the count, not the chosen form, for this reason
