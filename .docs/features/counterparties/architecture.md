# `Counterparties` — architecture

The `Counterparties` module materialises every entity the user
transacts with — merchants, personal P2P partners, banks, government
agencies, the user's own accounts, and as-yet-unresolved unknowns —
as a `counterparties` row per `(user_id, slug)` so the rest of the
codebase can route, filter, group, and link by counterparty identity
instead of re-running pattern matching on raw description strings.

## What this module is for

Before this module existed, a row labelled "ALBERT HEIJN" on one
statement and "AH AMSTERDAM" on another was indistinguishable to the
downstream surfaces: two different rows, two different category
suggestions, two different chart slices. With a counterparty row, both
ledgers point at the same `counterparties.id`; a single click on the
`/counterparties/{slug}` page shows every transaction across every
source.

The 5-type taxonomy (`merchant` / `personal` / `bank` / `government` /
`self_account`, plus `unknown` for unresolved rows in the triage
queue) drives the per-type Blade partials on the profile page and the
type-chip / type-color language across the UI.

What the module explicitly does NOT do:

- It never echoes a personal IBAN into a URL or slug. The privacy
  default for `type=personal` is hard: slug = kebab-cased display name
  only; the IBAN IS preserved on the row's `iban` column but never
  surfaces in routing.
- It never materialises a `counterparties` row for `type=self_account`.
  The user's own accounts have their own `/accounts/{slug}` surface;
  the resolver short-circuits with `counterpartyId=null` and the
  profile page routes back to the account view.
- It never cascade-deletes its history, and it never deletes its own
  rows on a timer either: counterparties are kept indefinitely and
  nothing scheduled writes to `transactions` (see
  [retention](retention.md)). The `transactions.counterparty_id` FK is
  from the user side only, so even a delete arriving from a peer takes
  no ledger row with it.
- It never resolves itself. The 7-step precedence chain depends on
  contracts owned by other modules — `ResolvesKnownCounterpartyIban`
  from `Import`, `MerchantNameResolver` from `Import`. The resolver is
  the orchestrator, not the matcher set.

## Data model notes

The allowed `type` values (`merchant`, `personal`, `bank`, `government`,
`self_account`, `unknown`) are enforced by paired BEFORE INSERT / BEFORE
UPDATE OF type triggers on the `counterparties` table, so a typo in the
application layer fails loud at the DB boundary rather than landing a
silently-broken row. `self_account` is part of the trigger set even
though the resolver never actually writes a row of that type. The
`metadata` JSON cast carries per-type payload (bridge provenance on
known-counterparty-IBAN rows, the matched pattern on corpus hits) so a
type-aware profile page renders without a second lookup.

Two of those keys are read back rather than merely stored, and
`Internal/Enums/CounterpartyMetadataKey` is where both are spelled:
`Subcategory`, whose only value is `CounterpartySubcategory::Fee`, and
`Ignored`, the triage-queue exclusion. `metadata` is not a
`SensitiveFieldRegistry` column, so both are readable in SQL —
`CounterpartyMetadataKey::column()` gives the JSON path, which is how
the queue's predicate and the profile's branch stay one spelling.

