# Deduction category wording

`tax_deduction_categories` is seeded from a bundled per-country corpus, and the
corpus is written in the jurisdiction's own language — `resources/corpus/tax/nl.yaml`
says "Zorgkosten", `gr.yaml` says "Ιατρικές δαπάνες". The seed is insert-only, so
the row a screen reads back is that wording, in that language, whoever is
reading. An English reader filing in the Netherlands read "Zorgkosten", "Giften"
and "Eigen woning" off the tax page, the picker, the badge and the CSV.

`corpus_key` was already on the row, and already read — `TaxCategoryStore` uses
it to keep a re-seed idempotent. It was simply never read for display.

## Where the wording comes from

Each corpus entry carries an `i18n` block: the same three fields (`name`,
`short_name`, `hint`) per locale, beside the jurisdiction wording they translate.

```yaml
  - key: "nl_zorgkosten"
    name: "Zorgkosten"
    short_name: "Zorgkosten"
    hint: "Ziektekosten boven drempel (eigen risico, hulpmiddelen, etc.)"
    i18n:
      en:
        name: "Healthcare costs"
        short_name: "Healthcare"
        hint: "Medical costs above the threshold (insurance excess, medical aids, etc.)"
      nl:
        name: "Zorgkosten"
        short_name: "Zorgkosten"
        hint: "Ziektekosten boven drempel (eigen risico, hulpmiddelen, etc.)"
```

`en` is required for every entry and `nl` is shipped for every entry; a reader in
any of the other twenty-four locales falls back to English, never to a raw key.
`AColumnSeededWithWordsResolvesInTheReadersLocaleArchTest` fails when an entry is
missing either.

### Why not a lang group

Every other translated string in the product lives in a per-module lang file, and
`TranslationParityArchTest` holds those files to a hard contract: a key in the
English file must exist in **all twenty-six** shipped locales. The corpus is 398
entries across 33 jurisdictions, three fields each — 31,044 strings under that
contract, of which twenty-four locales' worth would be English pasted into
another language's file. Parity is agreement, and that kind of agreement is a
lie the guard cannot see through.

Keeping the wording in the corpus also keeps a country whole: adding a 34th
jurisdiction is one complete file rather than one file and twenty-six edits.

The two sibling reference tables go the other way, and should: `categories` and
`currencies` are fixed vocabularies the product owns, small enough to carry in
every locale, so they resolve through `categorization::categories.<slug>` and
`ledger::currencies.<code>`.

## `name_is_default`

`corpus_key` cannot by itself say whether the stored name is still the corpus's
wording, because the key deliberately survives a rename — dropping it would make
the next re-seed insert the row again and undo the rename, which is the whole
point of the insert-only seed. So the row carries the same provenance flag
`categories` already carries:

| write | `name_is_default` |
|---|---|
| seeded from the corpus | `true` |
| added by the reader | `false` |
| renamed by the reader | `false` |

A rename is the user's own words and renders verbatim in every language.
`short_name` and `hint` take no flag: neither has an editor, and a category the
reader added carries no corpus key to resolve from.

The migration that adds the column does not assume. It re-reads each row's own
corpus entry and marks the row a default only where the stored name still equals
what the corpus wrote, so an install where somebody renamed a seeded category
keeps that rename.

## Where it is resolved

`Modules/Tax/Internal/Support/TaxCorpusWording` is the only reader of the corpus
for display, and three callers use it:

| caller | what it feeds |
|---|---|
| `TaxCategoryStore::listForUser()` | the settings list, the tag picker, the rule form's deduction select |
| `TaxYearQuery` | the year view's section headers, the CSV export, the PDF |
| `TaxTagQuery` | the badge on a transaction row |

`listForUser()` resolves the rows it returns rather than leaving it to each
render site: the three screens that read it share one row shape, and a fourth
reader must not have to know that `name` holds the corpus's language. Ordering
stays on the stored columns, so the list does not reshuffle per locale and two
devices agree on it.

The badge's short-then-long fall-through used to be a SQL `COALESCE`. It cannot
be: the choice between the short label and the name has to be made *after* both
have been resolved for this reader, not by the database.

## The rule this shares with the other two tables

`Modules\Core\Public\Support\SeededDisplayName` holds the one rule all three
seeded tables obey — the reader's wording wins while the row is still the
seeder's, and the row wins back the moment the user has written their own words
over it. `fromLang()` serves the lang-backed tables, `prefer()` the corpus-backed
one; the provenance rule is written once.
