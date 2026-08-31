# Copy that carries a count

Any line where a number and a noun appear together is written as a plural line,
not as a sentence with a number dropped into it. This page is the *how*; the
[field history](invariants-from-shipped-failures.md#a-count-beside-a-noun-that-never-declared-itself-plural)
is why, and `tests/Contracts/CountedNounDeclaresItsPluralArchTest.php` is what
fails when it is not.

## The seam

`Modules\Core\Public\Support\Lang` is the only translator views and components
call. Which of its reads a line takes is the whole subject:

| Call | When |
|---|---|
| `Lang::get($key, $replace)` | The line's wording does not depend on a number |
| `Lang::choice($key, $number, $replace)` | It does |
| `Lang::arms(...$keys)` | It does, and the number is only known in the browser |

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

That table is a floor **and** a ceiling. A segment past the count a locale
selects between is text no number can reach: `trans_choice` asks the rule table
for an index and returns that segment, so a second Turkish form renders for no
count at all and reads to its author as though it shipped. The arch test fails
on a surplus segment as well as a missing one.

A wording the rule table genuinely cannot select — a distinct line at zero,
which no locale here selects on — is written as an explicit `{0}` range
instead. Those are matched against the number before the rule table is
consulted, so they are exempt from the ceiling. Bulgarian and Hungarian use
them for exactly that.

A range does **not** shift the segments after it. `MessageSelector::choose()`
tries every range first; failing that it calls `stripConditions()`, which
takes the prefixes off but keeps the whole list, and the rule index then
addresses that list. So `{0} …|A|B` in a two-form locale reaches `{0}` at 0
and `A` at 1, and `B` is dead. The ceiling is therefore not "count the
unranged segments" but "no segment past the count the table selects between,
unless it carries a range of its own" — which is what the arch test asserts,
using `MessageSelector`'s own range regex so the two spellings cannot drift.

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

An arm written to fit the template reads as finished, which is why this is the
half of the rule that had to be enforced rather than described. A line with
`|` in it and a numeral beside it is caught the same way a line without one is:
off the count in the variable name. `{{ $rowCount }} {{ choice('…row') }}` over
`ROW|ROWS` fails, and so does the same pair with the numeral in a `<span>` of
its own, which is the shape a `tabular-nums` or a `<strong>` puts it in.

Moving the numeral in moves that styling out to the element around the whole
phrase. `tabular-nums` reaches only digits wherever it sits, so it costs
nothing; a weight or a colour now covers the noun as well as the number, and
that is a layout decision to take rather than a reason to leave the numeral out.

The exception stays the exception. A badge whose content is the number keeps
its counted phrase in an `aria-label`, and the rule does not cross an opening
tag or a quote to reach it — nor a `·`, which is what separates one colon
label from the next.

## The word after the numeral is not always a noun

Everything above is written about nouns, and the defect does not stop at them.
`{{ $n }} {{ Lang::get('…detectors.large') }}` over a bare `large` is the same
mistake one word to the left, and it is *harder* to see: English adjectives do
not inflect, so "1 large" and "2 large" both read correctly and the source
language never complains. The same fragment agrees with its number in Czech,
Croatian, Polish, Slovak, Slovenian, Serbian and Ukrainian — Polish wants
`otwarte` at two and `otwartych` at five — and Lithuanian and Latvian inflect
it again on a different schedule.

The fix is the noun's fix, unchanged: the numeral moves inside the line, each
locale gets as many segments as it selects between, and the call site reads
`Lang::choice()`. The dashboard's unusual-charges tile assembles its
`·`-separated helper line that way, one chosen phrase per part:

```php
'open' => ':count open|:count open',
'detectors' => ['large' => ':count large|:count large', …],
```

Two identical English segments are not padding. They are English saying it has
one form here, in the one place a locale that has three can say otherwise —
and the [parity test](../../tests/Contracts/TranslationParityArchTest.php)
only reaches a key once the `en` line carries a `|`.

The noun rules cannot see this: they look for a plural noun after a count
placeholder, and `large` is neither. The rule that does see it reads the count
off the **variable name at the call site** — `$openCount`, `$n`,
`$preview->dedupedTotalCount`, off the last word either way — and fires when one
is set beside a translated line. There are two of those rules and they differ
only in what the line was read with:

| Beside a | Says | Fix |
|---|---|---|
| `Lang::get()` | The line has one form and a number is governing it | Give it arms, then choose it |
| `Lang::choice()` | The line has arms and the template kept the numeral | Move `:count` into the arms |

Both match the number-then-line order only, because a number that *follows* its
label is the reword, not the offence. Both refuse to cross a quote or an opening
tag, so a badge's `aria-label` is not read as the next badge's noun, and both
allow exactly one closing tag between the two — a numeral in its own `<span>`
is still standing next to the word it counts.

## A number the template formats

Both rules above read the count off a **variable name**, and there are three
spellings they cannot see: `Fmt::number($n)`, `number_format($n)` and
`count($rows)`. The count is inside the parentheses, and what stands beside the
translated line is a call.

```blade
{{ Fmt::number($mappingsCount) }} {{ Lang::get('community::settings.mappings') }}
```

That shipped `1 Mappings`. So did `<strong>{{ count($diff['new']) }}</strong>
{{ Lang::get('…diff_new') }}` over the fragment `'new,'`, and a prefix/number/
suffix trio over `'Matches'` and `'transactions in your recent history.'`.

A third rule matches the call rather than a name, because a formatted number is
unambiguous: nothing formats a value that way except to show a reader a
quantity. The fix does not change — the numeral moves into the line and the site
reads `Lang::choice()`, which fills `:count` through `Fmt::number()` itself, so
the grouping marks the call site was reaching for arrive with it:

```blade
{{ Lang::choice('community::settings.mappings', $mappingsCount) }}
```

Where the phrase carries a **second** number, that one stays a replacement and
the line is chosen on whichever number the wording agrees with:

```php
'backfill_progress' => ':fetched / ~:count message|:fetched / ~:count messages',
```

```blade
{{ Lang::choice('email-scan::inboxes.backfill_progress', $total, ['fetched' => Fmt::number($fetched)]) }}
```

A sentence built from a translated prefix, a number and a translated suffix is
the same rule's other half. The split pins numeral-then-noun for twenty-six
languages at once and hands each translator a fragment they cannot reorder,
decline, or move the numeral inside. Collapsing the three into one line is the
fix, and the rule sees it because the numeral is still standing beside a
translated line.

## A phrase carrying a count and a cap

"2/3 pinned" is worse than a bare adjective, because the word agrees with
neither number. Nothing in `Lang::choice()` fixes a template that has already
decided the ratio is two numerals with a slash between them; several languages
cannot assemble the phrase from a bare adjective at all.

One key carries both numbers. It is chosen on the number the wording actually
agrees with, and the other arrives as a replacement:

```php
'pinned_count' => ':count of :max pinned|:count of :max pinned',
```

```blade
{{ Lang::choice('reports::index.pinned_count', $pinnedCount, ['max' => TogglePin::MAX_PINS]) }}
```

The cap is a replacement rather than a literal for the reason every other
blade literal is: it is `TogglePin::MAX_PINS`, the constant the write
transaction enforces, `@use`d into the view. A `3` typed into the template is a
second copy that no longer moves when the first one does.

It is still a pluralised line even though English has one form and several
locales write their arms identically. French, Italian, Spanish, Portuguese and
Greek inflect the participle with the pinned count; Czech, Polish and Ukrainian
reach for an invariable impersonal and repeat it across their arms; Estonian,
Finnish, Hungarian and Turkish keep the ratio and a participle that never
moves. The seam has to be `choice()` because *some* reader's wording depends on
the number — a `Lang::get()` here would be wrong by construction for the first
group and merely redundant for the rest.

Both numbers reaching one key is also what makes the arms reachable. With a cap
of three, Slovenian selects its singular at 1, its dual at 2, its 3–4 form at 3
and its genitive plural at 0 — all four arms render.

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

## A count the browser works out

`Lang::choice()` needs the number, and a count Alpine tallies — rows matched,
digits typed, log files on disk — does not exist while PHP renders. That is a
real constraint and not a licence: the palette shipped "See all 1 results" to a
phone by concatenating a prefix, the number and a suffix in JavaScript, and no
rule reading lang files could see it because no lang file held a counted line.

Three answers, in order of preference.

**The count is bounded.** Render every announcement server-side and index it.
The lock screen's PIN pad holds at most ten digits, so it renders eleven lines
through `Lang::choice()` and Alpine picks one:

```blade
@php
    $digitAnnouncements = array_map(
        static fn (int $count): string => Lang::choice('mobile::lock.digits_entered', $count, ['count' => $count]),
        range(0, 10),
    );
@endphp
<div x-bind:aria-label="@js($digitAnnouncements)[pin.length]">
```

**The count is unbounded.** `Lang::arms()` hands the browser the line's arms and
the reader locale's own plural index table, and the `$plural` magic reads it:

```blade
<span x-text="$plural(arms, 'dev::palette.results', results.length)"></span>
```

Both halves come from Laravel's `MessageSelector`, so no plural rule is written
twice. The table holds one entry per number below a hundred and one per residue
above it, which is exact because no rule in `MessageSelector` compares the
number itself to anything that large — asserted in
[the test](../../Modules/DevMode/tests/Feature/ACountedPhraseTheBrowserAssemblesPicksTheReadersArmTest.php)
rather than trusted, because a rule added later that does would make every large
count pick the wrong arm. That test runs the shipped `resources/js/lang.js`
under Node against every locale and compares it to `trans_choice`.

A line read this way may not carry a `{0}` or `[2,*]` range: a range is matched
against the number before the rule table is consulted at all, and it can name a
number past the end of the table. `Lang::arms()` throws rather than ship an arm
chosen a different way in the browser than on the server.

**No count is involved, only a value.** A line whose placeholder is filled
client-side stays one line with the placeholder in it, read through `$line`:

```blade
<div x-text="$line(@js(Lang::get('dev::palette.no_transactions')), { query })"></div>
```

What is refused, in the browser as in PHP, is `n === 1 ? a : b`. It is English's
two forms written where no locale rule can reach them, and it passes an English
test while answering wrongly for Slovenian at 3, Polish at 5 and Latvian at 0.

Two arch rules hold the seam, both reading Alpine expression attributes: a
translated line joined by `+` to anything is a sentence assembled from
fragments, and an Alpine-rendered count standing beside a translated line in the
template is the numeral-then-noun order pinned one layer out from where the PHP
rules catch it.

## Rewording is a fix

Moving the noun out of agreement position is as legitimate as pluralising, and
is the better answer where no plural selector can run:

- `core::components.file.count` is interpolated by Alpine in the browser and its
  count needs no agreement at all. It reads `Files selected: :count`.
- A badge whose entire content is a quantity often reads better as a label.

What is not a fix is a comparison in PHP. `count($x) === 1 ? Lang::get($a) :
Lang::get($b)` is English's two forms written into code that no locale rule can
reach, and the third arm of the arch test fails on it.

## Related

- [Translations awaiting a native reader](translations-awaiting-a-native-reader.md)
  — the marker a translated line carries when its plural arms are the best form
  the author could defend rather than a checked one
- [Invariants written after a shipped failure](invariants-from-shipped-failures.md)
  — what "1 errors" cost and why the parity test could not see it
- [Money representation](../features/ledger/money-formatting.md) — amounts follow
  the reader the same way, through a different seam
- [`Notifications` — copy that follows the reader](../features/notifications/reader-language-copy.md)
  — a stored notification keeps the count, not the chosen form, for this reason
