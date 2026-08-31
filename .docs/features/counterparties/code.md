# `Counterparties` — code

The file-level map for the module.

## Directory layout

```
Modules/Counterparties/
├── Public/
│   ├── Contracts/
│   │   └── CounterpartyResolver.php
│   ├── Pipeline/
│   │   └── ResolvesCounterparties.php
│   ├── Dto/
│   │   └── CounterpartyResolutionDto.php
│   ├── Enums/
│   │   └── CounterpartyType.php
│   ├── Events/
│   │   └── CounterpartyResolved.php
│   ├── Queries/
│   │   ├── ChainSummary.php
│   │   ├── CounterpartyDisplayName.php
│   │   ├── CounterpartyIndexQuery.php
│   │   ├── CounterpartyIndexRow.php
│   │   ├── CounterpartyProfileDto.php
│   │   ├── CounterpartyProfileQuery.php
│   │   ├── CounterpartyTriageQueue.php
│   │   └── TriageSuggestion.php
│   └── Support/
│       └── CounterpartyDefaultName.php
├── Internal/
│   ├── Actions/
│   │   └── LabelCounterparty.php
│   ├── Enums/
│   │   ├── CounterpartyMetadataKey.php
│   │   ├── CounterpartySubcategory.php
│   │   └── CounterpartyTypeFilter.php
│   ├── Resolver/
│   │   ├── CounterpartyResolverService.php
│   │   └── CounterpartySlugResolver.php
│   ├── Pipeline/
│   │   └── ResolveCounterpartyStage.php
│   ├── Jobs/
│   │   └── CounterpartyGarbageCollectorJob.php
│   └── Http/Livewire/
│       ├── CounterpartyIndex.php
│       ├── CounterpartyProfile.php
│       └── CounterpartyTriage.php
├── Models/
│   └── Counterparty.php
├── Database/
│   ├── Migrations/
│   │   ├── 2026_05_27_020001_create_counterparties_table.php
│   │   ├── 2026_05_27_020002_add_counterparty_id_to_transactions.php
│   │   └── 2026_08_30_000002_mark_the_counterparty_name_the_app_invented_as_its_own.php
│   └── Seeders/
│       └── Demo/DemoCounterpartiesSeeder.php
├── Routes/
│   └── web.php
├── Resources/views/
│   ├── components/
│   │   ├── type-chip.blade.php
│   │   ├── cp-card.blade.php
│   │   ├── chain-flow.blade.php
│   │   ├── frame.blade.php
│   │   ├── filter-chips.blade.php
│   │   ├── iban-row.blade.php
│   │   ├── privacy-banner.blade.php
│   │   └── self-stub.blade.php
│   └── livewire/
│       ├── counterparty-index.blade.php
│       ├── counterparty-profile.blade.php
│       ├── counterparty-triage.blade.php
│       └── profile-tabs/
│           ├── bank.blade.php
│           ├── government.blade.php
│           ├── merchant.blade.php
│           ├── personal.blade.php
│           ├── self.blade.php
│           ├── unknown.blade.php
│           └── _recent-activity.blade.php
├── Providers/
│   └── CounterpartiesServiceProvider.php
└── tests/
    ├── Unit/
    │   ├── CounterpartyResolverTest.php
    │   ├── CounterpartyTypeFilterTest.php
    │   ├── PrivacyDefaultsTest.php
    │   └── SlugCollisionTest.php
    └── Feature/
```

## Public API

