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

The per-module arch invariant `noReachIntoCounterpartiesInternal`
forbids any other module from importing
`Modules\Counterparties\Internal\*`.

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
  5. **Government keyword fallback** — descriptions matching
     `BELASTINGDIENST`, `GEMEENTE`, `RDW`, `CJIB`, `SVB` resolve to
     `type='government'`.
  6. **Description-keyword bank-fee fallback** — descriptions matching
     `KOSTEN KASOPNAME`, `RENTE`, `KOSTEN ` resolve to
     `type='bank'` with `metadata.subcategory='fee'`.
  7. **Unresolved** — `type='unknown'`; IBAN preserved for triage.

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
