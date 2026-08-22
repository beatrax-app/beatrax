# Translations awaiting a native reader

Some translated lines are the best form the author could defend and still not
the form a native speaker would have written. Those lines carry a marker in the
lang file, and this page is the work-list they add up to. The rule is that a
line is never left *silently* uncertain: a locale file whose value quietly
drifts is indistinguishable from one that was checked.

## The marker

A `//` block directly above the key, opening with `i18n-review:`, then the
locale, the key path, and what specifically is open:

```php
    // i18n-review: hr · email_scan_health — agreement is fixed, the noun is not:
    // this says "pretinac" where core sidebar badge.inboxes says "pristigla
    // pošta" for the same thing. One of the two is what Croatian readers use.
    'email_scan_health' => 'Stanje skeniranja e-pošte — :count povezan pretinac|…',
```

It changes nothing that renders, and it is one grep:

```bash
grep -rn 'i18n-review:' Modules/*/Resources/lang/
```

The block is 2–4 lines because every comment in `Modules/` is, so `M1` and `M2`
in [the comment policy](00-index.md#the-comment-policy-is-canonical-in-the-spec)
apply here like anywhere else.

**Clearing one** means deleting the comment, not editing it. A marker that a
reviewer has answered and left in place is worse than no marker: the next
reader treats an already-checked string as still open.

## What is open

### The lock screen's sign-out sentence

`forgot_pin` names the button the reader has to press, so the word in the
sentence and the word on the button have to be recognisably the same action.

| Locale | File | What a native reader has to decide |
|---|---|---|
| `lv` | `Modules/Auth/Resources/lang/lv/lock_screen.php`, `Modules/Mobile/Resources/lang/lv/lock.php` | The sentence now repeats the button's `Atteikties` verbatim rather than inflecting it to `Atsakieties`, whose stem change hides the match. But *atteikties* is also this app's word for cancelling a subscription (`Modules/DriftAlerts/Resources/lang/lv/alerts.php`, `cancel_impact`). If that collision is real, all three `sign_out` labels want `Izrakstīties` or `Iziet`. |
| `bg` | `Modules/Auth/Resources/lang/bg/lock_screen.php`, `Modules/Mobile/Resources/lang/bg/lock.php` | Left as it stood: the sentence says `Излез`, the button says `Изход`. Confirm the pairing reads — the two share a root, and twenty other locales pair a prose imperative with a nominal button label the same way. |

### The "no data is lost" clause

All three are grammatical. The question is idiom, not correctness.

| Locale | Current | The alternative that may read better |
|---|---|---|
| `et` | `Andmeid ei lähe kaotsi.` | `Andmeid ei lähe kaduma.` — *kaotsi minema* is usually said of physical objects |
| `fi` | `Tietoja ei häviä.` | `Tietoja ei katoa.` or the positive `Tiedot säilyvät.` |
| `hu` | `Nem vész el adat.` | `Semmilyen adat nem vész el.` — the current form matches `app_lock.forgot_modal_body`, which writes it with `soha`; standing alone it is terse |

Both `lock_screen.php` and `lock.php` carry the same sentence in each locale, so
each row is two files.

### A count beside a noun

| Locale | File · key | What is open |
|---|---|---|
| `lv` | `Modules/Core/Resources/lang/lv/dashboard.php` · `email_scan_health` | Latvian has no neuter, so the participle in a `pievienotas:` colon label still has to pick a number and is now inflected per arm. Czech, Slovak and Polish reach for an impersonal in the same place. A header shape — `Pievienotās pastkastes: :count` — may be the better answer. |
| `hr` | `Modules/Core/Resources/lang/hr/dashboard.php` · `email_scan_health` | Agreement is fixed; the noun is not. This says `pretinac` where `core::sidebar.badge.inboxes` says `pristigla pošta`. |
| `sr` | `Modules/Core/Resources/lang/sr/dashboard.php` · `email_scan_health` | Same, plus `sandučad` is a collective whose genitive plural is contested against `sandučića`; `badge.inboxes` calls the same thing `prijemno sanduče`. |
| `sl` | `Modules/Auth/Resources/lang/sl/lock_screen.php` · `error_incorrect_remaining`, `Modules/Mobile/Resources/lang/sl/lock.php` · `errors.incorrect_pin_remaining` | Rewritten from a count label to real dual agreement, so the verb moves with the noun across all four arms. The grammar is checked against the rule table and pinned by a test; the word order and the `še` are a style call. |
| `sl` | `Modules/Mobile/Resources/lang/sl/sync_complete.php` · `records` | Same rewrite. Leading with `:peer` is a guess about what reads well when the device name is long. |

### The first-import step's counted phrases

The wizard's closing step held four of its numerals in the template. Moving
them into the lines ([how](counted-nouns-in-copy.md#where-the-numeral-lives))
turned the lede into a frame carrying two chosen phrases, and gave four keys
arms they never had. Files are `Modules/Onboarding/Resources/lang/<locale>/first_import.php`.

| Locale | Key | What is open |
|---|---|---|
| `hr` `sr` | `source` | The frame's `iz` governs the genitive, so all three arms are now `izvora` where arm 0 was the nominative `izvor` — the reading was `iz 1 izvor`. The `pl` `cs` `sk` `lt` siblings were already genitive throughout, which is what settled it. Three identical arms is the correct paradigm here, not padding. |
| `sl` | `source` | Same correction against `iz`: `vira` in the singular, `virov` in the dual and both plurals, where the arms had been the nominative `vir vira viri virov`. |
| `uk` | `source` | Same against `із`: `джерела` for one, `джерел` above it, where arm 0 had been the nominative `джерело`. Whether a digit tally takes full genitive government or the counting form is the question the `pl` precedent answered; a native eye should confirm it transfers. |
| `et` | `lede_counts`, `source` | The frame had carried `allikatest:` while `source` supplied its own noun, so the line read "142 tehingut allikatest: 3 allikat". The case moved onto the noun as the elative `:count allikast`, which is the shape `fi` already uses beside it, and the frame is now bare. |
| `es` | `lede_counts` | `repartidas` agreed with the transaction count from the frame, where no selector reaches it. Dropped to the plain `en`; whether Spanish wants the participle back inside `txn` is the call. |
| `fr` | `lede_counts` | Same problem with `réparties`. Replaced by the invariable `provenant de`, which changes the register slightly. |
| `tr` | `lede_counts` | Left as `:transactions — :sources.`, so the reading is unchanged. Turkish would more naturally lead with the source — `3 kaynaktan 142 işlem` — and the frame is now the one place that can say so. |
| `el` | `account_detected` | The verb leads and the numeral follows it, matching `section.rows_shown` in the same file. The template could only ever put the numeral first, so `3 ΕΝΤΟΠΙΣΤΗΚΑΝ ΛΟΓΑΡΙΑΣΜΟΙ` was what shipped. |
| `el` `es` `fr` `it` `pt` `sv` | `already_imported` | Each had only the plural participle, which read wrong at one. A singular arm was added and agrees with the elided transaction noun. |
| `lv` | `already_imported` | Three arms with the zero-first order Latvian selects: `jau importētu`, `jau importēts`, `jau importēti`. The zero arm renders whenever nothing was a duplicate. |
| `ro` | `already_imported` | The third arm carries the `de` a numeral from 20 up requires, landing on a bare participle: `21 de deja importate`. It follows the `anomaly::dashboard` precedent and reads clumsily; a native eye should say whether the noun has to come back. |
| `sk` | `already_imported` | Was the genitive plural `už importovaných` alone, wrong at one and two. Now agrees with *transakcia* across the three arms. `cs` `pl` `hr` `sr` `sl` `lt` `uk` keep their impersonal, which needs no arm — that difference between siblings is deliberate but unreviewed. |

### A count beside an adjective

The unusual-charges tile assembles `3 open · 2 large · 1 first-time · 4
duplicate` from four keys. Each now carries its own numeral and its own arms
([how](counted-nouns-in-copy.md#the-word-after-the-numeral-is-not-always-a-noun)),
so what a reviewer is being asked is no longer "does this agree" — the rule
table settles that — but "is this the word, in this shape, that a reader here
would use".

All of these are in `Modules/Anomaly/Resources/lang/<locale>/dashboard.php`.

| Locale | Key | What is open |
|---|---|---|
| `de` | `open`, `detectors.*` | The adjectives now take the attributive ending an elided *Abbuchung* wants (`2 große`) rather than the predicative form they had (`2 groß`). Which register a compact tally wants is the call. |
| `nl` | `open`, `detectors.*` | The same change, `2 grote` over `2 groot`. |
| `pl` | `detectors.first_time` | Was `pierwszy raz`, which a numeral cannot govern; it is now the invariable `po raz pierwszy`, fixed across all three arms. Whether that reads beside a count, or wants a noun phrase, is open. |
| `lt` | `open` | Was the impersonal `neperžiūrėta`, which no numeral reaches. It agrees with *mokėjimas* across the three arms now; whether the impersonal reads better in a tally is the question. |
| `lt` | `detectors.first_time` | Same shape as `pl`, with the accusative adverbial `pirmą kartą`. |
| `fr` | `detectors.first_time` | `2 premières fois` is what the plural arm forces, and it reads as an ordinal rather than "seen for the first time". A participle such as `inédit` may be the answer. |
| `ro` | `open`, `detectors.*` | The third arm carries the `de` a numeral from 20 up requires, but it lands on a substantivised adjective with the noun elided. Whether `21 de deschise` stands without the noun wants a native call. |
| `bg` `cs` `sk` `uk` | `detectors.duplicate` | All four were a noun (`дубликат`, `duplicita`, `дублікат`) where their three siblings are adjectives, so no count could govern them. Each is now the adjective agreeing with the tile's own noun. The agreement is settled; the word choice is not. |
| `lv` | `open`, `detectors.*` | Latvian selects arm 0 for **zero**, so the genitive plural leads and the singular follows. This tile never renders a zero part, so that arm ships unread — it should still be checked standing alone. |

### A count and a cap in one phrase

`reports::index.pinned_count` became one key holding both numbers — `:count of
:max pinned` — because the adjective in `2/3 pinned` agrees with neither of
them. Files are `Modules/Reports/Resources/lang/<locale>/index.php`.

| Locale | What is open |
|---|---|
| `cs` `sk` | The cap reaches the line as a placeholder, so the preposition is the plain `z`. Read aloud, the current cap of three would take `ze` in Czech and `zo` before some numerals in Slovak. Which form a *written* ratio wants is the call. |
| `ro` | The third arm is the one Romanian selects from 20 up, which the cap of three keeps out of reach. It repeats the second arm rather than guessing at a `de` no reader can reach, and wants a native eye if the cap ever grows. |

### A noun whose case is governed by a different key

The developer console builds `Missing :noun: :list` from two keys: the frame
carries the verb, `arg` carries the noun. No numeral ever reaches the noun, so
its arms are plain number forms rather than the case a numeral would govern —
and the verb in the frame cannot agree in number with what lands beside it.

| Locale | File · key | Frame it lands in |
|---|---|---|
| `sl` | `Modules/DevMode/Resources/lang/sl/arg_prompt.php` · `errors.arg` | `Manjka :noun: :list` — nominative, singular verb |
| `sl` | `Modules/DevMode/Resources/lang/sl/runner.php` · `toast.arg` | `… potrebuje :noun: :list` — accusative |
| `hr` | `Modules/DevMode/Resources/lang/hr/arg_prompt.php` · `errors.arg` | `Nedostaje :noun: :list` |
| `sr` | `Modules/DevMode/Resources/lang/sr/arg_prompt.php` · `errors.arg` | `Nedostaje :noun: :list` |

If a reviewer says the singular verb cannot stand beside a plural noun, the fix
is in the frame, not the noun: reword it to a case-free label.

### Turkish

| File · key | What is open |
|---|---|
| `Modules/Core/Resources/lang/tr/sidebar.php` · `hint.*` | These tooltips use the polite imperative (`oluşturun`, `yükleyin`) while the rest of the locale addresses the reader as `sen` (`hesabın`, `parolan`). The source and every other locale's body copy are informal; Turkish interface convention pulls the other way. Which register wins is one decision for the whole locale, not for this block. |
| `Modules/DevMode/Resources/lang/tr/overview.php` · `queue_summary_batches` | `batch` had been left in English. `Toplu iş` is the term Turkish Laravel writing uses, but it sits close to the `iş` already standing for a job. |
| `Modules/Sync/Resources/lang/tr/health.php` · `skipped` | `Operasyon` reads as a military or surgical operation; `işlem` is the natural word but already carries "transaction" everywhere else in this locale. |

### A noun whose form is bound to a cap's current value — closed

`reports::index.pin_cap` states the pin cap in a sentence: *"You can pin up to
:max reports."* The cap used to be typed as `3` in all 26 strings and again in
two PHP constants; it now has one home in `PinCap::MAX_PINS` and reaches the
sentence as `:max`.

That alone would have left the grammar frozen at three — eight locales inflect
the noun after the numeral, and every one of them was written for exactly that
cap. The arch rule that forbids a count beside a bare plural caught it, which
is the whole reason the rule exists.

The line is now read with `Lang::choice`, selecting on **the cap** rather than
on how many reports are pinned. Whoever moves `PinCap::MAX_PINS` gets the right
arm in every language without touching a string, and Slovenian's dual is
reachable at a cap of two.

## Checked and deliberately left alone

Recording these matters as much as the open list — without it the next pass
re-opens the same questions.

- **Turkish never pluralises a noun after a numeral.** A scan of every `:count`
  in the locale finds no `-lar`/`-ler` following one. The common bug is absent.
- **Romanian's `de` is handled.** Every three-arm Romanian line puts `de` in the
  third arm only, which is the arm Laravel selects from 20 upward and not at
  101. `:days` in the forecast strings is `ForecastHighlightsQuery::HORIZON_DAYS`,
  a constant 30, so its unconditional `de` is right.
- **Croatian and Serbian paucal is handled.** The 2–4 arm carries the genitive
  singular (`2 transakcije`) and the 5+ arm the genitive plural
  (`5 transakcija`), with the verb switching from plural to neuter singular
  between them.
- **Lithuanian and Latvian arm *order* is right.** Latvian selects arm 0 for
  **zero**, arm 1 for 1/21/101 and arm 2 for the rest, and the files follow it —
  the trap of writing singular-first was avoided.
- **`et` `date.empty` / `time.empty`** were changed from the abessive
  (`kuupäev valimata`) to `kuupäeva pole valitud`, matching `file.none` in the
  same file. No marker: the internal precedent settles it.
- **Identical arms in `en`, `de`, `nl`, `hu` and `pl` are the translation.**
  An English adjective does not inflect, a Hungarian one never does after a
  numeral, and Polish `otwarte` covers both the singular and the 2–4 arm. The
  repeated segment is the locale saying so in the one place it can.
- **`et` `fi` `hu` `tr` keep the ratio in `pinned_count`.** Now that the whole
  phrase is one key, `2/3 kinnitatud` is each locale's own choice rather than a
  shape the template imposed, and their participles do not move with the count.
- **The zero arm of `anomaly::dashboard` never renders.** The tile hides itself
  below one open alert and drops a detector whose count is zero, so `lv` arm 0
  and the `cs`/`pl`/`sk` many-arm at zero are unreachable here. They are written
  correctly anyway, because the rule table decides the arm count, not the caller.

## Related

- [Copy that carries a count](counted-nouns-in-copy.md) — how a number and a
  noun are written together, and which arm each locale selects
- [Conventions](00-index.md) — the comment policy these markers are shaped by
