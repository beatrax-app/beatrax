# ADR 0005 — SQLite with WAL journal mode as the canonical store

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax runs on one machine at a time. v1.0 is single-user; v2.0 adds
a second-user partner-sharing mode that still runs against one shared
machine. Concurrent write load is bounded by one human's import
cadence — a handful of statements per month, plus background email
scans every fifteen minutes.

A real database server (PostgreSQL, MySQL) would have brought:

- A separate process to manage, run, back up, and upgrade across
  beatrax versions.
- A network port to firewall.
- A connection-pool layer for an app that opens, at most, three
  connections at once (web request, scheduler, queue worker).
- An operational story for users who install via NativePHP and would
  otherwise see "click to install" — adding a Postgres dependency turns
  installation into a multi-component setup wizard.

The minimum value such a server would have added — concurrent writers,
distributed replicas, advanced query optimisation — is invisible to a
single-user dashboard.

SQLite ships with PHP, lives in a single file, requires no service
manager, and has been the Laravel default driver since Laravel 11. The
remaining concern was concurrency: a background queue worker plus a
scheduler plus a web request all want to read while one of them might
be writing. SQLite's default rollback-journal mode serializes readers
and writers; WAL (Write-Ahead Logging) journal mode lets readers
proceed while a writer is active.

## Decision

- **Storage engine:** SQLite 3.45 or newer (whatever Laravel Herd ships
  in dev; whatever the bundled PHP carries in the NativePHP installer).
- **Journal mode:** `WAL`, set once at database creation and re-asserted
  by the `Modules/Core/Internal/Console/diederik:doctor` command on
  every run. `PRAGMA synchronous=NORMAL` is paired with WAL — full
  fsync per write is unnecessary for a single-user store with
  filesystem-level backup.
- **Database location:**
  - In development under Laravel Herd: `database/database.sqlite`.
  - In the NativePHP-bundled desktop app: the per-OS user-data
    directory resolved through `UserDataPathService`
    (`~/Library/Application Support/beatrax/` on macOS,
    `%APPDATA%\beatrax\` on Windows,
    `~/.local/share/beatrax/` on Linux).
- **Subsystems sharing the SQLite file:** the application schema, the
  Laravel `database` queue (jobs, failed_jobs, job_batches), the
  Laravel `database` cache (cache, cache_locks), and Laravel sessions
  (sessions). One file, multiple table sets, one WAL.
- **Backups:** `php artisan db:backup` uses SQLite `VACUUM INTO` to
  produce a consistent copy alongside live writes; the
  [`runbooks/operator-recovery.md`](../runbooks/operator-recovery.md)
  procedure restores from one of those snapshots.

## Consequences

- **Single-writer remains.** WAL allows readers to proceed during a
  write, but only one write transaction may be active at a time. The
  `database` queue driver respects this; long-running jobs commit in
  small transactions; the chain-resolution job uses
  `withoutOverlapping(60)` to serialize itself per-user.
- **Backup story is "copy the file".** Users can also copy the
  `database.sqlite` file directly while the app is closed; with WAL
  active the on-disk file may be out of date relative to the WAL log
  unless `PRAGMA wal_checkpoint(TRUNCATE)` runs first. The supported
  path remains `db:backup`, which handles this internally.
- **Multi-user partner sharing works on one machine.** The shared
  SQLite file is opened by both user sessions on the same machine; the
  WAL handles the concurrency. Sharing across machines is explicitly
  out of scope (see [ADR 0004](0004-local-only-hosting.md)).
- **Queue driver choice is bounded by this decision.** Redis-based
  queues need a separate Redis server, which violates the
  "single-file, no extra process" posture. See
  [ADR 0007 — Database queue driver](0007-database-queue-driver.md).
- **Future migration to Postgres is possible but not planned.** Laravel
  abstracts the driver; the schema uses no SQLite-specific features.
  If a real multi-machine deployment ever lands, the migration is a
  one-config change plus a dump-and-load. The point is that it's not
  needed today, and shipping it pre-emptively would import every
  operational cost listed above.

## Alternatives considered

- **PostgreSQL 16** — rejected for v1.0 / v2.0 scope. Migration path is
  preserved (no SQLite-only schema features used).
- **MySQL / MariaDB** — same rejection plus a less clean Laravel
  default story.
- **SQLite with default rollback-journal mode** — rejected. The
  scheduler-plus-worker-plus-web-request concurrency required WAL to
  avoid "database is locked" errors during background jobs.
- **A separate database file per subsystem** (one for app, one for
  queue, one for cache) — rejected. The cross-file consistency
  story was more complex than the value it added, and Laravel's
  `database` queue driver is happy sharing the application database.

## Related

- [ADR 0007 — Database queue driver](0007-database-queue-driver.md) —
  uses this SQLite file as the queue store.
- [Architecture — Data model](../architecture/data-model.md) — the
  table-by-table layout that lives in this file.
- [`local_development/database.md`](../local_development/database.md) —
  the developer view: file locations, GUIs, schema introspection.
- [`runbooks/operator-recovery.md`](../runbooks/operator-recovery.md) —
  the operational view: backups, restore, stuck-lock recovery.
