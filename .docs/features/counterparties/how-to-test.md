# `Counterparties` — how to test

Practical recipes for exercising the `Counterparties` module in
isolation.

## Unit tests

- **Location:** `Modules/Counterparties/tests/Unit/`
- **What they test:**
  - The 7-step precedence chain end-to-end against in-memory inputs
    (`CounterpartyResolverTest`).
  - The privacy default for `type='personal'` — IBAN never in slug or
    URL (`PrivacyDefaultsTest`).
  - The slug-collision suffix walk (`SlugCollisionTest`).
- **Common stubs:** the resolver is constructed with a stub
  `ResolvesKnownCounterpartyIban` and a stub `MerchantNameResolver`
  so the test fixes the upstream resolver behaviour and exercises only
  the orchestration. Inputs are synthetic `CanonicalTransaction`
  instances.

## Feature tests

- **Location:** `Modules/Counterparties/tests/Feature/`
- **What they test:**
  - The pipeline stage end-to-end against a real DB
    (`ResolveCounterpartyStageTest`).
  - The garbage-collector job's two-key preservation + the NULL-out
    - DELETE ordering (`CounterpartyGarbageCollectorJobTest`).
  - The three Livewire pages (`CounterpartyIndexTest`,
    `CounterpartyProfileTest`, `CounterpartyTriageTest`).
  - The per-user view preference (`UserPreferencesCounterpartyViewTest`).
- **Setup:** every test uses `RefreshDatabase`. Tests that resolve
  against an institution IBAN seed the
  `known_counterparty_ibans` table first.

## Contract / arch invariants

