# Sorting a name list without ICU

`LocaleCollator` is the ordering seam for every list the reader scans by name:
the country picker, the counterparty picker, both category pickers, the budget
and envelope lists, the cash book and the report builder.

Desktop has `ext-intl` and uses a real `Collator`. **Both phones do not**, and
the fallback is the arm they always take — so the fallback is not a degraded
path for unusual installs, it is the path most readers are on.

## What was wrong

The fallback folded through `Str::ascii()`, which *transliterates* Greek and
Cyrillic into Latin and then sorts in the Latin alphabet. The result was an
order no reader of those scripts would recognise, in a picker with no search
box:

| | correct (ICU) | old fallback |
|---|---|---|
| Greek | … Γαλλία, Γερμανία, Δανία, Ελβετία … | … Δανία, Ελλάδα, Ελβετία, **Φινλανδία**, Γαλλία … |
| Bulgarian | … България, Германия, Гърция … | … България, **Чехия**, Дания, **Финландия**, Германия … |

Φ is the 21st Greek letter and was landing 8th; Ч is the 25th Cyrillic letter
and was landing 4th. Latin-script locales were wrong in smaller ways too —
Czech `Chorvatsko` filing under C instead of after H, Danish/Norwegian/Swedish
`Ø`/`Ö` filing mid-list instead of last, Polish `Łotwa` moving. **13 of 26
locales changed order between desktop and phone.**

## The key, and the three questions it answers in order

`compareWithoutIcu()` builds a sort key per name and compares the keys. The key
is four parts joined by a byte lower than anything a part can contain, which is
what makes a name that is another's prefix still sort first:

1. **The letters.** One fixed-width token per letter, digit run or punctuation
   mark. Digits are one token for the whole run so "Trip 2" precedes "Trip 10",
   which is what `Collator::NUMERIC_COLLATION` gives the desktop and what two
   of the `strnatcasecmp` comparators this replaced already did.
2. **The accents.** Only consulted once the letters are exhausted — "Backer"
   before "Bäcker" before "Bæcker", never "Bäcker" before "Backers".
3. **The capitals.** Small before capital, except in Danish, the one shipped
   language whose reader expects the capital first.
4. **The folded text**, as a last resort, so two names ICU calls equal still
   get a deterministic answer instead of comparing equal.

Levels 1–3 are the three levels ICU compares on, in ICU's order.

## The tables

Every table is **transcribed from ICU's own sort keys**, the same way
`Locale::groupMark()` and `Locale::symbolBeforeAmount()` are, and for the same
reason: on device ICU can only answer for English. They were derived by asking
a full-ICU host for the primary weight of every letter in the three scripts,
grouping the letters that share one, and reading the groups off in weight
order. Re-derive them the same way if a language is added.

- **`ORDER`** — one line per locale: the reader's own alphabet in the reader's
  own order, assembled from `LATIN`, `GREEK` and `CYRILLIC` where the locale
  takes them unchanged. A slash joins letters ICU ranks as **one letter but
  still tells apart** (`d/đ/ð`, `æ/ä`); an equals joins two spellings it cannot
  tell apart **at all** (Romanian `ş=ș`, the cedilla and comma-below forms). A
  letter absent from the line gives up its accents to the base it decomposes to
  — which is why `de` needs no `ä` and `sv` does.
- **`EXPANSIONS`** — a ligature the reader's alphabet does not carry collates as
  the letters it spells (`æ → ae`). Where it *is* one of their letters (Danish
  æ, Finnish ä) the alphabet table lists it and this map is not consulted.
- **`MARK_ORDER`** — which accent a letter carries separates two names no
  earlier letter has. ICU ranks the accents identically in every shipped
  language, so one table serves all 26. It counts by twos.
- **`STROKE_WEIGHT`** — ICU files a stroked or final-form letter *between* two
  accents rather than after all of them, so `ø` falls between `Ȯ` and
  O-cedilla; that is the gap the twos leave room for. It applies only to a
  letter listed as somebody else's variant: Danish `ø` is a letter of its own
  and takes no stroke weight.
- **`PUNCTUATION`** — not codepoint order either. ICU files the hyphen early
  and the ampersand late, the same way in all 26, so "Jansen-de Vries" and
  "Jansen & Vries" order alike on both halves.
