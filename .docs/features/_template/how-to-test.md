<!--
  Template for `.docs/features/<module-slug>/how-to-test.md`. Fill in
  per-module. This file is the recipe for exercising the module in
  isolation — useful for a contributor running tests during a focused
  change, or for the maintainer reproducing a regression report.
-->

# `<ModuleName>` — how to test

Practical recipes for running this module's test suite, exercising
its arch invariants, and writing new tests that match the module's
style.

## Unit tests

Where unit tests live and what they cover:

- **Location:** `Modules/<ModuleName>/tests/Unit/`
- **What they test:** isolated services with stub collaborators;
  no database, no HTTP, no event bus.
- **Common stubs:** typical injected collaborators (a `LoggerInterface`
  spy, an `AuthContext` stub returning a fixed user) and how to
  construct them.

## Feature tests

Where feature tests live and what they cover:

- **Location:** `Modules/<ModuleName>/tests/Feature/`
- **What they test:** end-to-end flows that involve the database, the
  HTTP layer, queued jobs, or events.
- **Setup:** typical traits (`RefreshDatabase` plus any module-specific
  factories). Note which seeders the test relies on.

## Contract / arch invariants

If this module owns any `tests/Contracts/` invariants or has its own
`tests/Arch/` directory, list them here:

- `tests/Contracts/<InvariantName>Test.php` — what cross-module rule
  it enforces (e.g. "no writes to `transactions` from this module").
- `Modules/<ModuleName>/tests/Arch/<ArchTest>.php` — what
  module-local invariant it enforces.

Naming the invariants here makes them discoverable. A contributor
about to make a change that would break one of them sees the test
they need to update before they hit the failure.

## How to run the suite for just this module

```sh
# Just this module's tests
vendor/bin/pest Modules/<ModuleName>/tests

# Just the contract invariants this module participates in
vendor/bin/pest --filter '<ModuleName>'

# Run with stop-on-failure for a focused-debug session
vendor/bin/pest Modules/<ModuleName>/tests --stop-on-failure
```

For the full suite (including cross-module arch invariants):

```sh
composer test
```

## Common debugging recipes

Module-specific debugging starting points that don't belong in
`code.md` because they are about running the code, not about its
shape:

- **A failing arch invariant:** which test, where to look for the
  offending file, what the typical fix is.
- **A racing background job:** how to reproduce locally
  (`php artisan queue:work` plus the relevant trigger), how to inspect
  the `jobs` / `failed_jobs` table from `/dev/queue`.
- **A flaky assertion against a specific edge case:** the deterministic
  way to set up the fixture (factory state, seeder, fixture file).

## Behavioural contracts, and the tests that hold them

Each contract below names the test that proves it. The requirement it
serves is the spec's; this section maps that requirement onto the code
and the assertion — see
[10-functional/features/](https://github.com/beatrax-app/spec/blob/main/10-functional/features/).

<!--
  Fill in per-module. Each contract names the test that proves it, so a
  reader can go from "the module guarantees X" to the assertion without
  searching. The requirement behind the contract is the spec's; this
  section maps it onto the code.
-->

The behavioural contract for the module. A reader who is about to
change something here should be able to confirm, by reading this file
plus the linked tests, what the module is supposed to do and what it
must not do.

## Behavioral contracts

Bulleted list of the guarantees the module makes. Each entry is one
sentence, present-tense, and cross-references the Pest test that
proves it:

- The module always does X when Y. (`tests/Feature/XYZTest.php`)
- The module never does Z, even when W. (`tests/Contracts/InvariantTest.php`)
- The module idempotently handles re-running W. (`tests/Contracts/IdempotencyContractTest.php`)

If the module is the sole mutator of a state column or owns a
read-only contract enforced by an arch invariant, name the invariant
explicitly here (e.g. `crossModuleRawTableWrites`,
`RecurringSeriesStateMachine is the sole mutator of recurring_series.state`).

## Edge cases

Catalogue of the not-the-happy-path cases the module handles. Each
entry names the case and the observable behaviour:

- **Empty input** — what the module returns / does.
- **Malformed input** — what error surfaces, how it's logged.
- **Concurrent invocations** — what the locking story is (e.g.
  `ShouldBeUniqueUntilProcessing` per-user, or `withoutOverlapping(N)`).
- **Re-runs** — what idempotency guarantee holds.
- **Partial failure** — what survives, what rolls back.

The edge-case catalogue is the second-most-valuable section of this
file (after Behavioral contracts). A new contributor reading this
section should be able to anticipate every failure mode the module
handles cleanly and recognise any case the module does not yet handle.

## Cross-module collaborators

List every other module this one depends on, and every other module
that depends on it:

- **Depends on** — modules whose `Public/` surface this module imports.
  For each, name the contract / DTO / service used.
- **Depended on by** — modules that import this module's `Public/`
  surface. For each, name what they consume.

A module that imports nothing from another module's `Internal/`
namespace is enforced by the arch invariants
(see [module-boundaries](../../architecture/module-boundaries.md));
this section makes the legitimate cross-module surface explicit.

## Configuration + feature flags

Per-user preference flags, per-environment config keys, or runtime
guards the module respects:

- `users.<preference_column>` — what it toggles.
- `config('<module-key>.option')` — what it controls.
- `BEATRAX_RUNTIME=local` — whether the module behaves differently
  under the developer-mode runtime override (see
  [ADR 0007](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0007-database-queue-driver.md)).
