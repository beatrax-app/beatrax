# Phase 14: Queue Rewire + Horizon Carve-out - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-20
**Phase:** 14-Queue Rewire + Horizon Carve-out
**Areas discussed:** Dev-mode signal, Horizon dependency, Concurrent test fidelity, uniqueVia migration, cache.php publishing, Dev queue connection, Worker daemon scope

---

## Dev-Mode Signal

| Option | Description | Selected |
|--------|-------------|----------|
| DIEDERIK_RUNTIME===herd | Reuse Phase 13's orthogonal dev-gating env | |
| Standalone DIEDERIK_DEV_MODE | A dedicated boolean env var, independent | ✓ |
| Derive from APP_ENV | dev_mode = (APP_ENV === 'local') | |

**User's choice:** Standalone DIEDERIK_DEV_MODE
**Notes:** Follow-up — `DIEDERIK_RUNTIME` is retired; `DIEDERIK_DEV_MODE`
supersedes it as the single dev-feature gate. Roadmap references to
`DIEDERIK_RUNTIME=herd` are reinterpreted as `DIEDERIK_DEV_MODE=true`.

## Horizon Dependency Placement

| Option | Description | Selected |
|--------|-------------|----------|
| Horizon to require-dev too | Both horizon + predis in require-dev; zero Horizon/Redis tree shipped | ✓ |
| Horizon stays in require | Only predis moves; Horizon ships but boot-gated | |

**User's choice:** Horizon to require-dev too
**Notes:** Follow-up on the provider — `HorizonServiceProvider.php` stays in
place, registered via a `class_exists()` guard in `bootstrap/providers.php`;
the `noHorizonImportsInShippedBuildCode` arch test allow-lists that one file
(mirrors the existing Cache-facade carve-out).

## Concurrent Test Fidelity

| Option | Description | Selected |
|--------|-------------|----------|
| Real parallel processes | Spawn multiple OS worker processes on shared SQLite | |
| In-process race simulation | Single process; assert lock store rejects the duplicate | ✓ |
| Both layers | In-process + one slower real-process test | |

**User's choice:** In-process race simulation
**Notes:** Deterministic and stable; real WAL-contention testing deferred to
Phase 21's multi-user cohort.

## uniqueVia Migration

| Option | Description | Selected |
|--------|-------------|----------|
| Direct config read in each job | ~10 near-identical edits | |
| Shared helper/trait | One change point for config('cache.locks_store') | ✓ |
| You decide | Defer to planning | |

**User's choice:** Shared helper/trait
**Notes:** The shared file inherits the existing Cache-facade carve-out
constraint; BoundaryArchTest exemption consolidates onto it.

## config/cache.php Publishing

| Option | Description | Selected |
|--------|-------------|----------|
| Publish config/cache.php | Explicit, greppable locks_store key in repo | ✓ |
| Env-only, no published file | Stay on framework defaults | |

**User's choice:** Publish config/cache.php
**Notes:** `locks_store => env('CACHE_LOCK_STORE', 'database')`.

## Dev Queue Connection

| Option | Description | Selected |
|--------|-------------|----------|
| Dev stays on redis | Herd: redis + Horizon workers; shipped: database | ✓ |
| Dev also uses database | Uniform driver; Horizon dashboard-only | |

**User's choice:** Dev stays on redis
**Notes:** Matches roadmap SC1 ("'redis' in dev mode"); database path still
exercised by CI + the SC2 test.

## Worker Daemon Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Config + dep + test only | Worker spawning is Phase 15 | ✓ |
| Phase 14 owns worker wiring | Phase 14 delivers the launchd/daemon mechanism | |

**User's choice:** Config + dep + test only
**Notes:** Shipped-build `queue:work` daemon belongs to Phase 15's desktop
shell.

---

## Claude's Discretion

- Exact shape of the shared lock-store trait/helper.
- Internal structure of the `noHorizonImportsInShippedBuildCode` arch test.
- Exact `config/cache.php` contents beyond the explicit `locks_store` key.
- How the conditional `class_exists()` guard is expressed in
  `bootstrap/providers.php`.

## Deferred Ideas

- Shipped-build worker daemon → Phase 15 (desktop shell).
- Real OS-level concurrency / WAL-contention testing → Phase 21 (beta cohort).
- `laravel/pulse` (TELE-03) → v2.1 candidate.
