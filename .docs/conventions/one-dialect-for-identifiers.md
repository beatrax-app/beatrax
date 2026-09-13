# One dialect for identifiers, another for the reader

**A name the machine resolves is American. English a reader is shown, and the
prose explaining it, is British.**

| Surface | Dialect | Examples |
|---|---|---|
| Class, interface, trait, enum names | American | `BiometricEnrollmentOutcome`, `ThemeColor` |
| Method, function, property, variable names | American | `normalizedDayOrNull()`, `$labeler` |
| Enum cases, constants, namespaces | American | `CounterLegOrder::NearestToCenter`, `ARTIFACTS_MAIL` |
| Config keys, translation **keys** | American | `'section_organize'`, `'not_labeled_heading'` |
| File names, which follow the class | American | `ColdStartEnrollmentFlag.php` |
| Translation **values** in `lang/en` | British | `'ORGANISE'`, `'Winter tyres'` |
| Every other string a reader is shown | British | button copy, error sentences, help text |
| Comments and docblocks | British | "the colour of a row is the behaviour…" |
| `.docs/` prose | British | this sentence |

One translation line carries the whole rule, and it is the line the guard reads
back:

```php
'section_organize' => 'ORGANISE',
```

## Why American won the identifier half

The most visible identifier in the tree already decided it. The module is called
`Categorization`, and that name is load-bearing in a way a variable is not: it
is the first segment of every namespace under it, the name of its service
provider, the directory the autoloader resolves, and the anchor of every
`.docs` page and `@link` that points into it. Renaming *that* to settle the
question would touch every namespace, provider and doc reference in the
application — and would still leave the reader's English needing a dialect of
its own.

So the cheap half was already fixed, and the rule follows it.

## Why British won the reader half

Beatrax is written for a European reader, ships in twenty-six languages, and its
English is the source those translations are made from. `colour`, `behaviour`
and `organisation` are what that reader expects to see, and what the twenty-five
other locales were translated against. Nothing about a symbol's spelling reaches
a screen, so the two halves never have to agree.

## What the split is not

**It is not a licence to rename a persisted name.** A spelling is never worth a
migration, and three kinds of name are excluded for that reason:

- **Column names.** `cancelled_at` is a column, so `cancelled` stays — in the
  column, and in every property, variable and constant that names it, because
  those agreeing with the schema matters more than the dialect. `cancellation`
  doubles its `l` in both dialects and was never at stake.
- **Values that outlive the process.** `SyncListenerProcess` keeps a durable
  store key spelled `sync-listener:credentialled-device`. The constant naming it
  became `CREDENTIALED_DEVICE_KEY`; the string it holds did not, because a
  device that already wrote the old key still has to be found under it.
- **A vendor's own symbols.** PHPStan declares `PHPStan\Analyser`. Renaming a
  reference to somebody else's class only stops it resolving.

**It is not the same as "maximally American".** The one place the convention
changed a name a reader can see is the export archive, whose per-location
folders now read `artifacts/artifacts_imports/`. Nothing reads that archive
back, so no reader loses anything they had.

## How it is held

### The guard

`tests/Contracts/AnIdentifierIsAmericanAndItsCopyIsBritishArchTest.php` walks
every PHP file in the repository, takes the identifier tokens out of each one,
and splits them into words. Three properties matter:

- **Comments and strings are unreachable by construction.** Comments are dropped
  from the token stream and a quoted string is never an identifier token, so the
  two British surfaces are not excluded by a list that could rot — the reader
  cannot see them at all.
- **Blade islands are read.** `BladePhpSource::forPath()` lifts the PHP out of
  `@php`, `{{ }}` and a directive's arguments first. Two call sites were renamed
  in PHP and missed in their template during this very change, because
  `token_get_all` reaches an `@if (…)` as one run of inline HTML.
- **A word is only ever matched whole.** `CounterpartyResolver` holds the
  letters of `tyre` across the seam between `Counterparty` and `Resolver`, and a
  substring reader renames it.

The irregular words are a named list, because no rule derives `labelled` from
`label` without also deriving `controller` from `control`. The two productive
families — `-ise/-isation/-iser` and `-our` — are matched instead, with the far
shorter list of American words that happen to fit the shape named as exceptions.
A list alone would go blind to the next `harmonise` nobody thought to add.

The rule's other half is read from a live translation file rather than a
fixture, and the reader is then run against that file's own British value. A
guard whose British half is only ever exercised on a planted string cannot tell
you it still recognises British.

### Why the spell-checker does not hold it

`typos.toml` sets no `locale`, deliberately. typos reads a file as a stream of
words with no idea which of them are code, so `--locale en-us` reports the
British comments and the British copy along with everything else. Measured on
this tree with the identifier half already converted, it returns **1,741 hits:
870 in comments, 757 in string literals, 114 in Markdown and config prose, and
zero in an identifier and zero in a file name** — every one of them correct.
A hundred and twelve of those strings are the guard's own list of the words it
forbids.

Its dictionary would not have covered the identifier half either. It accepts
`authoriser`, `mislabelled`, `tokenising`, `credentialled`, `uncategorised`,
`relabelling`, `unlocalised` and `memoised`, all of which this change converted
and all of which the guard rejects.

## Related

- [Conventions index](00-index.md)
- [English a locale is allowed to keep](english-a-locale-is-allowed-to-keep.md)
  — the other direction: when a translated value is right to match the English
- [A translated line has a call site](a-translated-line-has-a-call-site.md) —
  how a key is assembled, which is what makes a key an identifier
