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

### The palette's "see all" row

`dev::palette.see_all` replaced a `see_all_prefix` + number + `see_all_suffix`
concatenation, so every locale got a counted line where it had had two
fragments. The affordance is "see them **all**", and the quantifier is the part
a numeral does not settle in the languages that decline it.

| Locale | File · key | What is open |
|---|---|---|
| `hr` · `sr` | `Modules/DevMode/Resources/lang/{hr,sr}/palette.php` · `see_all` | `sva` at 2–4 against `svih` at 5+. Only the 5+ arm carries the quantifier; the other two drop it rather than risk the wrong one. Both files want the same answer. |
| `pl` · `sk` | `Modules/DevMode/Resources/lang/{pl,sk}/palette.php` · `see_all` | `wszystkie 5 wyników` against `wszystkich 5 wyników`, and the Slovak equivalent. Written here as `wszystkie` / `všetkých`. |
| `uk` | `Modules/DevMode/Resources/lang/uk/palette.php` · `see_all` | `всі` before a genitive plural. The line this replaced avoided the question by moving the count into brackets — which is what a translator does when the call site leaves them nowhere to put their grammar. |
| `tr` | `Modules/DevMode/Resources/lang/tr/palette.php` · `see_all` | Turkish selects one arm, so the line covers every count. `Tüm :count sonucu gör` against a `tümünü` phrasing. |
| `lv` | `Modules/DevMode/Resources/lang/lv/logs.php` · `totals.all_files` | Written with a bare locative and no preposition, because `pa` governs a case the size phrase in front of it does not supply. |

### A count beside a noun

| Locale | File · key | What is open |
|---|---|---|
| `lv` | `Modules/Core/Resources/lang/lv/dashboard.php` · `email_scan_health` | Latvian has no neuter, so the participle in a `pievienotas:` colon label still has to pick a number and is now inflected per arm. Czech, Slovak and Polish reach for an impersonal in the same place. A header shape — `Pievienotās pastkastes: :count` — may be the better answer. |
| `hr` | `Modules/Core/Resources/lang/hr/dashboard.php` · `email_scan_health` | Agreement is fixed; the noun is not. This says `pretinac` where `core::sidebar.badge.inboxes` says `pristigla pošta`. |
| `sr` | `Modules/Core/Resources/lang/sr/dashboard.php` · `email_scan_health` | Same, plus `sandučad` is a collective whose genitive plural is contested against `sandučića`; `badge.inboxes` calls the same thing `prijemno sanduče`. |
| `sl` | `Modules/Auth/Resources/lang/sl/lock_screen.php` · `error_incorrect_remaining`, `Modules/Mobile/Resources/lang/sl/lock.php` · `errors.incorrect_pin_remaining` | Rewritten from a count label to real dual agreement, so the verb moves with the noun across all four arms. The grammar is checked against the rule table and pinned by a test; the word order and the `še` are a style call. |
| `sl` | `Modules/Mobile/Resources/lang/sl/sync_complete.php` · `records` | Same rewrite. Leading with `:peer` is a guess about what reads well when the device name is long. |
| `lv` | `Modules/Goals/Resources/lang/lv/messages.php` · `archived_disclosure`, `Modules/Pots/Resources/lang/lv/messages.php` · `archived.toggle` | Latvian selects its **first** segment for zero, so both are written there as a genitive plural (`Arhivētu mērķu`, `Arhivētu krājkašu`). Neither disclosure renders at zero — each is drawn only when the count is at least one — so that arm is unread. The pots singular is also written indefinite (`Arhivēta krājkase`) against the definite plural (`Arhivētās krājkases`) the line already carried; one of the two is what a count label wants. |
| `sl` | `Modules/CashBook/Resources/lang/sl/cash-book.php` · `errors.amount_unreadable`, `Modules/Forecasting/Resources/lang/sl/forecast.php` · `errors.amount_decimals` | Both said "z :decimals decimalnimi mesti". The preposition follows the numeral's **spoken** form — `s` before *tremi* and *štirimi*, `z` before *enim*, *dvema* and *osmimi* — and a digit settles none of it, so one of the four arms is always written with the wrong one. They now read `na največ` and `ki ima največ`, neither of which alternates. Whether either reads as well as the instrumental is the call. |

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

