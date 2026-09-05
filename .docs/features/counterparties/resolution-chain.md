# Counterparty resolution chain

A bank statement row does not tell you who it was with. It carries a
free-text description, an optional counterparty name, and an optional
IBAN — three weak, partly-missing signals. The module's job is to turn
that into one stable identity per `(user_id, slug)` so every downstream
surface groups, filters, and links by the same key.

Neither obvious approach survives contact with real statements. Keying
on the description string alone splits one merchant across every
spelling a payment terminal produces — `ALBERT HEIJN 1234` and
`AH AMSTERDAM ZUID` become two counterparties, two category
suggestions, two chart slices. Keying on the IBAN alone loses most of
the data set, because card and terminal rows carry no counterparty IBAN
at all, and it is actively wrong for people: a private IBAN must never
become a routing key that ends up in a URL.

`Modules\Counterparties\Internal\Resolver\CounterpartyResolverService`
therefore runs an ordered chain of matchers over both signals and takes
the first hit. The order is the classification rule, not an
implementation detail — reordering the list changes what a row resolves
to.

## The taxonomy

Every resolved row lands on one of five real types, plus a sixth value
for rows the chain could not place.

| `type` | What it means | Written as a row? |
|---|---|---|
| `merchant` | A business the user buys from | Yes |
| `personal` | A P2P partner — a private individual | Yes, name-only slug |
| `bank` | A financial institution, including the user's own bank's fees | Yes |
| `government` | A tax office, municipality, or agency | Yes |
| `self_account` | One of the user's own accounts | **No** |
| `unknown` | Nothing matched; queued for triage | Yes |

`Modules\Counterparties\Public\Enums\CounterpartyType` is the canonical
spelling of those values. The `counterparties.type` column itself stays
a plain string guarded by paired `BEFORE INSERT` / `BEFORE UPDATE OF
type` triggers, so a typo in the application layer fails at the
database boundary instead of landing a silently-broken row. The
`all` / `self` filter aliases in the index and profile views are UI
vocabulary only and are never stored.

`self_account` is the one type the resolver never materialises. The
user's own accounts already have their own `/accounts/{slug}` surface,
so step 1 returns a DTO with `counterpartyId = null` and writes
nothing.

## The chain, in order

`resolve()` builds six closures, calls them in sequence, and returns
the first non-null result. Step 7 is the fallthrough after the loop.

### 1. Self-account check

Normalises `counterpartyIban` (whitespace stripped, upper-cased) and
looks for a matching `accounts.iban` row for this user. A hit
short-circuits to `type = 'self_account'` with a null id.

This runs first because a transfer between the user's own two accounts
looks exactly like a P2P transfer to the later steps: it is a
`transfer_in` / `transfer_out` row with a valid IBAN and a
personal-looking name. Step 4 would happily classify the user as their
own P2P partner.

### 2. Known-counterparty-IBAN bridge

Asks `Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban`
whether this IBAN is a known institution bridge — the PayPal
Luxembourg and ICS-at-ABN-AMRO IBANs that `Chains` uses for account
routing. A hit resolves to `type = 'bank'` with
`metadata.bridge_account_kind` and `metadata.institution_iban`.

It runs before merchant resolution because IBAN evidence is exact where
description evidence is fuzzy. A PayPal settlement row's description
would otherwise reach the merchant resolver and become a `merchant`
counterparty named after whatever the description happened to say.

The bridge contract returns the user's own `Account` — it was built for
account routing, not for display — so the resolver reads
`known_counterparty_ibans.notes` directly for the institution's legal
name rather than widening a cross-module contract for one string. When
`notes` is empty it falls back to the transaction's own counterparty
name, then to the IBAN.

### 3. Merchant resolution

Runs the description through
`Modules\Import\Public\Services\MerchantNameResolver`, which owns the
alias and corpus matching. A hit becomes `type = 'merchant'` with
`merchant_name` set to the resolved name and kept beside the row's own
`display_name`; only a merchant row carries one.

