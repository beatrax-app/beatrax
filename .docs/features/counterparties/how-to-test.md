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
    + DELETE ordering (`CounterpartyGarbageCollectorJobTest`).
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