### A count that was standing outside its own line

Five call sites put a formatted number beside a translated word — `1 Mappings`,
`1 new, 1 unchanged, 1 conflicts.`, `Matches 1 transactions in your recent
history.`, `1 / ~1 messages`, and the rules page's re-apply progress. Each is now
one `Lang::choice` line, which gave twenty-five locales arms where they had had
a bare noun, an adjective or a sentence fragment. Files are
`Modules/Community/Resources/lang/<locale>/settings.php`,
`Modules/Import/Resources/lang/<locale>/aliases.php`,
`Modules/EmailScan/Resources/lang/<locale>/inboxes.php` and
`Modules/Categorization/Resources/lang/<locale>/rules.php`.

| Locale | Key | What is open |
|---|---|---|
| `de` | `settings.contributors` | *Mitwirkende* is an adjectival noun, so the singular has to pick a gender; written as the strong masculine `:count Mitwirkender`. |
| `el` | `settings.contributors` | The singular is the present participle *συνεισφέρων*, which is what the plural implies and formal beside a numeral. |
| `lt` | `settings.contributors` | The file had the definite *prisidėjusieji*, which no numeral governs; now the indefinite participle. A noun (*talkininkai*) may read better. |
| `sl` | `settings.contributors` | *sodelujoči* as a substantivised participle, definite masculine across all four arms; the indefinite *sodelujoč* is the alternative. |
| `da` `nb` `sv` | `aliases.diff_new`, `diff_unchanged` | The elided noun is *alias*, taken as neuter, which is what gives `nyt`/`nytt`/`oförändrat`. A common-gender *alias* flips all three singulars. |
| `de` `nl` | `aliases.diff_new`, `diff_unchanged` | Attributive endings for the elided noun, following the anomaly tile's `2 große` / `2 grote`. Gender and register both open. |
| `hr` `sr` | `aliases.diff_new`, `diff_unchanged` | First arm written definite (`novi`) against indefinite `nov`; the paucal takes the genitive singular. Both files want the same answer. |
| `sl` | `aliases.diff_new`, `diff_unchanged` | Indefinite masculine across all four arms, giving the dual `nova`; definite `novi` is the alternative. |
| `ro` | `aliases.diff_new`, `diff_unchanged` | The third arm carries the `de` a numeral from 20 up requires, landing on a bare adjective with *alias* elided — the same shape the anomaly tile is already marked for. |
| `es` `pt` | `aliases.diff_unchanged` | *sin cambios* / *sem alterações* is invariable, so both arms repeat and the singular reads terse. *inalterado* would agree but drops the file's own wording. |
| `lv` | `aliases.diff_*` | Zero-first arm order. Unlike the anomaly tile, this **zero arm does render** — a parsed file can bring nothing new. |
| `uk` | `aliases.matches` | Replaces a count label that hid the history in brackets. *Відповідає* governs the dative, leaving arms 2 and 3 identical. |
| `tr` | `aliases.matches` | The old prefix/suffix put the history first and the verb last; that order is kept, and one arm covers every count. |
| `el` `lv` | `rules.reapply_progress` | The participle follows the arm `:count` selects, but what was checked is `:checked` — and Latvian's arm 1 covers 21, where `:checked` can exceed one. |

### A quantifier compressed to chip width

`categorization::rules.combinator_all` / `combinator_any` replaced the literals
`ALL` and `ANY`, which had been typed into a ternary in the template. Each locale
takes the quantifier its own `rule_form.match_all` / `match_any` chose, inflected
to agree with that locale's word for *condition*.

| Locale | What is open |
|---|---|
| `bg` | «кое да е» compacts `match_any`'s «което и да е» to chip width. |
| `de` | `EINE` is the locale's own quantifier but reads ambiguously as a logic chip; `BELIEBIG` is the alternative. |
| `et` | `MIS TAHES` stands in for *ükskõik millisele*, which no chip can carry. |

`nb` and `nl` diverge from their own `match_any` on purpose: "en av" and "een
van" leave a dangling preposition in a chip, so they read `MINST ÉN` and
`MINSTENS ÉÉN`, parallel to the French `AU MOINS UNE` and Italian `ALMENO UNA`
that *are* their locales' own wording. That is a decision, not an open question.