A third, `default_name`, marks a `display_name` the resolver had to invent
rather than read off the file, so the word follows the reader's language
instead of the importer's — see
[the app's own words](resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name).

`type='bank'` is the one type two chain steps write for two different
things: step 2's bridge lands an institution the reader transacts
*through*, step 6's corpus lands a charge the bank levies. The fee flag
is what tells them apart, and the profile body needs it — a PayPal
settlement under a heading reading "Bank fees by category" tells the
reader their own bank charged them for a purchase they made.

Two vocabularies describe those six values, and they are not the same
one. `Public/Enums/CounterpartyType` spells what the column stores.
`Internal/Enums/CounterpartyTypeFilter` spells what the index page's
chip row and its `?type=` query parameter accept: it adds a synthetic
`all` that matches every row rather than any stored value, and it
shortens `self_account` to `self`. `CounterpartyTypeFilter::toColumnValue()`
is the single place one becomes the other — it returns `null` for `all`,
which is how the query knows to apply no `type` predicate at all — and
`forColumnValue()` is the inverse the per-chip counts are keyed by. A
`?type=` value that is neither vocabulary reads as no filter.

Cross-user defense in depth: every production read/write carries an
explicit `where('user_id', ...)` filter through the raw query builder.
The `BelongsToUser` global scope on the `Counterparty` model is a
secondary guard that only fires when an Eloquent query reaches the
model inside an HTTP-bound request — it does not fire under queue
workers, console commands, or model-factory paths. Any new query path
must carry its own explicit filter regardless of the trait.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `CounterpartyResolver::resolve(CanonicalTransaction $tx, User $user)`
    — the single entry point cross-module consumers
    (`Import`, `Ledger`, `Chains`, `Recurring`, `Categorization`)
    inject when they need to know a row's counterparty.
- **Pipeline/**
  - `ResolvesCounterparties::run($tx, $user)` — the pipeline-stage
    contract `ImportPipeline` consumes. Bound to
    `Internal\Pipeline\ResolveCounterpartyStage`. `Migration`'s
    `PromoteStagingToDomain` and `CashBook`'s `RecordManualTransaction`
    consume the same seam, so a promoted row and a hand-typed one carry
    a `counterparty_id` on the same terms an imported one does.
- **DTOs/**
  - `CounterpartyResolutionDto` — `(counterpartyId, slug, type)`
    returned by the resolver. `counterpartyId = null` when
    `type='self_account'`.
- **Support/**
  - `CounterpartyDefaultName::resolve($storedName, $metadata)` — the one
    read seam that turns a name the app supplied into the reader's own
    language. Every surface that renders a counterparty name goes through
    it; `CounterpartySlugResolver` deliberately does not.
- **Events/**
  - `CounterpartyResolved` — `(counterpartyId, userId, type)` fired on
    every successful upsert. v1.0.0 ships zero listeners; reserved
    for future audit / merge / notification surfaces.
- **Queries/**
  - `CounterpartyIndexQuery` (+ row DTO `CounterpartyIndexRow`),
    `CounterpartyProfileQuery` (+ `CounterpartyProfileDto` +
    `ChainSummary`), `CounterpartyTriageQueue` (+ `TriageSuggestion`).

`Internal/` houses the implementation:

- **Internal/Actions/LabelCounterparty** — the write seam behind every
  triage decision: accept, hand-label, ignore. It re-derives the slug
  from the new display name, announces the write with `EntityMutated`
  so the reader's other device sees it, and owns the ignore predicate
  `CounterpartyTriageQueue` filters on.
- **Internal/Resolver/CounterpartyResolverService** — the 7-step
  precedence chain.
- **Internal/Pipeline/ResolveCounterpartyStage** — the `ImportPipeline`
  glue.
- **Internal/Http/Livewire/** — `CounterpartyIndex` (`/counterparties`),
  `CounterpartyProfile` (`/counterparties/{slug}`),
  `CounterpartyTriage` (`/counterparties/triage`).

`App\PhpStan\Rules\BoundaryRule` and the `pinnedCrossModuleInternalImports`
arch invariant forbid any other module from importing
`Modules\Counterparties\Internal\*`.

`CounterpartiesServiceProvider` binds both Public contracts as
singletons, loads migrations + the `counterparties::` view namespace,
registers the `counterparties` Blade component namespace so the
anonymous components under `Resources/views/components/*.blade.php`
are addressable as `<x-counterparties::type-chip />` etc., and
registers the three Livewire pages the routes file's class-string
bindings resolve against.

## Key services + events

- `CounterpartyResolverService::resolve` — walks the chain:
  1. **Self-account check** — user's own IBANs short-circuit to
     `type='self_account'` with `counterpartyId=null`.
  2. **Known-counterparty-IBAN bridge** — institution IBANs (PayPal
     Luxembourg, ICS at ABN AMRO) resolve to `type='bank'`. The
     resolver also reads the `notes` column on
     `known_counterparty_ibans` directly so the display name reflects
     the institution's legal entity.
  3. **Merchant resolution** — description run through
     `MerchantNameResolver` (`Import`); a hit produces `type='merchant'`.
  4. **Personal-IBAN heuristic** — a Dutch IBAN with a personal-looking
     name on a `transfer_*` row resolves to `type='personal'` with
     the privacy default.
  5. **Government corpus fallback** — descriptions matching a rule
     from `resources/corpus/government/<cc>.yaml` resolve to
     `type='government'`.
  6. **Bank-fee corpus fallback** — descriptions matching a rule from
     `resources/corpus/bank-fees/<cc>.yaml` resolve to `type='bank'`
     with `metadata.subcategory='fee'`, which is what separates them
     from step 2's institution rows on the profile page.
  7. **Unresolved** — `type='unknown'`; IBAN preserved for triage.

  Step 2's bridge contract returns the user's own `Account` (it was
  designed for Chains' account-routing), so the resolver reads the
  `known_counterparty_ibans.notes` column directly for a display name
  rather than widening that contract. Step 4's personal-IBAN heuristic
  validates any structurally valid SEPA IBAN (mod-97 checksum + the
  country's BBAN length via `jschaedl/iban-validation`, not just Dutch
  IBANs) paired with a name that fails the small-business marker list
  (`BV`, `NV`, `LTD`, `GMBH`, and similar legal-entity suffixes).
  Steps 5 and 6 take their rules from `ClassificationRuleProvider`,
  scoped to the reader's own country, and are skipped outright for a
  reader who has named none: a merchant can be international, a
  government body and a bank's fee cannot. `CorpusPatternMatcher`
  does the matching — a literal pattern as a whole token via
  `containsToken()`, a `regex:` pattern as a backtrack-bounded PCRE.

  Slug strategy: kebab-cased display name with per-user collision
  suffixing (`bol`, `bol-2`, `bol-3`, …). The DB-layer UNIQUE on
  `(user_id, slug)` plus the suffix-walk guarantees no two rows for the
  same user share a slug; the walk decrypts a candidate row's stored
  `display_name` (rotation-safe, try-every-epoch) before the identity
  comparison, since a naive ciphertext comparison would treat every
  already-resolved counterparty as "taken by a different name" on
  every re-import and fragment one merchant across `bol`, `bol-2`, …
  forever.

  Cross-user posture: every raw query carries an explicit
  `where('user_id', $user->id)` filter. The `BelongsToUser` global
  scope on the `Counterparty` model only fires under an HTTP-bound
  request; the resolver also runs from import/queue/console contexts
  where the scope is silent, so the explicit filter is load-bearing.

- `ResolveCounterpartyStage::run` — delegates to the resolver and
  stamps the FK on the canonical transaction via
  `withCounterpartyId()`. No-op when the resolver returns `null` or
  a `self_account` DTO.

- `CounterpartyResolved` event — fired by the resolver on every
  upsert. The event-emission discipline keeps the resolver loosely
  coupled to any future surface that wants to react.

## Profile query cross-user 404 contract

`CounterpartyProfileQuery::bySlug()` returns null when a slug is
unknown or belongs to a different user, and the route resolver maps
that to a `404`, never a `403` — no signal is emitted that the slug
exists in another user's set. The explicit `where('user_id', ...)`
filter on the raw query is the primary scope; `BelongsToUser` is a
defense-in-depth secondary check. The DTO's `iban` field IS populated
even for personal counterparties; every rendering path branches on the
user's explicit Show-IBAN click before echoing it.

## Index query aggregation

`CounterpartyIndexQuery::forUser()` computes each card/list-row's
12-month total, per-month average, and 12-bar sparkline via SQL
`GROUP BY`/`SUM` so per-render cost stays bounded regardless of import
history depth. All three — and the profile's own total and category
breakdown — take their window from
`Internal\Support\RollingTwelveMonths`: twelve whole calendar months
ending with the one in progress. The totals used to take a rolling
year while the bars took calendar months, so spend inside the headline
figure, and inside the average it is divided by, had no bar to appear
in — on the 1st of a month, a whole month of it. Rows are read via the raw query builder rather than
Eloquent, so the explicit `where('user_id', ...)` filter is the
load-bearing scope (`BelongsToUser` only fires under HTTP-bound
Eloquent surfaces). `CounterpartyIndexRow` carries no `iban` field at
all — the personal-type privacy default extends to the index DTO shape
itself, not just a null value.

The row also carries its own `href` and its two formatted amounts,
derived once in the DTO constructor. The index emits every row up to
three times — the cards grid, the phone list and the desktop table, the
last two both rendered and then hidden by `.phone-only`/`.desktop-only`
— and each copy was formatting the amounts and rebuilding the link from
the same three fields.

## Triage keyboard shortcuts

`CounterpartyTriage` (`/counterparties/triage`) is a focused single-card
queue with keyboard-first ergonomics. It is the app's second writer of
`counterparties` and behaves like one: every decision goes through
`LabelCounterparty`, which announces it to the op-log exactly as the
resolver does.

| Key | Action |
|---|---|
| `Y` | Accept current suggestion + advance |
| `N` | Reject suggestion + focus manual-label section |
| `S` | Skip for now — advance without writing |
| `→` | The same movement; `skipForNow()` is a call to `nextItem()` |
| `Esc` | Close triage (return to `/counterparties`) |

`S` and `→` are one behaviour, and the card used to draw both of them:
`↷ Skip for now` as a ghost and `Next ▸` as the only solid button on the
screen. The louder of the two did none of the work, so the button is
gone and `Skip for now` is the single forward control. `nextItem()`
stays public because the `→` binding calls it.

`CounterpartyTriageQueue::suggestionFor()` walks up to 20 recent
transaction descriptions through `MerchantNameResolver` and tallies
matches: `high` confidence at ≥80% agreeing on the same merchant,
`medium` at ≥60%, `low` for at least one match below that. Null when no
description resolves to a known merchant. `TriageSuggestion::$confidence`
(`high`/`medium`/`low`) drives the
banner copy verbatim: `✨ Looks like **{name}** — confidence high`,
`✨ Maybe **{name}** — confidence medium`, or `Pattern match: **{name}**
— confidence low. Verify before linking.` `$reasoning` is the
load-bearing sub-line rationale — the suggestion is never rendered
without it.

The bindings respect the input carve-out in
`resources/views/layouts/app.blade.php`: when focus is inside an
`INPUT` / `TEXTAREA` / `contentEditable` element, keys go to the field,
not the handler — the view layer attaches listeners on the wire root
with Alpine focus-state tracking. Progress copy renders as
`{seen} of {total} · {percent} % · ~{minutes} min remaining`.

## The triage card's action area

Every control in the card — the two suggestion answers, the name box,
the type picker, the save, the skip, the ignore and the previous — is a
full-width block in a `.triage-stack` column, so the card's own content
box is the only left and right edge on the screen. The section was
hand-styled before, with seven inline `flex:` / `padding:` / `border:`
declarations of its own, and measured in headless Chromium with a coarse
pointer against the built stylesheet it drew **five distinct left edges
and seven right edges** at 411px in English; in German, Greek, Spanish,
French, Hungarian, Dutch and Ukrainian two of its buttons broke to three
lines inside a 44px box. It uses `x-core::form-field`,
`x-core::neutral-button` and `x-core::secondary-button` now, so across
all 26 locales at 375px and 411px, with and without a suggestion banner,
every control shares one left edge and one right edge, clears 44px,
clips nothing, and the page never scrolls sideways.
`Modules/Counterparties/tests/Feature/TheTriageCardDrawsOnePrimaryOnOneEdgeTest.php`
holds the structure that measurement depends on.

## What the personal-contact banner promises

`counterparties::components.privacy_banner.body` is the sentence a reader
sees above a personal contact: the IBAN is hidden until they reveal it,
and it stays out of exports. The first half is the reveal control on the
profile page. The second half was not true anywhere — `TaxCsvExporter` declares a
`counterparty_iban` column and wrote the decrypted value for every tagged
row, so a personal contact tagged into one tax year left their IBAN in a
CSV handed to an accountant, and on a phone in whatever the share sheet
was pointed at.

The type check now sits in `TaxYearQuery::counterpartyIban()`, upstream of
both the CSV and the PDF rather than at either render site. The column
stays in the export: a merchant's IBAN is what half the rows are
reconciled against, and dropping the header would break a spreadsheet the
reader already has.

`tests/Contracts/APersonalContactsIbanStaysOutOfTheExportItIsPromisedToArchTest.php`
holds both halves — that the counterparties table's IBAN column is read in
exactly one shipped place, and that the place names
`CounterpartyType::Personal`. One reader is what makes one check enough; a
second export is a second promise to keep, and the guard fails until
somebody has decided how.

## Retention

Nothing prunes `counterparties`, and nothing on a timer writes to
`transactions`. A row the resolver creates stays. Each device holds a
partial replica, so "no transaction points at this row" and "the
transactions that point at it have not arrived yet" are the same
observation — [retention](retention.md) carries the paired Mac and
iPhone it was measured on. `tests/Contracts/NoScheduledTaskPrunesUserDataArchTest.php`
holds the rule: it walks every scheduled command into the jobs it
dispatches and fails on a `->delete()` against a table of user data, or
an `->update()` setting a column of one back to `null`.

## Data flow

The import-time resolution:

```
ImportPipeline.preview
  → … parse / normalize / classify / auto-category
  → ResolveCounterpartyStage.run($tx, $user)
       → CounterpartyResolver.resolve($tx, $user)
            → step 1: self-account check
            → step 2: known-IBAN bridge
            → step 3: merchant resolution
            → step 4: personal-IBAN heuristic
            → step 5: government keyword
            → step 6: bank-fee keyword
            → step 7: unknown
            → firstOrCreate counterparty row keyed (user_id, slug)
            → dispatch CounterpartyResolved
       → tx.withCounterpartyId($dto->counterpartyId)
  → … fingerprint / persist
```

The user-facing surfaces:

```
GET /counterparties
  → CounterpartyIndex Livewire SFC
       → CounterpartyIndexQuery (per-type chips, filter, search)

GET /counterparties/{slug}
  → CounterpartyProfile Livewire SFC
       → CounterpartyProfileQuery
            → load row by (user_id, slug)
            → per-type Blade partial (bank / merchant / personal /
              government / self / unknown)
            → recent-activity tab, chain-summary, IBAN row

Routes target Blade wrapper views (`counterparties::index` /
`profile` / `triage`) that `@extends` the project's `layouts.app`, then
inline the matching Livewire component — Livewire 4's `#[Layout]`
attribute targets an `<x-layouts.app>` Blade-component shape that
doesn't match the project's `@extends` convention, so the wrapper view
sidesteps the mismatch. Route order is load-bearing: the literal
`/counterparties/triage` registers before the `/counterparties/{slug}`
placeholder so the router matches it first — reversing the order would
route `/triage` into the profile page with `slug = "triage"` and 404
for any user who doesn't happen to own a counterparty slugged `triage`.

GET /counterparties/triage
  → CounterpartyTriage Livewire SFC
       → CounterpartyTriageQueue (unresolved rows)
       → per-row TriageSuggestion (the heuristic the resolver
         would re-apply if the user nudges it)
```
