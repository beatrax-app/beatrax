# English a locale is allowed to keep

Key parity is perfect: every one of the 4,096 English keys has a value in all 25
other locales, and no locale carries a key English does not. What parity cannot
see is a value that is *there* and is still the English one — same key, same
placeholders, same plural segments, and the reader gets a Dutch page with an
English word in it.

Measured through Laravel's own loader rather than a grep — `Lang::getLoader()
->namespaces()` for the groups and `Lang::get($group, [], $locale)` for the
values, so the comparison is the string the app actually renders — **277 keys**
were byte-identical to the English in at least one locale while five or more
sibling locales had translated the same key.

Run the same comparison before trusting any count, and check the translator is
not simply falling back: `Lang::get('ledger::detail.type_label', [], 'nl')` has
to come back Dutch. If it comes back English every comparison in this page is
vacuous.

## Most of them are correct

A value identical to English is the *normal* case for a large part of the copy,
and translating one is a regression. Five reasons carry all of them, and they
are the groups `FROZEN_LINE_PINS` in
`tests/Contracts/ALineTheOtherLocalesTranslatedIsNotLeftInEnglishArchTest.php`
is keyed by:

| Reason | What it covers |
|---|---|
| An acronym, an initialism, or an abbreviation | `ECB`, `OK`, `PIN`, `txn`, `args`, `Min`, `Max`, `info`, `±10%`, `vs :label` |
| A proper noun | every country name, `Euro`, `Dev Console`, `Artisan runner`, `via Enable Banking`, `Open Azure Portal` |
| The label the provider prints in its own console | `Client ID`, `Client secret`, `Application (client) ID`, `Client secret value`, `Redirect URI` |
| The word this locale actually uses | `Status` in Danish, `Date` in French, `Password` in Italian, `Streaming` in Dutch, `Open banking` in most of these markets |
| A term this locale's own neighbouring copy also keeps in English | `SAFE`, `DESTRUCTIVE`, `Queue` in German, `exit` in eight locales |

The provider-label row is the one that looks most like a defect and is not. A
reader following the Gmail wizard is looking for a field on Google's own screen;
a translated `Client secret` is a field they cannot find. The same holds for
Azure's `Application (client) ID`.

The last row is the subtlest, and it is a judgment about a *page* rather than a
word. German keeps `Queue` on the nav item because the German Dev Console copy
around it says `Queue-Inspektor`, `Queue-Worker` and `Die Queue ist leer`;
translating the one line would leave that page speaking two vocabularies.
`SAFE` and `DESTRUCTIVE` are the same shape — fifteen locales keep the badge in
English *and* write `SAFE-Befehle` / `comandi SAFE` / `polecenia SAFE` in the
sentence above it, so the badge and the sentence agree today. The arch test
re-reads `dev::runner.subtitle` for every locale that claims that reason; the
day a locale translates the sentence, the declaration stops being true and the
rule fails rather than going on excusing the badge.

## How a line that IS wrong gets translated

Never free-hand. The term comes out of the tree, and the pull request says where
from:

1. **The same English word already translated at another key in that locale.**
   `auth::lock_screen.backspace_aria` was `Backspace` in Dutch while
   `mobile::lock.backspace` — the same key on the other shell — was `Wissen`.
2. **A sibling in the same array that locale did translate.** `dev::doctor`
   ships `aria_pass`, `aria_warning`, `aria_fail`; every one of the 25 locales
   translated `aria_warning`, and fourteen left the other two in English. One of
   three localised is not a register decision, it is an omission.
3. **The construction three sibling locales already used.** Czech, Polish and
   Slovak all render the backspace key as *delete character*; Croatian and
   Serbian got `Obriši znak`, built from two words already in their own files.

## The traps

Rule 1 delivers as many false leads as real ones, and every one of these looked
like evidence:

- `core::backup.restore.confirm_prefix` translates `Type` as a **verb** — "type
  DELETE to confirm". Reusing it for a `Type` column header would have written
  *Tape* into three French screens.
- `reports::index.open` translates `Open` as the verb *Openen*;
  `drift-alerts::alerts.tabs.open` is the adjective, and Dutch `Open` is right.
- `tax::badge.tag` is the verb *Taggen*; `tag_caption` is the noun.
- `reports::builder.viz.table` is *Tableau*, a chart type; `sync::health.col_table`
  is a database table, which French also calls a `Table`.
- `counterparties::components.filter_chips.personal` is *Personales* — Spanish
  had already translated `Personal`, into a word spelled the same way.

A term that fits the word but not the sense is worse than the English, because
the next reader cannot tell it was ever checked.

## Where an uncertain line goes

A line translated from evidence that still might not be what a native speaker
writes carries an `i18n-review:` marker — see
[Translations awaiting a native reader](translations-awaiting-a-native-reader.md).
A line deliberately left in English goes in `FROZEN_LINE_PINS` under its reason.
Between the two there is no third state: a locale file whose value quietly
matches English is indistinguishable from one nobody looked at.