### The migration preview's own sentences

`Modules/Migration/Resources/lang/<locale>/unmapped.php` is new: it holds the
labels and reasons the preview used to store in the database in English. Twelve
markers sit in it, and eleven are the same key.

| Locale | Key | What is open |
|---|---|---|
| `cs` `el` `hr` `it` `lt` | `reason.split_legs_without_category` | `:uncategorized` is filled from `ledger::common.uncategorized`, whose value in these locales is a prepositional phrase — *Bez kategorie*, *Χωρίς κατηγορία*, *Senza categoria*. "Waiting in *that*" is ungrammatical, so the frame adds its own noun and visibly repeats the word: "v kategorii **Bez kategorie**". A shorter English clause would clear all five. |
| `et` `fi` | same | The count moved behind the elative phrase, which is how these languages count. Both arms are then identical, because the noun does not move after a numeral and the verb stays singular. |
| `hu` | same | The article before `:uncategorized` is written `a`, resolved against today's *Kategorizálatlan*. It breaks if that word ever starts with a vowel. |
| `lv` | same | Latvian selects arm 0 for zero and this line never renders at zero, so the genitive plural ships unread. Also whether a leg is *sadalījuma daļa* where `ledger::detail.split` says only *daļa*. |
| `sl` | same | Dual agreement moves the verb across all four arms; the grammar is pinned by the rule table, the noun phrase is a style call. |
| `tr` | same | Turkish selects one arm, so the line covers every count. It leads with `:legs`, which is the natural order; a `:count`-first reading needs a different frame. |
| `uk` | same | *розподіл* is Ukrainian for a split **and** for a budget assignment, and both senses stand on this one screen. |

### The developer console's own chrome

The console's rail, its palette rows and its whitelisted-command list were
English typed into PHP while every page they point at shipped in twenty-six
languages. Eighty-one keys moved out of code into `dev::nav`, `dev::palette`,
`dev::runner`, `dev::overview` and `core::sidebar`.

| Locale | Key | What is open |
|---|---|---|
| `hu` | `core::sidebar.dev.worker_ago`, `dev::overview.heartbeat_age` | `:count s-mal ezelőtt`. The instrumental suffix on an SI symbol is correct Hungarian — *5 km-rel* — but reads formally for a compact pulse; `:count mp` may be what a reader wants. |
| `tr` | `dev::runner.command.install.description` | *İdempotent* kept as a loanword rather than translated. |
| `fi` `et` | same key | *Idempotentti* / *Idempotentne*, loanwords with no settled native form. |
| `el` | `dev::palette.nav.sql.label` | *Πάνελ SQL* is a loanword; *Πίνακας* would collide with the word for a database **table** on a SQL screen. |
| `de` `nl` `da` `nb` `sv` | `dev::nav.sync_health`, `dev::palette.nav.sync_health.*` | Rendered as *sync status* rather than *health*: *Gesundheit* and *gezondheid* read wrong for a machine, but *status* loses that the screen is specifically a quarantine count. |
| all | `dev::overview.heartbeat_age` | `ttl` is left untranslated as a technical abbreviation, beside a count it does not govern. |
| `ro` | `dev::overview.heartbeat_age`, `core::sidebar.dev.worker_ago` | Romanian puts *de* in front of a noun from twenty up, and the third arm writes `acum :count de s`. Whether *de* survives in front of an abbreviation is the open half. |
| `el` | `dev::overview.heartbeat_age` | *δλ* against *δευτ.*; both are already in the tree, in `emailscan` and in the mobile lock screen. |
| `el` `lt` | `dev::runner.command.migrate_fresh` | *μετεγκατάσταση* / *migracija* is the schema sense, while each locale calls the app's own YNAB import *μεταφορά δεδομένων* / *perkėlimas*. One word, two migrations. |
| `pl` `ro` | `dev::palette.nav.overview.hint` | "Tiles" has no precedent in either locale; rendered *kafelki* / *panouri*. |
| `pl` `ro` | `dev::palette.nav.artisan.hint` | "Whitelisted" has no settled form in either locale, so both describe it — *z listy dozwolonych*, *din lista permisă*. |
| `pl` `ro` `uk` | `dev::palette.nav.sync_health.hint` | Nothing in the tree names a CRDT merge op; *operacje scalania*, *operațiuni de îmbinare*, *об’єднання* against *злиття*. |
| `sk` `sl` | `dev::runner.command.route_list` | *cesta* and *pot* are already each locale's word for a filesystem path in `dev::system`, so an HTTP route lands on the same noun. `sk` borrows *routa*, `sl` writes *poti HTTP*. |
| `sk` `sl` | `dev::runner.command.view_clear` | The same collision one row down: the palette's own views hold *zobrazenie* / *pogled*, and the Blade template cache needs the word too. |
| `sk` `sr` `uk` | `dev::palette.nav.logs` | None of the three has a noun for a log *tailer*; each names the act of following the file and moves "live" into the hint. |
| `sr` `uk` | `dev::runner.command.rederive_fingerprints` | *otisak* / *відбиток* already carries a key fingerprint and a biometric one; here it takes a transaction's. |
| `sr` | `dev::palette.nav.overview.label` | *Dev* used attributively with no hyphen, following this locale's own `SQL upit`. `uk` hyphenates, hence *Dev-огляд*. |