`display_name` is the name the row's own file gave it, and only falls
back to the resolved name when the file named no counterparty. The
resolver reads the *description*, so what it returns is a stand-in for
a counterparty the file did not name — the same rule the import
preview's counterparty column applies, and the reason the two agree. An
N26 export puts `REWE` in Partner Name and `Groceries` in Payment
Reference; a reader who confirms a preview row reading REWE must not
find the alias's name on it afterwards. The corpus keeps its value
where it was earned — on the terminal noise a description carries, and
on `merchant_name`, which every alias-anchored surface joins against.
Because the slug follows the display name, the entity a row lands on is
keyed by the name the reader was shown.

Five tiers, in order: the reader's own exact alias, the reader's own
generalized alias, then the corpus exact, generalized and regex
lookups. The order matters — the alias tiers run *first*, so an
unanchored match there wins before the corpus is even asked.

**Within a generalized tier, the most specific pattern wins.** Both
generalized tiers sort their candidates longest-needle-first before
scanning, so the first hit is the narrowest one and the scan still
short-circuits. `usort` has been stable since PHP 8.0, so equal-length
needles keep the order they loaded in and the result stays
deterministic.

This is not a tie-breaker detail. A clean install with **no country set**
— what anyone who skips the signup selector has — loads every region at
once, and `Albert Heijn 1042` matched the Czech `ALBERT`
(`merchants/cz.yaml:11`) as well as the Dutch `ALBERT HEIJN`
(`merchants/nl.yaml:31`). `CorpusLoader` sorts filenames, so `cz.yaml`
seeds first, takes the lower id, and won the first-match scan. The
import preview — the first thing a new reader ever sees of their own
data — showed a Dutch supermarket as a Czech chain. Longest-needle-first
fixes that case on its own merits, without depending on the reader
having answered the country question at all.

**Every pattern is matched as a whole token**, alias tiers included, via
`CorpusPatternMatcher::containsToken()`: a bare `mb_stripos` found the
corpus token `OBI` inside "m*obi*el" and turned a phone bill into a DIY
chain. The boundary is asserted only where the needle's own edge is
alphanumeric, so `AMAZON.` still matches `AMAZON.NL`.