- **`COMBINING_MARKS`** is kept out of the fold's own regex literal so the two
  spellings of "an accent is not a letter" cannot drift apart.

## Four things the tables alone would get wrong

Each of these was found by sorting a large generated corpus through both arms
and diffing the two orders.

- **A stranded combining mark.** `a̋` has no precomposed form, so normalising
  leaves the mark standing as its own character. Left as a token it became a
  letter of its own sorting ahead of every digit, putting `a̋ω…` before `a3`.
  A mark now attaches to the letter before it.
- **Turkish `I`.** The dot is the letter, and it stays the letter under an
  accent: ICU files `Í` with `ı`, not with `i`. Taking the dot off the
  *composed* character misses that, so it comes off the decomposed base.
- **Hungarian doubled digraphs.** "lly" is two `ly`, not `l` followed by `ly`,
  and read the second way it filed "amellyel" ahead of "amely" — a word out of
  the app's own Hungarian translations. Longest first, so "ddzs" is two `dzs`.
- **Compatibility forms.** `1º Andar` is an `o` written small. ICU reads the
  same letter and separates the two the way it separates a capital from a
  small, so a compatibility form sorts after both.

## The boundary

A letter outside Latin, Greek and Cyrillic — the three scripts the 26 shipped
languages are written in — files after every letter the reader's own alphabet
knows, in codepoint order. ICU has weights for it and the transcribed tables do
not. **This is the one place the two arms can still disagree**, and it is named
here rather than asserted, because closing it means shipping CLDR.

Two smaller approximations sit beside it, both about accents nobody writes:

- Two stacked accents on one letter are reduced to the first. ICU compares
  the whole sequence.
- An accent on a letter the alphabet lists as somebody else's variant is
  **added** to that letter's weight rather than sequenced after it, so `ø̆`
  and `ō` can come out the other way round. `ø` under a breve is not a word
  in any of the twenty-six.

Both were found the same way as the four fixes above: 188,000 generated names
per pass, sorted through the fallback, with ICU asked to re-check every
adjacent pair. After the fixes, 13 of the 26 still hold one such pair and every
one of them is an accent or a capital stacked on a letter that is another
letter's variant. **On the 26 languages' own words — every distinct word in
each locale's own Core translations, and every country name — the two arms
agree exactly.** That is what `LocaleCollatorMatchesIcuTest` asserts.

## Testing it

`compareWithoutIcu()` is public for the same reason `Money::formatWithoutIcu()`
is: **this is the arm both phones run, so it has to be assertable without
uninstalling `ext-intl`.** A test that builds a real `Collator` is testing the
desktop, and the desktop was never the broken half.

Three suites hold it:

- `LocaleCollatorMatchesIcuTest` sorts **every distinct word of each language's
  own Core translations** — roughly 1,200 per locale, in that locale's own
  script — through a real `Collator` and through the fallback, and asserts the
  two orders are the same order, in all 26. Where ICU calls two spellings equal
  any order is legal, so the fallback settles those ties on both sides; a real
  disagreement still shows.
- `LocaleCollatorWithoutIcuTest` asserts the **order** of specific lists on the
  fallback arm: Greek and Cyrillic country names, the Czech and Slovak `ch`,
  the Danish `aa`, the Turkish dotless ı, the Hungarian digraphs, the
  Lithuanian own-letters, and a name that is another name's prefix.
- `LocaleCollatorTest` keeps the desktop contract, and its "answers each reader
  in their own alphabet" case asserts both arms.

A name-ordering test that does not assert an **order** cannot fail. Asserting
only that two names differ — `not->toBe(0)` — tests that the fold is
injective, which a fold mapping every Greek name to the empty string still
satisfies. So the Greek case in `LocaleCollatorWithoutIcuTest` reads `Ωμέγα`
after `Άλφα` and `Άλφα` before `Βήτα`, and the `LocaleCollatorTest` case above
asserts the fallback arm beside the collator one rather than building a
`Collator` in every branch.

## Cost

A sort asks for the same name's key n·log n times, so keys are memoised per
locale, with a cap so a long-lived desktop process cannot grow one entry per
name ever seen. The ICU collator is memoised per locale for the same reason.

Related: [money-formatting.md](../ledger/money-formatting.md) documents the same
ICU/fallback split for amounts, including the invariant that the two must not
read differently.
