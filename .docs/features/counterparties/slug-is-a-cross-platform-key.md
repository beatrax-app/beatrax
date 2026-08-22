# `counterparties.slug` is a cross-platform key

The [resolution chain](resolution-chain.md) keys one identity per
`(user_id, slug)`, and `slug` is plaintext at rest and synced between the
devices of a household. Two devices must therefore derive **the same slug
from the same merchant name**, or the ledger they exchange is keyed apart
and one merchant becomes two rows.

`CounterpartySlugResolver::slugify()` used to reach that ASCII form through
`iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', …)`. `//TRANSLIT` is implemented
by the **C library**, not by PHP: the transliteration table ships with
glibc, with Apple's libiconv, and with whatever libiconv an Alpine or
Android build links against. They do not agree, and they never had to —
`//TRANSLIT` is documented as an approximation, not as a function.

## What the divergence actually was

Measured through three PHP 8.5 builds — macOS (libiconv 1.11), Debian
(glibc 2.41), Alpine (libiconv 1.18):

| Merchant name | macOS | glibc | musl |
|---|---|---|---|
| `Café Ambiance` | `caf-e-ambiance` | `cafe-ambiance` | `caf-e-ambiance` |
| `Société Générale` | `soci-et-e-g-n-erale` | `societe-generale` | `soci-et-e-g-en-erale` |
| `Łódź Market` | `l-o-zz-market` | `lodz-market` | `l-od-z-market` |
| `Ünlü Şarküteri` | `unl-us-ark-uteri` | `unlu-sarkuteri` | `unl-u-sark-uteri` |
| `Ærø Færge` | `aero-ae-rge` | `aero-faerge` | `aero-faerge` |
| `Ωμέγα` | `counterparty` | `u` | `counterparty` |

**24 of 41** realistic European merchant names derived a different slug
depending on which libc the device linked. A sweep of every BMP codepoint
put the general figure far higher: of 64,638 codepoints tested as
`a<char>z`, only **1,159 produced the same slug on all three**.

Three properties made this worse than a formatting disagreement:

1. Apple's libiconv emits the accent as a spacing character — `é` becomes
   `'e` — and the `[^a-z0-9]+` pass reads that apostrophe as a word break.
   One merchant becomes two words.
2. It is **lossy**, not merely different: `Ærø Færge` loses the `F` of
   `Færge` outright.
3. It is not stable within one machine. The same input through the same
   binary transliterates differently depending on whether Composer's
   autoloader has been required — `Isik"oD?ner` bare against
   `Isik"oD"oner` after `require vendor/autoload.php`.

A value that answers three ways is not a value a stored row can be keyed
under. For an accented merchant there is no single "already persisted"
slug to preserve.

## The rule now

Transliteration happens in PHP, so every device reaches the same bytes.

1. Seven characters are substituted first — `©` `®` `µ` `×` `•` `℮` `◦`.
   These are the ones the BMP sweep found *every* libc spelling with a
   letter and `voku/portable-ascii` holding no entry for. `µ` has to be
   substituted before step 2, because it decomposes to Greek mu.
2. Unicode **compatibility decomposition** (`Normalizer::FORM_KD`), then
   every combining mark (`\p{Mn}`, `\p{Me}`) and every zero-width
   character removed. That folds `é` to `e`, `ﬁ` to `fi`, `Ⅻ` to `XII`,
   a fullwidth `Ｆ` to `F`, and drops a zero-width space rather than
   letting it split a name. Normalization is covered by the Unicode
   stability policy, so it cannot drift the way a translit table does.
3. Any remaining non-ASCII **Latin letter, punctuation mark or symbol**
   goes through `Str::ascii()` — the `voku/portable-ascii` table, pure
   PHP. That expands `ß`→`ss`, `æ`→`ae`, `ø`→`o`, `đ`→`d`, `þ`→`th`,
   `€`→`EUR`, and an en dash to `-`.
4. Anything left — a letter of a non-Latin script, or a symbol the table
   does not carry — becomes a **separator**, not nothing. `//TRANSLIT`
   answered an unmappable character with `?`, which the cleanup pass reads
   as a word break; dropping it here would join the words either side and
   re-slug the merchant carrying one.

Non-Latin scripts are deliberately **not** romanised. `Str::ascii()` would
turn `Пятёрочка` into `piaterocka`, which reads better and would rename
every stored Cyrillic, Greek, Arabic and Hebrew merchant — all of which
sit on the `counterparty` fallback today, on every platform.

The `[^a-z0-9]+` → `-` pass, the fallback and the 128-character cut are
unchanged.

## Why not `Str::slug()`

`Str::slug()` is portable, and it is still the wrong function here: it
*deletes* the dot and the slash this slugifier keeps as separators, so
`Coolblue B.V.` becomes `coolblue-bv` and `Shop 24/7` becomes `shop-247`.
Those are stored slugs on every install. Only the transliteration half —
`Str::ascii()` — is shared;
`Modules\Counterparties\tests\Unit\CounterpartySlugifierIsFrozenTest` pins
the difference.

## What this changed for a device that already has rows

| Device | Slugs that change |
|---|---|
| Linux desktop, and any glibc build | none across the measured Latin-script names |
| macOS desktop | the accented ones — 24 of the 41 measured |
| musl / Alpine builds | 23 of the 41 measured |

Every name whose slug was **identical on all three platforms** derives that
same slug now: 95 of 95 fixtures, including `Straße 12`, `Blåbær`,
`Œuvre`, `Đorđe`, `Ffanø`, `Škoda Auto`, `Beşiktaş Market`, `Disney©
Store`, `Jan–Pieter Bakkerij`, and every non-Latin script. The names that
change are the names that were already keyed three ways.

An existing macOS or musl install therefore holds rows under a slug the
resolver no longer derives, and the next import of that merchant creates a
second row beside the first. Reconciling those is a data migration, and it
is not written here.

## The residue

Across the whole-BMP sweep, 1,043 of the 1,159 codepoints that were stable
across the three libcs keep their slug. The 116 that do not are:

- **103 circled letters and digits** (`Ⓑ`, `①`). `//TRANSLIT` spells these
  with parentheses — `(B)` — which read as two word breaks; compatibility
  decomposition gives the bare `B`.
- **10 CJK squared units** (`㎡`). `//TRANSLIT` writes `m^2`, decomposition
  writes `m2`.
- **`Ŀ`/`ŀ`** (Catalan L with middle dot) and **`⅟`**.

None of them can carry a merchant identity that a numeric-suffix walk does
not already separate, and all of them were only ever agreed on by accident.

Five codepoints — `U+0897` and `U+A7F1`–`U+A7F4` — still derive differently
between a build with `ext-intl` and one falling back to
`symfony/polyfill-intl-normalizer`. That is a Unicode **data-version** lag
in the polyfill for characters assigned after its table was cut, not a libc
dependency, and it closes as the polyfill updates.

## Related

- [Resolution chain](resolution-chain.md) — where the slug is used as the
  `firstOrCreate` key.
- [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md) — why
  `counterparties.slug` cannot be encrypted, which is what makes it a
  plaintext key two devices have to agree on.