Two English strings changed on the way in, because `dev::palette.nav.*` sits
under a `nav.` path that `AnEnglishHeadingIsWrittenAsASentenceArchTest` reads:
"Dev Overview" became "Dev overview" and "Sync Health" became "Sync health".

A placeholder that shows a filesystem path is copy, not a value: every locale
writes the path words in its own language and keeps `backup.sqlite` — `/pad/naar/`,
`/шлях/до/`, `/διαδρομή/προς/`. The same reading applies to `dev::runner.arg.id`,
whose placeholder describes what an empty field does rather than a word to type,
so *all* is translated. `app.name`, `default` and `alice` stay as they are: those
are values the field actually accepts.

### A hint whose two nouns are one word

`dev::palette.action.open_profile.hint` is "Settings — account and preferences".
Five locales use one word for both halves, so the hint would repeat itself; each
substitutes its own second word — `omat valinnat`, `mogućnosti`,
`alkalmazásbeállítások`, `možnosti`, `opcije`. Files are
`Modules/DevMode/Resources/lang/{fi,hr,hu,sl,sr}/palette.php`.

`de`, `nb` and `sv` use *Voreinstellungen* / *preferanser* / *preferenser* in
the same hint. They are the standard software terms and they appear nowhere else
in those locales, which is worth knowing rather than open.

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

Recording these matters as much as the open list — without it the next reviewer
re-opens the same questions.

- **Turkish never pluralises a noun after a numeral.** A scan of every `:count`
  in the locale finds no `-lar`/`-ler` following one. The common bug is absent.
- **Romanian's `de` is handled.** Every three-arm Romanian line puts `de` in the
  third arm only, which is the arm Laravel selects from 20 upward and not at
  101. `:days` in the forecast strings is `ForecastHighlightsQuery::TILE_HORIZON`,
  a constant 30, so its unconditional `de` is right.
- **An identical pair of arms is often what the governing case leaves.** Where a
  numeral phrase is already instrumental or genitive, the distinction the locale
  draws in the nominative disappears: `:decimals miejscami po przecinku` is
  Polish for two and for five alike, and so are the Czech, Slovak and Ukrainian
  siblings; `do :days dana` is Croatian and Serbian for one, two and 365, because
  the genitive singular and the genitive plural of *dan* are the same word. The
  repeated arm is the paradigm, not padding.
- **A noun that does not inflect for number repeats its arms too.**
  `ledger::detail.toast.note_too_long` is one line in `da`, `de`, `nb` and `sv`,
  because *tegn*, *Zeichen* and *tecken* are the same word in both numbers, and in
  `hu` for the reason already recorded here. `et` and `fi` repeat theirs in
  `cashbook::cash-book.errors.amount_unreadable`, where the noun stands in a
  comitative or adessive no numeral moves — `kuni :decimals kümnendkohaga`,
  `enintään :decimals desimaalilla` — while the `amount_decimals` sibling in
  `forecasting::forecast` keeps two, because there the same noun is a subject.
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