**The alias tiers read one memoised list per reader.** Both of them share
it, loaded and sorted once instead of two reads and a sort per
transaction, and keyed by user id because the resolver is a singleton —
see [merchant aliases](../import/architecture.md#merchant-aliases) for
what a writer owes the memo.

**The corpus tiers are scoped to the reader's country**, the alias tiers
are not. `MerchantNameResolver::regionFor()` reads `UserCountry::current()`
(memoised — this runs once per transaction across a whole import) and
passes it to all three `CommunityCorpusQuery` lookups, which filter
`community_merchant_mappings.region`. Without it a Dutch
`Betaalautomaat Albert Heijn 1042 Amsterdam` resolved to the Czech chain
`ALBERT`: `CorpusLoader` sorts its files, so `cz.yaml` seeds before
`nl.yaml`, takes the lower id, and wins the first-match scan. The word
boundary cannot help there — `albert` genuinely is a whole token in that
description.

Two deliberate widenings, both the merchant corpus's own:

- **A reader who has named no country gets every region**, not nothing.
  `UserCountry::current()` returns `''` for that state and the filter is
  skipped, so someone who never answered the country question still gets
  merchant hits. Steps 5 and 6 read that same empty region the opposite
  way and skip themselves entirely, which is why `ZORGPREMIE` — the
  ordinary Dutch word for a health-insurance premium, and a Belgian
  government pattern — cannot reach a reader with no country. A merchant
  can be international; a government body and a bank's fee cannot.
- **A mapping whose own `region` is null or empty matches every reader.**
  The column is nullable and `CorpusLoader` leaves it empty for a file it
  could not read a code from, so excluding those would drop mappings
  nobody meant to scope.

The reader's own aliases are never region-scoped: they are theirs
wherever they live, and region is a property of the shared corpus, which
holds every country's merchants at once and whose short tokens collide
across them.

### 4. Personal-IBAN heuristic

The one step with a real predicate rather than a lookup. All four
conditions must hold:

- `type` is `transfer_in` or `transfer_out`.
- The IBAN is structurally valid SEPA — mod-97 checksum plus the
  country's own BBAN length, via `jschaedl/iban-validation`. Not a
  Dutch-only check.
- The counterparty name is non-empty.
- The name looks personal: at most 4 whitespace-separated tokens, none
  of which is a legal-entity marker. The marker list is
  `MERCHANT_NAME_MARKERS` on the resolver — `BV`, `B.V.`, `B.V`, `NV`,
  `N.V.`, `LTD`, `LIMITED`, `INC`, `INC.`, `GMBH`, `AG`, `SARL`, `SA`,
  `PLC`, `CORP`, `CO.`, `LLC` — compared after upper-casing and
  stripping a trailing comma.

It runs *after* merchant resolution deliberately. A small business with
a short trading name and its own IBAN would pass every one of those
four conditions; letting the merchant resolver answer first means a
business the user already has an alias for stays a merchant.

**Privacy default:** the display name is the trimmed counterparty name
and nothing else, so the slug derives from the name alone and the IBAN
never reaches a URL. The IBAN is still stored on the row's `iban`
column; it simply never routes. `PrivacyDefaultsTest` guards this.

### 5. Government keyword fallback

### 6. Bank-fee keyword fallback

Both delegate to the same `resolveByRules()` helper over a haystack of
`description . ' ' . counterpartyName`. The patterns come from
`Modules\Community\Public\Services\ClassificationRuleProvider` —
`governmentRules()` and `bankFeeRules()`, read from the per-region
corpus YAML — and are matched by `CorpusPatternMatcher`, which handles
both literal and regex patterns. Neither step asks for rules at all
unless the reader has named a country:
`CounterpartyResolverService::namesANationalInstitution()` reads an
empty region and each step returns null before the provider is reached.
Government hits record `metadata.matched_keyword`; bank-fee hits add
`metadata.subcategory = 'fee'`
(`Internal\Enums\CounterpartySubcategory::Fee`, under
`CounterpartyMetadataKey::Subcategory`), which is what the profile page
branches on — `CounterpartyProfileDto::$isBankFee` — to render a fee
panel rather than an institution one. The DTO carries the answer as a
bool rather than the token: it is Public, and a Public class may not put
an Internal type on the cross-module surface. Step 2's bridge lands on the same
`type='bank'` and carries no such flag, and the two rows are not the
same claim about the money: a PayPal settlement under a heading reading
"Bank fees by category" tells the reader their bank charged them for
their own purchase.

These two run last among the matchers because a keyword hit is the
weakest evidence in the chain. `KOSTEN` appears in plenty of
descriptions that are not bank fees, and anything with a real IBAN
match or a real merchant alias deserves to win first.

Government display names have their own rule in
`governmentDisplayName()`, because the pattern is not always presentable
copy:

- A **literal** pattern found verbatim inside the counterparty name
  keeps the fuller name — `GEMEENTE UTRECHT` beats a generic
  `Gemeente`.
- A **regex** pattern cannot be substring-checked and is not human
  copy, so it falls back to the rule's own name, then the transaction's
  name, then the app's own word `Government`. Nothing else would stop
  PCRE syntax from landing in both the UI and the slug.

A bank-fee rule with no `name` falls back the same way, to the app's own
`Bank fee`. Every entry in every shipped `bank-fees` file carries a name
today, so that arm is a guard rather than a live path.

The name those entries carry is the fee word in the jurisdiction's own
language — `Bankkosten`, `Rente`, `Χρεωστικοί τόκοι`. That is a word for the
reader who named that country and nothing at all in the other twenty-five
languages, so each entry also carries a `key`: the KIND of charge the word
names, from a closed vocabulary of eighteen
(`CounterpartyDefaultName::FEE_KINDS`). The jurisdiction's word still goes in
the column, because the slug is derived from it; the kind travels beside it as
the provenance token, and is what every other reader sees.

Both of those, and step 7's `Unknown`, are the app's words rather than the
file's — see [the app's own words](#the-apps-own-words-for-a-row-it-had-to-name)
for why they are marked and re-resolved per reader.

### 7. Unresolved

Everything left becomes `type = 'unknown'`, keeping the IBAN so the
triage page can show recent activity on it. The display name is the
counterparty name, or the IBAN, or the app's own word `Unknown`, in that
order.

The IBAN stays the *display* name in the middle case, and deliberately:
`display_name` is sealed, and it is the only thing the reader has to
recognise the row by — a triage queue of rows all called "Unknown" is
not a privacy improvement. What it must not become is the *slug*. The
slug is plaintext and it is the URL, so the middle case is exactly the
name [`routableBase()`](#slug-allocation-and-the-decrypt-before-compare-rule)
refuses to derive a slug from.

The one case that produces no row at all is a transaction with no name,
no IBAN, *and* no description. The resolver returns null, the writer
layer still persists the transaction without a `counterparty_id`, and
there is simply nothing for triage to show.

Unknown rows are the input to
[triage suggestions](triage-suggestions.md).

## The app's own words for a row it had to name

Three of the arms above name a row with a word that came from the app rather
than from the reader's file or the corpus: `Unknown` in step 7, `Government`
for a regex government rule with no name, and `Bank fee` for a bank-fee rule
with no name.

A fourth case reaches the same seam from the other direction. A bank-fee rule
that *does* carry a name carries the jurisdiction's word for the charge, which
is the corpus's language rather than the reader's; it re-resolves here too, by
the kind the entry declares rather than by a word the app chose.

A word like that stored in `display_name` is frozen in the language the import
ran in. A phone set to Dutch showed "Onbekend" four times on `/counterparties`
and "Unknown" once, on the counterparty row itself, because that one came out
of the column while the other four came from
`counterparties::components.type_chip.unknown`.

`Modules\Counterparties\Public\Support\CounterpartyDefaultName` is the seam
that fixes it, and it follows `Modules\Ledger\Public\Support\CategoryDisplayName`,
which does the same job for `categories.name_is_default`:

- The **word the row was created with still goes in the column** — the app's
  English for the three it names itself, the corpus's own word for a fee it
  named. The slug derives from the display name, so a translated name would
  fork the row per reader, and a reader whose locale has no line for the token
  keeps something legible.
- **`metadata.default_name` carries the token** — `unknown`, `government`, or
  one of the eighteen bank-fee kinds, of which `bank_fee` is the generic one.
  This table already keeps its row flags in `metadata`
  (`ignored`, `subcategory`) rather than in dedicated columns, so the mark
  needs no schema change and travels with the row the way those two do.
- **Every read site resolves through `CounterpartyDefaultName::resolve()`**:
  `CounterpartyIndexQuery`, `CounterpartyProfileQuery`,
  `CounterpartyDisplayName` (which is also what the transaction detail picker,
  the rule form and the report builder read) and `CounterpartyTriageQueue`.
  Three sites outside this module read the column too, and they resolve the
  same way: `Modules\Tax\Internal\Services\TaxYearQuery` (the cockpit row, and
  through it the CSV and PDF exports),
  `Modules\Tax\Public\Services\TaxTagQuery` (the batch-tag banner) and
  `Modules\Search\Internal\Services\EntityNameSearch` (the ⌘K palette).
- **The palette matches on the reader's word as well as the stored one.**
  Translating on the way out alone would leave a Dutch reader unable to *find*
  a row their own screen calls "Onbekend". A category is matched by a SQL
  predicate on `name_is_default` plus the slugs the term translates to, but a
  counterparty's `display_name` is ciphertext at rest, so the palette has no
  SQL name predicate to widen: it already reads the reader's own counterparties
  whole and matches in PHP. Resolving the token in that same pass costs no
  extra row and no extra statement, and works on an encrypted install where a
  `metadata` predicate would still have to be paired with a name match SQL
  cannot do. The stored English keeps matching beside the translation, the way
  a default category's does.
- **`LabelCounterparty` clears the mark.** Once the reader has named the row,
  the words are theirs and stay verbatim in every language.
- **`CounterpartySlugResolver` deliberately does not resolve.** It compares the
  *stored* name to decide whether a slug is free; translating there would
  fragment one counterparty into one row per language the reader has used.

The mark is written with the name it belongs to and never re-asserted by the
refresh pass, which leaves `display_name` alone for the same reason. Rows
written before the seam existed are marked by
`2026_08_30_000002_mark_the_counterparty_name_the_app_invented_as_its_own`,
which recognises them by `type='unknown'` and `slug='unknown'` — both plaintext,
so it reads the same on an encrypted install, and neither reachable for a row the
reader labelled themselves.

Fee rows the corpus named are marked by
`2026_09_05_000002_mark_a_seeded_bank_fee_name_as_the_apps_own`, on the same
principle and under the same constraint: it cannot read `display_name` either,
so it asks the slug that column is derived from and marks a row only where the
slug is still exactly what the corpus's own word slugifies to. Twenty-five of
the 257 corpus names are written in a non-Latin script and slugify to the
resolver's opaque fallback, so those rows are left alone rather than guessed
at — they take the kind from the resolver on the next import instead.

## Writing the row

`upsert()` is the single write path for steps 2 through 7.

It resolves a slug (below), then `firstOrCreate`s on
`(user_id, slug)`. `slug` and `type` are stored plaintext because they
are the matching and routing keys; `display_name`, `merchant_name`,
`iban`, and `metadata` go through
`Modules\Sync\Public\Services\SensitiveColumnCodec::encryptAttrs()`.

When the row already existed, `type`, `iban`, `merchant_name` and
`metadata` are refreshed from the current resolution — a `null` from
this pass means it knows less, not that the stored value is wrong, and
a `type` of `unknown` never overwrites a known one. `display_name` is
deliberately left alone *on this path*: an import re-reading the same
row is not the reader renaming it, and the slug is derived from the
name, so a different name here is a different row. The reader renaming
it in triage is the other case, and there the slug moves with the name
— see [triage suggestions](triage-suggestions.md#what-the-user-does-with-it). Without the refresh the returned DTO reported
the fresh classification while the stored row kept the first pass's, so
a row that landed `unknown` never left `CounterpartyTriageQueue` (which
selects strictly `type='unknown'`) and a row that later resolved to a
merchant kept the NULL `merchant_name` its first pass had written.

Events:

- `EntityMutated` — `create` when the row was created and `edit` when
  the refresh above wrote something, both with **plaintext** field
  values. `OpLogWriter` encrypts sensitive columns
  itself under the current key epoch and the backfiller decrypts before
  handing them over; passing the stored ciphertext would encrypt it
  twice and the peer would never read it back. `metadata` is part of
  the payload because `website` and `logo_url` live in there and
  nowhere else — omitting it landed the counterparty on the peer with
  both fields blank.
- `CounterpartyResolved` — on every upsert, created or not.

The resolver takes a `SessionFactory` rather than a `Session`.
Resolving a session builds the encrypter, and this class is reachable
from a console command that Artisan constructs merely to list it.

## Slug allocation and the decrypt-before-compare rule

`CounterpartySlugResolver::resolveUnique()` owns the `(user_id, slug)`
UNIQUE constraint in application code.

### The one name a slug is never derived from

`routableBase()` sits between `slugify()` and the walk, and it asks one
question: does the slugified name spell an **account identifier** — the
ISO 13616 shape, or the bare account number a file carries where no
IBAN exists? If it does, the base is `OPAQUE_BASE` (`unnamed`) instead
of the name.

It is a property of the seam rather than of an arm, and that is the
point. Three separate places in this repository asserted that the IBAN
never reaches a URL — the `personal` arm's privacy note above, the
`create_counterparties_table` migration's comment on the column, and
the personal profile tab's own `The full IBAN never appears in the URL`
— and all three were true of the arm the author was looking at and
false of the row beside it. Arms 2 and 7 both fall back to the IBAN for
a display name when the file names nobody, and the slug follows the
display name, so `/counterparties/nl91abna0417164300` was a real route.
`upsert()` is the single write path, `resolveUnique()` is its single
slug source, and asking there is what makes the answer hold for an arm
nobody has written yet — including a triage rename, where the name is
whatever the reader typed.

The suffix walk is unchanged, so the opacity costs no matching: two
nameless rows become `unnamed` and `unnamed-2`, told apart by the
holder's decrypted display name exactly as `bol` and `bol-2` are, and
the same statement re-imported lands back on the row it made.
`2026_09_05_000001_replace_counterparty_slugs_that_spell_an_account_number`
renames the rows already written, reading the stored slug's shape
because it is the only unsealed evidence on the row and it has to run
before any device is unlocked.

`slugify()` folds to ASCII, lower-cases, collapses every run of
non-alphanumerics to a single `-`, trims stray dashes, and falls back
to the literal `counterparty` if nothing survives. The result is cut to
128 characters — the width of the `slug` column that carries the
UNIQUE. The fold happens in PHP rather than through the C library, so
two devices on different operating systems derive the same bytes from
the same merchant name — see
[`counterparties.slug` is a cross-platform key](slug-is-a-cross-platform-key.md).

It is deliberately **not** `UniqueSlug::slugify()`, the shared
`Str::slug()` helper that `AccountSlugResolver` and the migration
promoter use. The two share only the transliteration half and disagree
on ASCII alone: `Coolblue B.V.` slugs to `coolblue-b-v` here and
`coolblue-bv` there, `Shop 24/7` to `shop-24-7` against `shop-247`.
Because `upsert()` `firstOrCreate`s on `(user_id, slug)`, the slug is a
stored identifier and not a formatting choice: swapping the slugifier
would miss every already-stored merchant whose name carries a separator
the other one deletes and fork it into a second row on the next import
— the same fragmentation the decrypt-before-compare rule below exists
to prevent. `CounterpartySlugifierIsFrozenTest` pins the difference so
the swap cannot be made by accident.

Resolution never re-derives a slug for a row that has one, so the
slugifier reaches counterparties created for the first time and the one
other caller that re-slugs deliberately: a triage rename.

Collisions walk a numeric suffix: `bol`, then `bol-2`, `bol-3`, and so
on until a free slug appears — `Modules\Core\Public\Support\UniqueSlug::walk()`,
shared with `AccountSlugResolver` and the migration promoter, which asks
this class's own free-predicate rather than carrying one. A slug counts
as free when no row holds it **or** when the row holding it is this same
counterparty.

"This same counterparty" is answered two ways, and `resolveUnique()`'s
optional `$ownedBy` argument picks which. An import has no row yet, so
it can only ask by name — the decrypt-before-compare rule below. A
rename does have one, so it asks by id, and it has to: two rows may
legitimately carry the same display name, and answering by name there
walked one row straight onto another's slug and hit the
`(user_id, slug)` UNIQUE.

That second half is the load-bearing part. `display_name` is an
encrypted column, and AEAD ciphertext never byte-equals its plaintext —
a naive comparison would decide that every already-resolved
counterparty is "taken by a different name" on every single re-import,
and would fragment one merchant across `bol`, `bol-2`, `bol-3`,
forever. `slugIsFreeFor()` therefore decrypts the stored name via
`SensitiveColumnCodec::decryptValue()` before comparing identity.

The decrypt never throws. An undecryptable value comes back as raw
ciphertext, which fails the identity comparison and falls through to
suffixing — a spare slug, never a wrongly merged row.
`SlugCollisionTest` covers the walk.

## Cross-user scoping

Every query in the chain carries an explicit
`where('user_id', $user->id)` on the raw query builder. The
`BelongsToUser` global scope on the `Counterparty` model is a secondary
guard that only fires when an Eloquent query reaches the model inside
an HTTP-bound request — and most of the resolver's callers are the
import pipeline, queue workers, and console commands, where the scope
is silent. `CashBook`'s `RecordManualTransaction` is the one that runs
inside a request, and it carries the same explicit filter because the
chain does. The explicit filter is the real scope; the trait is defence
in depth.

## Related

- [Module architecture](architecture.md) — the surface map, the
  Public/Internal boundary, and the data flow diagrams.
- [Triage suggestions](triage-suggestions.md) — what happens to the
  `unknown` rows step 7 produces.
- [Retention](retention.md) — why a resolved row is kept for good.
- [`counterparties.slug` is a cross-platform key](slug-is-a-cross-platform-key.md)
  — why the slugifier transliterates in PHP rather than through the C
  library, and which stored slugs change with it.
