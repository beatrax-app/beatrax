# A translated line has a call site

Every key in `Modules/<X>/Resources/lang/en/` is carried by twenty-six locale
files. A key nothing renders costs twenty-six translations, and no test could
see it: `TranslationParityArchTest` measures `en` against every locale in both
directions, so a key present in all twenty-six is in parity *by construction*.
Parity asks whether the translations agree. This asks whether anything reads
them.

`tests/Contracts/EveryTranslatedLineReachesAReaderArchTest.php` is what fails.

It is half the question. A key nothing declares has no line for this rule to
start from, and renders as itself to the reader —
[a call site names a key that resolves](a-call-site-names-a-key-that-resolves.md)
is the other half, and the two together are what "the copy is complete" means.

Both rules start from something already written down: a key, or a call. Neither
can see reader-facing English that never became either, and the forecast chart
named its two tooltip series `'Range'` and `'Point estimate'` in a PHP array for
that reason — see
[a payload no checker opens](invariants-from-shipped-failures.md#two-series-named-in-english-inside-a-payload-no-checker-opens).
A chart or JS payload is where that survives longest.

## What counts as a call site

`Modules\Core\Public\Support\Lang` is the only translator the app calls, but
the key it receives is often assembled rather than typed. The guard resolves
four shapes, and each one exists in this codebase:

| Shape | Example | Reaches |
|---|---|---|
| A whole literal | `Lang::get('chains::index.heading')` | that key |
| A branch node | `$reason->labelKey('anomaly::alerts.reasons')` | everything under it |
| A literal the code concatenates onto or interpolates into | `'recurring::fixed_payments.empty_'.$arm`, `"reports::index.summary.metric.{$key}"` | everything with that prefix |
| A bare group | `$window->labelKey('recurring::review')` | the whole file |

The second and third are the ones a naive scan misses, and missing them is
worse than not scanning: it reports a live line as dead. The enum that returns
`'recurring::fixed_payments.filter_'.($this === self::All ? 'all' : 'this_month')`
spells neither leaf, and a sweep that deleted them would have taken the
dashboard's filter labels and both of its empty states with it.

## What is not a call site

**A test.** A test that asserts a key has copy proves the key exists, not that a
screen renders it — and the assertion is usually written with the same
interpolation the screen uses, so a `"mobile::setup.{$group}.{$step}"` in a test
vouches for a whole subtree. Four `working.*` lines lived in twenty-six
languages for that reason, held up by a test and rendered by nothing.

**A compiled Blade cache.** `storage/framework/views/` keeps the last render of
a template that has since changed, so grepping it finds the call site the
template no longer has.

## When a line has no call site

The verdict is not automatically "delete". A group heading whose section moved
to another screen is a heading the reader lost, and rendering it where the
section landed is the fix. Read the commit that removed the call site before
choosing: a signpost removed on purpose — because its destination is in the
navigation — is dead, and a label dropped in a rebuild that nothing replaced is
missing.

## Related

- [A call site names a key that resolves](a-call-site-names-a-key-that-resolves.md)
  — the mirror rule, the eight keys it found, and the four shapes it must not report
- [Copy that carries a count](counted-nouns-in-copy.md) — `Lang::get` against
  `Lang::choice`, and why the numeral lives inside the line
- [Translations awaiting a native reader](translations-awaiting-a-native-reader.md)
  — the marker a machine-translated line carries