- **Contracts/**
  - `CounterpartyResolver::resolve(CanonicalTransaction $tx, User $user)`
    → `CounterpartyResolutionDto|null`. Returns `null` only for
    pathological rows (no name, no IBAN, no description).
- **Pipeline/**
  - `ResolvesCounterparties::run(CanonicalTransaction $tx, User $user)`
    → `CanonicalTransaction`. The pipeline-stage contract, consumed by
    `ImportPipeline`, `PromoteStagingToDomain` and `RecordManualTransaction`
    — every writer of a canonical row runs it.
- **DTOs/**
  - `CounterpartyResolutionDto` — `(counterpartyId, slug, type)`.
    `counterpartyId === null` iff `type === 'self_account'`.
- **Enums/**
  - `CounterpartyType` — the six values the `type` column stores. The
    UI-side filter vocabulary is a separate enum; see below.
- **Events/**
  - `CounterpartyResolved` — `(counterpartyId, userId, type)`.
- **Queries/**
  - `CounterpartyIndexQuery::forUser($user, CounterpartyTypeFilter)`
    → `Collection<int, CounterpartyIndexRow>`. The filter defaults to
    `All`, which applies no `type` predicate.
  - `CounterpartyIndexQuery::countsByType($user)`
    → `array<string, int>` keyed by `CounterpartyTypeFilter` value, so
    `self` rather than `self_account`, plus the synthetic `all` total.
  - `CounterpartyIndexRow` — the index row DTO. Alongside the queried
    fields it exposes `href`, `total12mFormatted` and
    `avgPerMonthFormatted`, derived once in the constructor so the three
    per-row loops in the view read them rather than rebuild them.
  - `CounterpartyProfileQuery::bySlug($user, $slug)`
    → `CounterpartyProfileDto|null`.
  - `CounterpartyProfileQuery::categoryBreakdown($cp)` /
    `::taxYearBreakdown($cp)` → `Collection<int, stdClass>`. Both go
    through `convertedBuckets()`, which converts one currency's whole
    bucket at a time through `CrossCurrencyTotal::distribute()` — per
    row it rounded the rows apart from the hero figure they sit under —
    and hands each row a `currency` and an `unconverted` list of the
    codes it could not price, which the three tab partials render.
  - `CounterpartyProfileDto::$isBankFee` — whether the profile body
    renders the fee panel or the institution one.
  - `CounterpartyProfileDto::isPartial()` / `unconvertedList()` — the
    codes the twelve-month hero total leaves out for want of a rate.
    The index has said this since it was written; the profile it links
    to computed the same list and rendered none of it.
  - `CounterpartyTriageQueue::forUser($user, $queueFirstId)`
    → `list<Counterparty>`. Excludes rows the reader marked ignored,
    as does `unknownCountForUser()` behind the sidebar badge.

## Internal services

- `Internal/Actions/LabelCounterparty` — the single write seam for the
  three decisions triage makes. Owns the slug re-derivation a rename
  needs, the `EntityMutated` announcement that gets the write to the
  reader's other device, and the ignore predicate the triage queue
  reads back. Called by `CounterpartyTriage`; the predicate is static
  so `CounterpartyTriageQueue` shares it.
- `Internal/Enums/CounterpartySubcategory` — narrows a `type` the chain
  writes for more than one thing. `Fee` is the only case: it separates a
  bank-fee row from the institution rows step 2 also lands on
  `type='bank'`. `CounterpartyProfileDto` carries the answer as a bool
  rather than this token, because a Public DTO may not declare an
  Internal type.
- `Internal/Enums/CounterpartyMetadataKey` — the `metadata` keys
  something reads back, as opposed to the opaque provenance the resolver
  only writes. `Ignored` for the triage-queue exclusion, `Subcategory`
  for the above. `column()` gives the JSON path a query builder
  addresses the key by, so the SQL predicate and the in-PHP read cannot
  drift.
- `Public/Support/CounterpartyDefaultName` — the one place that asks
  whether a stored `display_name` is the reader's own wording or the
  app's, and re-resolves the app's for whoever is reading. The token
  lives under `metadata.default_name`; the seam and the reason for it are
  in [the resolution chain](resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name).
- `Internal/Resolver/CounterpartySlugResolver` — `resolveUnique()` and
  the frozen slugifier behind it. The optional `$ownedBy` argument is
  the row a rename is moving: an import has no row yet and asks by
  name, a rename asks by id.
- `Internal/Enums/CounterpartyTypeFilter` — the chip-row and `?type=`
  vocabulary, and the one place it maps onto the column's. It stays
  Internal because no other module filters this index.
- `Internal/Resolver/CounterpartyResolverService` — the 7-step
  precedence chain. Cross-user posture: every raw query carries an
  explicit `where('user_id', $user->id)`; the `BelongsToUser` global
  scope is only a secondary guard (silent under queue / job / console
  contexts where the resolver typically runs).
- `Internal/Pipeline/ResolveCounterpartyStage` — pipeline glue. Calls
  the resolver, stamps `counterpartyId` via `withCounterpartyId()`,
  no-ops on `null` or `self_account` DTOs. Emits no events of its own.
- `Internal/Jobs/CounterpartyGarbageCollectorJob` — daily per-user
  prune. `ShouldQueue` + `ShouldBeUniqueUntilProcessing` keyed on
  `userId`. Three tries, exponential backoff `[60, 300, 900]`. Lock
  store: `LockStore::forUniqueJobs()` (same as `Chains` and
  `EmailScan`).
- `Internal/Http/Livewire/CounterpartyIndex` — `/counterparties`.
  Per-type chip filter + search; persists view preference via
  `user_preferences.counterparty_index_view`.
- `Internal/Http/Livewire/CounterpartyProfile` —
  `/counterparties/{slug}`. Resolves the row by `(user_id, slug)` via
  the query; per-type Blade partial drives the body.
- `Internal/Http/Livewire/CounterpartyTriage` —
  `/counterparties/triage`. Iterates `type='unknown'` rows; offers a
  resolver-suggested type the user can accept. Every write it makes
  goes through `LabelCounterparty`. `$draftName` / `$draftType` are the
  manual-label bindings and `$drafts` keeps one pair per counterparty
  id, so moving through the queue does not throw typed input away —
  [what the reader typed is
  theirs](triage-suggestions.md#what-the-reader-typed-is-theirs).

## Models + migrations

- `Models/Counterparty` — maps to `counterparties`. Uses
  `BelongsToUser`. Per-type cast for `metadata` (JSON). The
  `type` column is enforced by paired BEFORE INSERT / BEFORE UPDATE OF
  `type` triggers; a typo in application code fails loud at the DB.

Migrations:

- `2026_05_27_020001_create_counterparties_table.php` — initial create.
  Columns: `id`, `user_id` (FK with cascade delete from user side
  only), `type`, `slug` (128), `display_name`, `iban` (64, nullable),
  `merchant_name` (nullable), `metadata` (JSON, nullable), timestamps.
  UNIQUE `(user_id, slug)` powers the per-user slug-collision suffix
  walk. Index `(user_id, type)` powers the index page's per-type
  filters.
- `2026_05_27_020002_add_counterparty_id_to_transactions.php` — adds
  the nullable `transactions.counterparty_id` column. Deliberate
  omission: no `constrained('counterparties')` — the FK is from the
  user side only. The GC job is responsible for NULLing the column
  before deleting orphans. Index `(user_id, counterparty_id)` is the
  profile-page hot-path.

## Provider wiring

`CounterpartiesServiceProvider::register()`:

- Binds `CounterpartyResolver` → `CounterpartyResolverService` as a
  singleton so cross-module consumers share one instance per request
  / job.
- Binds `ResolvesCounterparties` → `ResolveCounterpartyStage` as a
  singleton so `ImportPipeline` depends on the Public contract, not
  the Internal class.

`CounterpartiesServiceProvider::boot()`:

- Loads migrations unconditionally.
- Loads routes + views guarded by file-exists / dir-exists checks.
- Registers the `counterparties` Blade component namespace so the
  eight anonymous components (`type-chip`, `cp-card`, `chain-flow`,
  `frame`, `filter-chips`, `iban-row`, `privacy-banner`, `self-stub`)
  are addressable as `<x-counterparties::type-chip />` etc. across
  every other module's views.
- Registers the three Livewire components under the
  `counterparties.*` namespace.
