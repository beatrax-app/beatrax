# ADR 0001 — Modular architecture via nwidart/laravel-modules

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax grew across eleven shipped phases into a system with eighteen bounded
domains — Auth, Categorization, Chains, Community, Core, Counterparties,
Desktop, DevMode, DriftAlerts, EmailScan, Forecasting, Import, Ingestion,
Ledger, Onboarding, Receipts, Recurring, Transfers. The original Laravel
single-namespace layout was tried briefly in the earliest spikes and
discarded after the first three modules. With more than a handful of domains,
two failure modes appeared every week:

- **Implicit coupling.** Code in one feature reached into another's
  Eloquent models, query builders, or job classes. A change in the receiver
  silently broke the caller, and the test suite caught it only because every
  domain shared the same database.
- **Diluted ownership.** When the directory layout was `app/Models`,
  `app/Services`, `app/Jobs`, nothing in the filesystem said which module
  owned which class. Discussions about "where should this go" recurred every
  week.

The alternatives weighed: a single namespace with linting rules (rejected —
the rules would have to inspect every `use` statement and accumulate
exception lists faster than the boundary itself was drawn); Domain-Driven
Design "Contexts" implemented by hand (rejected — too much custom
infrastructure for a single-developer project); and `nwidart/laravel-modules`
(accepted — it ships the directory layout, the service-provider auto-loading,
the per-module migrations and seeders, and the convention everyone in the
Laravel community already recognizes).

## Decision

Every domain lives under `Modules/<Name>/` with a strict `Public/` vs
`Internal/` split:

- `Modules/<Name>/Public/` — service-class contracts, DTOs, events, and
  facades that other modules MAY import. The public surface is the
  module's API.
- `Modules/<Name>/Internal/` — actions, jobs, listeners, parsers, pipeline
  stages, resolvers, and HTTP controllers. Only the owning module may
  import anything from here.
- `Modules/<Name>/Models/` — Eloquent models. Other modules MAY use them
  directly (instantiation, `Model::find()`, relationships, query-builder
  via `$model->newQuery()`); see [ADR 0002](0002-di-only-rule.md) for the
  facade-vs-Eloquent boundary.
- `Modules/<Name>/Database/` — per-module migrations, seeders, and
  factories.
- `Modules/<Name>/Routes/`, `Modules/<Name>/Resources/views/`,
  `Modules/<Name>/tests/` — standard Laravel locations, scoped per module.

Cross-module access goes through the importing module's `Public/` surface
or through Laravel events. A module that needs a behaviour another module
owns declares a contract in its own `Public/Contracts/`; the owning module
implements it; the binding is wired in a service provider. The receiver
never imports the implementer's `Internal/` namespace.

## Consequences

- **Enforced by tests, not convention.** `tests/Contracts/BoundaryArchTest.php`
  carries dedicated invariants for cross-module imports, state-column
  mutators, facade usage, and Native-PHP shell access. Twenty-nine arch
  invariants ship at v1.0.0; new modules add their own invariants alongside
  the contract they define. A boundary violation fails the Pest run, which
  fails the PR gate.
- **New modules follow a fixed template.** Adding a module means creating
  `Public/`, `Internal/`, `Models/`, `Database/`, `Routes/`, `Resources/`,
  `tests/`, and a `module.json` and `composer.json` that wire the
  service-provider auto-loader. The features deep-dive template in
  [`.docs/features/_template/`](../features/_template/) mirrors this shape
  so each new module ships its own documentation in the same four-file
  format.
- **Refactoring discipline.** Pulling logic out of a fat controller or job
  into a new service first asks "which module owns this", not "where does
  this fit in `app/Services/`". The answer is sometimes a new module; the
  cost of creating one is low because the directory template is fixed.
- **Migration ordering is per-module.** Laravel sorts migrations by
  timestamp across all modules, so cross-module foreign keys still work,
  but a developer adding a column on a table owned by another module is
  doing something wrong by definition — the migration belongs in the
  owning module's `Database/Migrations/` directory.

## Alternatives considered

- **Plain Laravel namespaces with import-linter rules** — rejected: the
  rule set would have grown unbounded, and no off-the-shelf linter
  understood Laravel's facade indirection.
- **Hand-rolled "Contexts" directory with custom auto-loading** —
  rejected: too much custom infrastructure to maintain alongside a
  single-developer project.
- **Hexagonal / Onion architecture with port + adapter layers** —
  rejected: the ceremony of declaring ports for every collaborator
  swamped the velocity benefit of module-level isolation for a project
  where one person owns every boundary anyway.

## Related

- [ADR 0002 — DI-only rule](0002-di-only-rule.md) — explains why
  collaborators are constructor-injected even within a module.
- [Architecture — Module boundaries](../architecture/module-boundaries.md)
  — names every module, lists the bidirectional contracts they expose,
  and walks through the arch invariants that enforce the split.