- **`notifications::settings.hide_details.help` was re-pointed in all 26
  locales, and carries no marker.** The English had described the opposite
  switch and 25 locales had translated it faithfully, so every one of them
  had to move. What settles the corrected wording is internal precedent
  rather than a judgment call: each locale's own `hide_details.label`
  already carries that language's hiding verb — `Skjul`, `verbergen`,
  `Απόκρυψη`, `Ocultar`, `Piilota`, `Slėpti`, `gizle`, `Приховувати` — and
  the help now reuses it, together with the locale's own word for the
  on-direction. Finnish takes the elative (`ilmoitusbannerista`) for the
  same reason: its label already says `ilmoituksista`.

- **No cross-locale guard was written for the direction of that sentence.**
  The English one is executable — the test reads the switch direction out of
  the sentence and follows it — but "does this line send the reader to *on*"
  has no language-independent token to match on, the way `%` and `±` gave
  `anomaly::settings.sensitivity_help` one. Every candidate needed a
  per-language verb table, which is a second translation to keep current and
  the copy of a rule that goes stale. The 25 translations are held by review,
  not by a test, and this bullet is the record of that.

## The script a locale ships in

A locale is written in one script, and `Modules/Core/Public/Enums/Locale.php`
is where that is decided. The comment on `Locale::Sr` states it for the only
case where the choice was open: Serbian ships in **Latin**, because Cyrillic
renders without a font fallback on every desktop and mobile target, and Latin
is what Serbian banking software overwhelmingly uses.

Twenty-six lines across ten `sr` files were Cyrillic anyway — and
`core::backup.intro_html` was Latin for the sentence and Cyrillic for its last
clause. All of them are now transliterated. Serbian Cyrillic and Latin are a
strict one-to-one mapping (`љ` is `lj`, `ђ` is `đ`, `ч` is `č`, `џ` is `dž`),
so this was a letter substitution and nothing else: placeholders, HTML, the `|`
separators and the names already in Latin (Beatrax, Gmail, OAuth, GDK, PIN)
came through byte for byte.

[Parity](arch-invariants.md) could not see any of it, and never will. It
compares key paths, placeholder tokens and plural-segment counts *between*
locales, and a line in the wrong script has the right keys, the right `:count`
and the right number of `|` segments. It is in perfect parity and still wrong
on screen: the reader gets a page where most words are Latin and a handful are
not, which reads as a rendering fault rather than as a translation.

### What the guard holds

`tests/Contracts/ALocaleIsWrittenInTheScriptItShipsInArchTest.php` holds it
from both sides, over every `Modules/*/Resources/lang/<locale>/*.php` and every
`lang/<locale>/*.php`:

- **No locale carries a script it did not declare.** The expectation is a map
  in the test — Latin for the twenty-three Latin-script locales including `sr`,
  Cyrillic for `bg` and `uk`, Greek for `el` — and its keys are asserted equal
  to `Locale::cases()`, so a new locale must declare its script before anything
  else in the file will pass.
- **The rule is one-directional.** Latin letters are legal everywhere, because
  a brand name, a currency code, an IBAN or a class attribute is Latin inside a
  Cyrillic file too. Only a *non-Latin* letter in a locale that never declared
  that script is an offence.
- **A non-Latin locale is actually written in its script.** The other half of
  the same rule, and the shape a `bg` or `el` file left in English takes —
  invisible to parity for the same reason.
- **The walk is counted.** Files, bytes and a per-locale floor, so a glob that
  answers nothing cannot report a clean tree; and a `false` from
  `preg_match_all` throws with `preg_last_error_msg()` rather than being read
  as "no match".

The only exemptions are pins carrying a reason and a `proves` pattern that is
re-run against the file. Today there are three, all the same claim: Search
ships `messages.php` empty in every locale, English included, and an empty
array is in no script. When Search gains its first message the pattern stops
matching and the guard demands the translation rather than waving the file on.

## Related

- [Copy that carries a count](counted-nouns-in-copy.md) — how a number and a
  noun are written together, and which arm each locale selects
- [Conventions](00-index.md) — the comment policy these markers are shaped by
