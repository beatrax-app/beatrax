<!--
  Template for `.docs/features/<module-slug>/specs.md`. Fill in
  per-module. This is the behavioural-contract file — what the module
  guarantees, what edge cases it handles, and how it collaborates
  across module boundaries.
-->

# `<ModuleName>` — specs

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
explicitly here (e.g. `noResolverWritesTransactions`,
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
