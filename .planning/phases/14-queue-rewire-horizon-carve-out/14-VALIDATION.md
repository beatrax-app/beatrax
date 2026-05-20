---
phase: 14
slug: queue-rewire-horizon-carve-out
status: ready
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-20
updated: 2026-05-21
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.7.0 (PHPUnit 11 engine) + pest-plugin-arch ^4.0 |
| **Config file** | `phpunit.xml` (sets `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `DB_CONNECTION=sqlite_testing`) |
| **Quick run command** | `php artisan test --filter=Phase14` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~90 seconds (full suite) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=BoundaryArchTest` (fast — arch invariant must stay green as carve-outs change)
- **After every plan wave:** Run `php artisan test` + `composer analyse` (Larastan L10 strict) + `composer format:check`
- **Before `/gsd:verify-work`:** Full suite green + SC2 concurrency test green + `composer install --no-dev --dry-run` verified Horizon/Redis-free
- **Max feedback latency:** ~90 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 14-01-01 | 01 | 1 | PKG-03 | T-14-01-02 | `cache_locks` table generated from framework stub (no column drift → no fail-open lock) | migration | `php artisan migrate --force` + `db:table` checks | ❌ W0 | ⬜ pending |
| 14-01-02 | 01 | 1 | PKG-03 | T-14-01-03 | `locks_store` defaults to `database`; Redis store inert in shipped build | config | `php -r` config assertion | ❌ W0 | ⬜ pending |
| 14-01-03 | 01 | 1 | PKG-03 | T-14-01-01 | `app.dev_mode` fail-closed `false` via `(bool)` cast | config | `php -r` config assertion + grep `.env.example` | ❌ W0 | ⬜ pending |
| 14-02-01 | 02 | 2 | PKG-03 | T-14-02-03 | `Cache`/`config()` confined to `LockStore` | config + unit | grep `config/queue.php` + LockStore presence | ❌ W0 | ⬜ pending |
| 14-02-02 | 02 | 2 | PKG-03 | T-14-02-03 | `uniqueVia()` resolves the configured store; facade carve-out is one file | unit + arch | `php artisan test --filter=LockStore` + `--filter=BoundaryArchTest` | ❌ W0 | ⬜ pending |
| 14-02-03 | 02 | 2 | PKG-03 | T-14-02-01 / T-14-02-02 | `database` lock rejects duplicate dispatch; no duplicate `chain_resolution_runs` row | feature (in-process race) | `php artisan test --filter=DatabaseQueueConcurrency` | ❌ W0 | ⬜ pending |
| 14-03-01 | 03 | 3 | PKG-03 | T-14-03-01 / T-14-03-02 | `/horizon` early-exit + `class_exists()` autoload guard | feature | `php -l` + `php artisan route:list` | ❌ W0 | ⬜ pending |
| 14-03-02 | 03 | 3 | PKG-03 | T-14-03-SC | Horizon/Predis → `require-dev`; `--no-dev` tree Redis-free | shell/integration | `php -r` composer.json assertion + `composer install --no-dev --dry-run` | ❌ W0 | ⬜ pending |
| 14-03-03 | 03 | 3 | PKG-03 | T-14-03-04 | No `Laravel\Horizon\*` import outside allow-listed provider | arch + feature | `php artisan test --filter=BoundaryArchTest` + `--filter=HorizonGating` + `--filter=ShippedDependencyTree` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

All four success criteria need new tests — no existing test covers the `database` lock store, Horizon gating, or the dependency tree. Each plan creates its own Wave 0 scaffolding inside the plan tasks:

- [ ] **Plan 01:** 3 framework migrations (`create_jobs_table`, `create_job_batches_table`, `create_cache_table`) — prerequisite for ANY `database`-driver test (RESEARCH Pitfall 1)
- [ ] **Plan 02:** `Modules/Core/tests/Unit/LockStoreTest.php` — SC1; asserts `uniqueVia()` resolves the *configured* store (`database` vs `redis`), not just "a Repository"
- [ ] **Plan 02:** `Modules/Chains/tests/Feature/DatabaseQueueConcurrencyTest.php` — SC2; in-process race sim against the `database` lock store, reuses `Modules/Chains/tests/fixtures/scenario-1/` payload
- [ ] **Plan 03:** `tests/Contracts/BoundaryArchTest.php::noHorizonImportsInShippedBuildCode` invariant + updated facade carve-out
- [ ] **Plan 03:** `tests/Feature/HorizonGatingTest.php` — SC3; `app.dev_mode` off → no `/horizon` route
- [ ] **Plan 03:** `tests/Feature/ShippedDependencyTreeTest.php` — SC4; greps `composer.json` `require`/`require-dev` sections

Pest 4.7.0 is already installed — no framework install needed.

---

## Manual-Only Verifications

*All phase behaviors have automated verification.* The SC2 concurrency criterion is satisfied by an in-process deterministic race simulation (CONTEXT D-08) — real OS-level parallel-worker / WAL-contention testing is explicitly deferred to Phase 21 and is not a Phase 14 gate.

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 90s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** ready
