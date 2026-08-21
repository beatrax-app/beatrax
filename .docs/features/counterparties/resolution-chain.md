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
`display_name` and `merchant_name` both set to the resolved name.
`merchant_name` is the column the garbage collector's alias anchor
joins against — see the retention rules in
[garbage collection](garbage-collection.md).

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
both literal and regex patterns. Government hits record
`metadata.matched_keyword`; bank-fee hits add
`metadata.subcategory = 'fee'`, which is what the profile page branches
on to render a fee row rather than an institution row.

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
  name, then the literal string `Government`. Nothing else would stop
  PCRE syntax from landing in both the UI and the slug.

### 7. Unresolved

Everything left becomes `type = 'unknown'`, keeping the IBAN so the
triage page can show recent activity on it. The display name is the
counterparty name, or the IBAN, or the literal `Unknown`, in that
order.

The one case that produces no row at all is a transaction with no name,
no IBAN, *and* no description. The resolver returns null, the writer
layer still persists the transaction without a `counterparty_id`, and
there is simply nothing for triage to show.

Unknown rows are the input to
[triage suggestions](triage-suggestions.md).

## Writing the row

`upsert()` is the single write path for steps 2 through 7.

It resolves a slug (below), then `firstOrCreate`s on
`(user_id, slug)`. `slug` and `type` are stored plaintext because they
are the matching and routing keys; `display_name`, `merchant_name`,
`iban`, and `metadata` go through
`Modules\Sync\Public\Services\SensitiveColumnCodec::encryptAttrs()`.

Two events fire:

- `EntityMutated` — only when the row was actually created, and with
  **plaintext** field values. `OpLogWriter` encrypts sensitive columns
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

`slugify()` transliterates to ASCII (`iconv` with
`ASCII//TRANSLIT//IGNORE`), lower-cases, collapses every run of
non-alphanumerics to a single `-`, trims stray dashes, and falls back
to the literal `counterparty` if nothing survives. The result is cut to
128 characters — the width of the `slug` column that carries the
UNIQUE.

Collisions walk a numeric suffix: `bol`, then `bol-2`, `bol-3`, and so
on until a free slug appears. A slug counts as free when no row holds
it **or** when the row holding it is this same counterparty.

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
an HTTP-bound request — and the resolver's primary callers are the
import pipeline, queue workers, and console commands, where the scope
is silent. The explicit filter is the real scope; the trait is defence
in depth.

## Related

- [Module architecture](architecture.md) — the surface map, the
  Public/Internal boundary, and the data flow diagrams.
- [Triage suggestions](triage-suggestions.md) — what happens to the
  `unknown` rows step 7 produces.
- [Garbage collection](garbage-collection.md) — the retention rules
  that decide when a resolved row is pruned again.
