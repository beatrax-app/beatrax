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
- It never cascade-deletes its history. The
  `transactions.counterparty_id` FK is from the user side only; an
  orphan counterparty pruned by the garbage-collector job is preceded
  by an explicit `UPDATE transactions SET counterparty_id = NULL` on
  every referencing row, so the historical transaction stays put.
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
`metadata` JSON cast carries per-type opaque payload (e.g. the
`subcategory => 'fee'` flag on bank-fee rows, bridge provenance on
known-counterparty-IBAN rows) so type-aware profile pages render
without a second lookup.

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
    `Internal\Pipeline\ResolveCounterpartyStage`.
- **DTOs/**
  - `CounterpartyResolutionDto` — `(counterpartyId, slug, type)`
    returned by the resolver. `counterpartyId = null` when
    `type='self_account'`.
- **Events/**
  - `CounterpartyResolved` — `(counterpartyId, userId, type)` fired on
    every successful upsert. v1.0.0 ships zero listeners; reserved
    for future audit / merge / notification surfaces.
- **Queries/**
  - `CounterpartyIndexQuery` (+ row DTO `CounterpartyIndexRow`),
    `CounterpartyProfileQuery` (+ `CounterpartyProfileDto` +
    `ChainSummary`), `CounterpartyTriageQueue` (+ `TriageSuggestion`).

`Internal/` houses the implementation:

- **Internal/Resolver/CounterpartyResolverService** — the 7-step
  precedence chain.
- **Internal/Pipeline/ResolveCounterpartyStage** — the `ImportPipeline`
  glue.
- **Internal/Jobs/CounterpartyGarbageCollectorJob** — the daily
  per-user prune, `ShouldBeUniqueUntilProcessing` keyed on user id.
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
     with `metadata.subcategory='fee'`.
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

- `CounterpartyGarbageCollectorJob` — daily per-user prune.
  Two-key safety: a row survives if either (a) any transaction in the
  last 365 days references it OR (b) a `merchant_aliases` row anchors
  it via `friendly_name = counterparties.merchant_name`. The prune
  runs in one DB transaction, `UPDATE transactions SET counterparty_id
  = NULL` first, then the `DELETE`.

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
history depth. Rows are read via the raw query builder rather than
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
queue with keyboard-first ergonomics:

| Key | Action |
|---|---|
| `Y` | Accept current suggestion + advance |
| `N` | Reject suggestion + focus manual-label section |
| `S` | Skip for now (re-queues at end of session) |
| `→` | Next unknown |
| `Esc` | Close triage (return to `/counterparties`) |

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

## Garbage collector — encryption-aware orphan predicate

`CounterpartyGarbageCollectorJob` runs daily per user
(`ShouldBeUniqueUntilProcessing` keyed on `"{userId}"`, `tries=3`,
`backoff=[60,300,900]`, lock via `LockStore::forUniqueJobs()`) and
prunes `counterparties` rows that are orphans by a two-key check: no
transaction has linked to the row within 365 days AND no
`merchant_aliases` row anchors it via `friendly_name =
counterparties.merchant_name`. A row survives if either key holds — a
merchant-alias anchor survives a quiet year, and recent activity
survives an alias rename. The prune runs inside a single DB
transaction; `transactions.counterparty_id` is NULLed for every
referencing row before the `DELETE`, so history is never lost (the FK
carries no cascade, by design — see `add_counterparty_id_to_transactions`).
Every clause is scoped by an explicit `user_id`; the job never touches
another user's rows.

`counterparties.merchant_name` is a `SensitiveFieldRegistry` encrypted
column, but the job's sole dispatch origin is the daily
`counterparties.gc` scheduler tick — a queue worker with no live
Session, and therefore never an app-lock KEK. The orphan predicate's
`merchant_name IS NOT NULL` half (a raw-SQL `whereColumn` equality
against the always-plaintext `merchant_aliases.friendly_name`) cannot
be evaluated as-is once encrypted, since AEAD ciphertext never
byte-equals plaintext. `handle()` therefore branches three ways for
that half:

- **Not encrypted** — the original raw-SQL equality runs unchanged.
- **Encrypted with a KEK available** (kept symmetric with
  `DetectRecurringSeriesJob`'s pattern for a future request-bound
  dispatch origin; never true for today's sole daemon origin) —
  candidates are loaded and `SensitiveColumnCodec::decryptValue()`
  decrypts each row's `merchant_name` in PHP for comparison against the
  user's alias `friendly_name` set (mirrors
  `FingerprintStage::detectConflicts()`'s decrypt-before-compare
  template). Any candidate whose decrypt fails is skipped rather than
  compared, since a failed decrypt returning raw ciphertext would
  never match plaintext and would wrongly prune an alias-protected row
  — preserve-data-on-uncertainty, never a wrongful prune.
- **Encrypted with no KEK** — the half is skipped entirely and a
  warning naming the user and the skipped-row count is logged; those
  candidates are re-evaluated on a future run with an available KEK.

The `merchant_name IS NULL` half of the predicate is always
plaintext-safe (`SensitiveColumnCodec::encryptAttrs()` only encrypts
string values, so a NULL merchant_name is never turned into
ciphertext) and prunes unconditionally regardless of encryption state.

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

The daily garbage-collector:

```
Schedule → CounterpartyGarbageCollectorJob (per user, unique-until-processing)
  → BEGIN TX
       → identify orphans (no recent tx + no alias)
       → UPDATE transactions SET counterparty_id = NULL WHERE in (...)
       → DELETE FROM counterparties WHERE id IN (...)
     COMMIT
```