- `tests/Arch/CounterpartiesBoundaryTest.php` — the module-local
  invariants:
  - no class under `Modules\Counterparties\` may import from another
    module's `Internal\` namespace;
  - the resolver runs without `Cache::*` / `Auth::*` facade calls;
  - the personal-IBAN privacy default's source string composition is
    intact.
- The repo-wide `noReachIntoCounterpartiesInternal` invariant —
  forbids any class outside `Modules\Counterparties\` from importing
  `Modules\Counterparties\Internal\*`.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/Counterparties/tests

# Just the resolver chain unit tests
vendor/bin/pest Modules/Counterparties/tests/Unit/CounterpartyResolverTest.php

# Just the GC job
vendor/bin/pest Modules/Counterparties/tests/Feature/CounterpartyGarbageCollectorJobTest.php

# Stop on first failure for a focused debug session
vendor/bin/pest Modules/Counterparties/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

- **A `personal` counterparty's slug shows an IBAN fragment** — the
  privacy default regressed. Walk
  `tests/Unit/PrivacyDefaultsTest.php` first; the fix is in
  `CounterpartyResolverService::deriveSlugForPersonal()`. Never
  patch in the IBAN as a tiebreaker — collision-suffix walks are the
  sanctioned answer.
- **A merchant landing as `personal`** — the merchant matcher
  (`MerchantNameResolver`) did not produce a hit; the personal-IBAN
  heuristic then fired at step 4. Check the
  `MERCHANT_NAME_MARKERS` list — a merchant whose registered name
  carries `BV`, `B.V.`, `NV`, `LTD`, `GMBH`, `AG`, etc. is excluded
  from the personal heuristic; add the missing marker if a new legal-
  entity suffix is missed.
- **Two distinct merchants collapsing onto one slug** — both produced
  the same `display_name` slug-cased; the collision walk produced
  `bol` and `bol-2` but the test asserts `bol`. Confirm the slug walk
  is the issue (it should produce a stable `bol-2` for the second
  match) and not a missing distinguishing field in `display_name`.
- **The GC job pruned a row the user still uses** — the row had no
  transaction in the last 365 days AND no `merchant_aliases.friendly_name`
  match. Add a per-user alias to anchor the row, or add a recent
  transaction. The retention window is fixed in the job source; the
  invariant is "no recent activity AND no explicit anchor".
- **A counterparty page returns 404 for the owner** — the `slug` in
  the URL does not match any row for the current user. Check the
  `user_id` filter on the underlying query; a recent rename via
  display-name change keeps the OLD slug live until the next import
  re-resolves the row.
- **The triage queue shows a row the resolver should have matched** —
  the resolver returned `type='unknown'` because none of steps 1-6
  fired. Read the row's `description` + `counterparty_name` +
  `counterparty_iban` columns; the most common cause is a missing
  alias for a personal P2P that the personal heuristic did not pick
  up because the name carries one of the `MERCHANT_NAME_MARKERS`.

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

The behavioural contract for the `Counterparties` module.

## Behavioral contracts

- **The resolver walks the 7-step precedence chain in order, first
  match wins.** The order is load-bearing: known-IBAN bridge before
  merchant resolution (a PayPal Luxembourg charge resolves as `bank`
  before the merchant matcher fires); merchant before personal-IBAN
  (a real merchant with a Dutch IBAN never lands as `personal`).
  (`tests/Unit/CounterpartyResolverTest.php`,
  `tests/Feature/ResolveCounterpartyStageTest.php`)
- **A `self_account` resolution short-circuits with `counterpartyId =
  null`.** The user's own account legs do NOT materialise a
  `counterparties` row; the profile page routes back to
  `/accounts/{slug}`. (`tests/Unit/CounterpartyResolverTest.php`)
- **A personal IBAN never appears in a slug or URL.** The privacy
  default for `type='personal'` produces a slug from the kebab-cased
  display name only; the IBAN is preserved on the `iban` column for
  the profile page's IBAN row but is invisible to routing.
  (`tests/Unit/PrivacyDefaultsTest.php`)
- **Slugs are unique per user.** Collisions inside the
  `resolveSlugForUpsert()` walk produce `bol`, `bol-2`, `bol-3`, …
  The DB-layer UNIQUE on `(user_id, slug)` is the second-layer
  guard. (`tests/Unit/SlugCollisionTest.php`)
- **The resolver is idempotent.** Re-resolving the same canonical
  transaction returns the same `counterpartyId`; no second INSERT is
  issued. (`tests/Feature/ResolveCounterpartyStageTest.php`)
- **Every resolution dispatches `CounterpartyResolved` exactly once.**
  v1.0.0 ships zero listeners; the event exists for future
  subscribers.
- **`type='self_account'` is not a `counterparties` row.** No row
  exists for the user's own accounts; queries that group by
  counterparty must handle the NULL `counterparty_id` case
  explicitly. (`tests/Unit/CounterpartyResolverTest.php`)
- **Cross-user reads / writes return 404, not 403.**
  `CounterpartyProfile::mount` raises
  `NotFoundHttpException` when the `(slug, user_id)` lookup misses;
  no detail leaks about whether another user owns the slug.
  (`tests/Feature/CounterpartyProfileTest.php`)
- **The `type` enum is enforced at the DB layer.** Paired BEFORE
  INSERT / BEFORE UPDATE OF `type` triggers reject any value outside
  `merchant|personal|bank|government|self_account|unknown`. An
  application typo fails loud as SQLSTATE 23000.
- **The garbage-collector job is unique per user.**
  `ShouldBeUniqueUntilProcessing` with `uniqueId() = $userId`
  collapses a scheduled tick + an on-demand sweep into one job; the
  lock releases when `handle()` begins so a long-running GC pass
  never blocks a follow-up tick once executing.
  (`tests/Feature/CounterpartyGarbageCollectorJobTest.php`)
- **The garbage-collector preserves rows anchored by either recent
  activity or a merchant alias.** A row survives if any transaction in
  the last 365 days references it OR a `merchant_aliases.friendly_name`
  matches its `merchant_name`. Both keys must be absent for the row to
  be pruned. (`tests/Feature/CounterpartyGarbageCollectorJobTest.php`)
- **Garbage collection never cascade-deletes transactions.** The
  `transactions.counterparty_id` column is NULLed first inside the
  same transaction, then the `counterparties` row is deleted.
  Historical transactions retain their data; only the FK link is
  severed. (`tests/Feature/CounterpartyGarbageCollectorJobTest.php`)
- **The `counterparty_index_view` user preference persists per user.**
  Switching the index view mode writes
  `user_preferences.counterparty_index_view`; a fresh login restores
  the chosen mode. (`tests/Feature/UserPreferencesCounterpartyViewTest.php`)

## Edge cases

- **A row with no IBAN, no description, and no counterparty name** —
  the resolver returns `null`; the stage no-ops; the
  `transactions.counterparty_id` column stays NULL; the row remains
  visible in the triage queue's "unresolved" bucket.
- **A merchant later renamed in the user's alias table** — the
  resolver's step 3 hit produces a different `display_name`; the
  upsert keys on `(user_id, slug)`, so the row's old slug stays put
  and the alias rename surfaces only at the profile page render.
- **A second user installing the bundle on a multi-user device** —
  the per-user uniqueness on `slug` means user A's `bol` and user B's
  `bol` coexist as distinct rows; the `BelongsToUser` global scope
  hides each from the other.
- **A row created by step 7 (`unknown`) that becomes resolvable later
  (e.g. the user added a merchant alias)** — the next import-time
  resolution finds the merchant hit at step 3, upserts the new
  `(user_id, slug)`; the unknown row stays as an orphan and is
  pruned by the next GC pass.
- **A `personal` row whose user later adds the IBAN to a known-
  counterparty mapping** — subsequent imports take the step 2 branch
  and produce a `bank` counterparty; the old `personal` row decays
  via the GC.
- **An import-time resolver exception** — `ResolveCounterpartyStage`
  does NOT catch resolver exceptions; the canonical-transaction
  failure path is the ImportPipeline's responsibility. (The 7-step
  chain is engineered to be exception-free under normal inputs;
  catching here would mask bugs.)
- **A garbage-collector pass while the user is mid-import** — both
  paths use the same `(user_id, slug)` uniqueness; the GC's
  exclusion of recent-activity rows means the just-imported row
  cannot be pruned in the same pass.

## Cross-module collaborators

- **Depends on**
  - [`Core`](../core/how-to-test.md) — `User`, `Clock`, `BelongsToUser`,
    `LockStore`.
  - [`Import`](../import/how-to-test.md) — `ResolvesKnownCounterpartyIban`
    contract (step 2), `MerchantNameResolver` (step 3),
    `CanonicalTransaction` DTO.
  - [`Ledger`](../ledger/how-to-test.md) — the `transactions` table the
    `counterparty_id` column extends; reads via the profile-page
    queries.
- **Depended on by**
  - [`Import`](../import/how-to-test.md) — calls
    `ResolvesCounterparties::run` inside `ImportPipeline.preview`.
  - [`Categorization`](../categorization/how-to-test.md) — triage row
    links to `/counterparties/{slug}`.
  - [`Chains`](../chains/how-to-test.md) — uses
    `ChainSummary` query when rendering the chain-drawer.
  - [`Recurring`](../recurring/how-to-test.md) — links a recurring-series
    to its `counterparty_id`.

## Configuration + feature flags

- The 7-step chain ordering is fixed in the resolver source — it has
  no config knob. A change to the ordering would alter every row's
  resolution and is therefore a deliberate-code-change, not a
  runtime toggle.
- `user_preferences.counterparty_index_view` — per-user index-page
  view-mode preference (read by `CounterpartyIndex` Livewire SFC).
- The garbage-collector retention window (365 days) is fixed in the
  job source.
- No environment flag changes the resolver's behaviour; it runs the
  same way in local dev, the packaged build, and CI.
