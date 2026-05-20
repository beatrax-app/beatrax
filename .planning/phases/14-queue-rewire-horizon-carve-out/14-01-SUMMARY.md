---
phase: 14-queue-rewire-horizon-carve-out
plan: 01
subsystem: queue-cache-foundation
tags: [queue, cache, config, migrations, database-driver]
requires: []
provides:
  - "jobs / job_batches / cache / cache_locks tables on the default SQLite store"
  - "config('cache.locks_store') project-defined key (defaults to 'database')"
  - "config('app.dev_mode') key derived from DIEDERIK_DEV_MODE"
  - ".env.example shipped defaults: QUEUE_CONNECTION=database, CACHE_LOCK_STORE=database, DIEDERIK_DEV_MODE=false"
affects:
  - "Plan 02 (queue rewire) reads config('cache.locks_store') via the shared lock-store helper"
  - "Plan 03 (Horizon carve-out) gates the dashboard on config('app.dev_mode')"
tech-stack:
  added: []
  patterns:
    - "Project-defined config key (locks_store) documented as a non-framework convention"
    - "(bool) cast at the config layer for fail-closed env-derived booleans"
key-files:
  created:
    - database/migrations/2026_05_21_001844_create_jobs_table.php
    - database/migrations/2026_05_21_001844_create_job_batches_table.php
    - database/migrations/2026_05_21_001844_create_cache_table.php
    - config/cache.php
  modified:
    - config/app.php
    - .env.example
decisions:
  - "config/cache.php published from the Laravel 13 framework default; only the strict-types header and the custom locks_store key were added"
  - "locks_store documented in-file as a project convention, not a framework key, so future readers do not hunt for non-existent framework docs"
metrics:
  duration: ~3m
  completed: 2026-05-21
  tasks: 3
  files: 6
---

# Phase 14 Plan 01: Queue + Cache Foundation Summary

Generated the three framework-stub migrations the `database` queue driver and `database` cache lock store require, published `config/cache.php` with a project-defined `locks_store` key, and wired `config('app.dev_mode')` from `DIEDERIK_DEV_MODE` — so `php artisan migrate` now succeeds on the `database` driver path.

## What Was Built

- **Task 1 — Framework migrations.** Ran `php artisan queue:table`, `queue:batches-table`, and `make:cache-table` to generate `create_jobs_table`, `create_job_batches_table`, and `create_cache_table` (the last creates both the `cache` and `cache_locks` tables). Added `declare(strict_types=1);` to each to match the existing `create_failed_jobs_table` migration. `php artisan migrate` applies all three cleanly; `jobs`, `job_batches`, `cache`, and `cache_locks` tables all exist.
- **Task 2 — config/cache.php.** Published the Laravel 13 framework cache config, added the `declare(strict_types=1);` header, and added one project-defined key (`'locks_store' => env('CACHE_LOCK_STORE', 'database')`) as a sibling of `'default'`. A comment block above it states it is a project convention, not a framework key, and describes current behavior only. Both the `database` and `redis` store entries remain defined.
- **Task 3 — config('app.dev_mode') + .env.example.** Added `'dev_mode' => (bool) env('DIEDERIK_DEV_MODE', false)` to `config/app.php`, mirroring the existing `'debug'` `(bool)` cast. Updated `.env.example`: `QUEUE_CONNECTION` flipped from `redis` to `database`, `CACHE_LOCK_STORE=database` added, `DIEDERIK_DEV_MODE=false` added. All `REDIS_*` lines retained for the dev box.

## Verification

| Check | Result |
|-------|--------|
| `php artisan migrate` applies the three new migrations | PASS — `jobs`, `job_batches`, `cache`, `cache_locks` all exist |
| `config('cache.locks_store')` resolves to `'database'` with `CACHE_LOCK_STORE` unset | PASS |
| `config('app.dev_mode')` resolves to strict `false` with `DIEDERIK_DEV_MODE` unset | PASS |
| `cache.stores` defines both `database` and `redis` entries | PASS |
| `composer format:check` (Pint) | PASS |
| `composer analyse` (Larastan L10 strict) | PASS — 0 errors |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree had no `vendor/` directory**
- **Found during:** Task 1
- **Issue:** The fresh worktree shipped no `vendor/` directory, so `php artisan` fatally failed on `require vendor/autoload.php`. No artisan command could run.
- **Fix:** Ran `composer install --no-interaction` against the existing committed `composer.lock` (an install from a pinned lockfile — not a new package add, so outside the package-legitimacy checkpoint exclusion). Also created a local `.env` (copied from `.env.example`), an empty `database/database.sqlite`, and ran `php artisan key:generate` so migrations could execute.
- **Files modified:** None tracked — `vendor/`, `.env`, and `database/database.sqlite` are all gitignored. No commit needed.
- **Commit:** n/a (environment setup only)

### Verification Method Adjustment

The plan's verify snippet for Tasks 2 and 3 used a bare `php -r "echo (require 'config/cache.php')..."`, which fails standalone because `env()` is undefined without the framework bootstrap. Verified instead by bootstrapping the application kernel (`bootstrap/app.php` + `Kernel::bootstrap()`) before reading `config()`. Same assertions, correct context. Not a code deviation.

## Notes

- **`DIEDERIK_RUNTIME` retirement (D-02):** A full codebase grep (`*.php`, `*.json`, `*.env*`, excluding `vendor/`, `node_modules/`, `.planning/`) found **zero** `DIEDERIK_RUNTIME` references. `.env.example` never contained the line, and no runtime code references it. Nothing to remove; D-02 is already satisfied at the file level.
- **Larastan scope:** Running `phpstan analyse config/...` directly flags `env()` as a forbidden global helper, but `config/` is intentionally outside the configured `phpstan.neon` `paths` (only `Modules`, `app`, `bootstrap/app.php` are analysed; `database/migrations/*` is explicitly excluded). `composer analyse` — the canonical gate — passes with 0 errors. Config files are the one sanctioned home for `env()`.

## Self-Check: PASSED

- FOUND: database/migrations/2026_05_21_001844_create_jobs_table.php
- FOUND: database/migrations/2026_05_21_001844_create_job_batches_table.php
- FOUND: database/migrations/2026_05_21_001844_create_cache_table.php
- FOUND: config/cache.php
- FOUND: config/app.php (modified)
- FOUND: .env.example (modified)
- FOUND commit: 7109eb9 (Task 1 — migrations)
- FOUND commit: e83ed84 (Task 2 — config/cache.php)
- FOUND commit: fea6e12 (Task 3 — config/app.php + .env.example)
