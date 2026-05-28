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
