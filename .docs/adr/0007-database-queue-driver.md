# ADR 0007 — Database queue driver in the shipped bundle; Horizon is dev-only

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax runs background work: chain-resolution per user, email-scan
backfill, drift-alert re-evaluation, forecast recomputation. Laravel
gives a clean abstraction over queue drivers — `database`, `redis`,
`sqs`, `beanstalkd`, `sync` — and the application code is driver-agnostic.
The choice of driver is an operational decision, and it changes per
deployment surface.

In local development, the user already has Docker
available for the Redis loopback container that v1.0 carved out for
chain resolution; Horizon's dashboard at `/horizon` is a useful debug
surface. In the shipped desktop bundle (see
[ADR 0006](0006-nativephp-desktop-shell.md)), Redis cannot ship — it
would require either a bundled Redis binary inside the NativePHP
distribution (large, platform-specific, operationally painful) or
asking end users to install Redis on their own (a non-starter for the
"double-click to install" target).

The SQLite database (see [ADR 0005](0005-sqlite-wal.md)) is already on
disk, already has WAL mode, and Laravel's `database` queue driver
stores jobs in the same database file alongside the application
schema. For one user and a low job rate (under a thousand jobs per
month at the projected import cadence), SQLite handles the queue
table cleanly.

## Decision

- **Shipped desktop bundle:** `QUEUE_CONNECTION=database`. Jobs land in
  the `jobs`, `failed_jobs`, and `job_batches` tables in the same
  SQLite file as the application schema. The `database` cache driver
  uses the same file for the `cache` and `cache_locks` tables, which
  matters for `withoutOverlapping()` locks on scheduled jobs.
- **Local development:** `QUEUE_CONNECTION` defaults to `database`
  in `.env.example`. The `BEATRAX_RUNTIME=local` developer override may
  switch to `redis` and start Horizon for the dashboard surface; the
  Horizon iframe under `/dev/horizon` is gated on that runtime flag.
- **Horizon is dev-only.** Its service provider's `boot()` early-exits
  unless `BEATRAX_RUNTIME=local` AND `QUEUE_CONNECTION=redis`. The
  [`noHorizonImportsInShippedBuildCode`](#) arch invariant enforces
  that no production code path imports a Horizon symbol; the provider
  itself imports it inside a runtime guard.
- **Workers in the desktop bundle:** the NativePHP shell launches a
  long-running `php artisan queue:work --tries=3 --backoff=60` worker
  inside the bundle's child-process slot. The launchd plists shipped
  in `deploy/launchd/` carry the equivalent setup for local
  development.

## Consequences

- **One operational surface, not two.** Users who install the desktop
  bundle never have to install or configure Redis. The data layer is
  the SQLite file; the queue layer is the SQLite file; backups capture
  both atomically via `VACUUM INTO`.
- **Failed-job visibility without Horizon.** The shipped bundle exposes
  a Livewire failed-jobs page under `/dev/queue` (a developer-mode
  surface, see `Modules/DevMode/`) that reads from the SQLite
  `failed_jobs` table. The `diederik:failed-jobs prune` command keeps
  the table bounded; the surface is sparse compared to Horizon but
  covers the inspect-and-retry path real users need.
- **Job-rate ceiling.** The `database` driver tops out at perhaps a few
  hundred jobs per minute on SQLite, well above the projected ceiling.
  If a future phase pushes the rate higher, the abstraction allows
  swapping in `redis` per deployment without code changes — but the
  Horizon dashboard would only return as a developer-mode surface,
  not as part of the shipped bundle.
- **Lock storage works the same.** `withoutOverlapping(60)` for
  `db:backup` uses the `cache_locks` table; the
  `ShouldBeUniqueUntilProcessing` per-user lock for the chain-resolver
  uses the same backend in shipped mode and switches to Redis under the
  local developer override.

## Alternatives considered

- **Bundle Redis inside the NativePHP distribution.** Rejected: large,
  platform-specific, operationally hostile to non-technical users.
- **Ask users to install Redis themselves.** Rejected: the "double-click
  to install" target dies the moment a setup wizard appears.
- **Ship Horizon-in-Docker as a separate optional add-on.** Rejected:
  same UX problem one layer up, plus a documentation maintenance burden
  for a feature most end users will never enable.
- **Ship Horizon's UI without Horizon's Redis dependency** — not
  technically possible without rewriting Horizon's storage layer.

## Related

- [ADR 0005 — SQLite with WAL](0005-sqlite-wal.md) — the storage backing
  the `database` queue tables.
- [ADR 0006 — NativePHP desktop shell](0006-nativephp-desktop-shell.md)
  — the bundle that cannot carry Redis.
- [`runbooks/operator-recovery.md`](../runbooks/operator-recovery.md) —
  the operational view of the queue, scheduler, and failed-jobs
  surfaces.
- [`local_development/dev-mode.md`](../local_development/dev-mode.md) —
  the Horizon-under-local-dev developer surface.
