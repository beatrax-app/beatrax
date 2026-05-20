---
phase: 13-app-paths
plan: 01
subsystem: Core / path abstraction
tags: [nativephp-readiness, path-resolution, arch-test, di-refactor]
requires: []
provides:
  - UserDataPathService static + instance path-resolution surface
  - UserDataPathService container singleton binding
  - noStoragePathHardCodedOutsideUserDataPathService arch invariant
  - bin/check-paths.sh + composer check:paths CI grep gate
  - UserDataPathResolutionTest simulated-NativePHP-env feature scaffold
affects:
  - Plan 02 (migrates every call site to UserDataPathService)
  - Plan 03 (turns the arch test + check:paths green, fills the feature test)
tech-stack:
  added: []
  patterns:
    - static-core + instance-delegate service (config-callable pre-container)
    - grep-style arch invariant it() block
key-files:
  created:
    - Modules/Core/Public/Services/UserDataPathService.php
    - Modules/Core/tests/Unit/UserDataPathServiceTest.php
    - Modules/Core/tests/Feature/UserDataPathResolutionTest.php
    - bin/check-paths.sh
  modified:
    - Modules/Core/Providers/CoreServiceProvider.php
    - tests/Contracts/BoundaryArchTest.php
    - composer.json
decisions:
  - publicPath() abstracted as a service accessor rather than left as an
    unhandled exception, so config/modules.php public_path('modules') has a
    clean replacement; public_path is therefore NOT a banned helper
  - core.backups_directory cleanup deferred to Plan 02 (D-04) to keep this
    plan a clean additive slice
metrics:
  duration: ~25m
  completed: 2026-05-20
  tasks: 3
  files: 7
---

# Phase 13 Plan 01: UserDataPathService Summary

`UserDataPathService` — the single class through which every filesystem path
resolves — built as a static-core + instance-delegate service, bound as a
container singleton, with the Wave-0 arch invariant, CI grep gate, and
simulated-NativePHP feature-test scaffold the rest of Phase 13 builds on.

## What Was Built

**Task 1 — UserDataPathService (TDD).** `final class` in
`Modules\Core\Public\Services` with `declare(strict_types=1)`. A private
static resolution core (`projectRoot()` = the one sanctioned `base_path()`
call; `storageRoot()` reads `getenv('NATIVEPHP_STORAGE_PATH')` with a
project-root fallback) feeds the public static accessors callable
pre-container from `config/*.php`: `databaseFile()`, `storageBase()`,
`appPath()`, `backupsPath()`, `secretsPath()`, `frameworkPath()`,
`modulesPath()`, `migrationsPath()`, `publicPath()`. Instance accessors
(`databasePath()`, `storagePath()`, `backups()`, `secrets()`, `framework()`,
`appRelative()`) one-line delegate to the statics for DI consumers. All joins
use `DIRECTORY_SEPARATOR`; `appPath()` rejects `..` path-traversal segments
with `InvalidArgumentException` (threat T-13-01). Larastan L10 strict clean.

**Task 2 — Singleton binding (TDD).** `CoreServiceProvider::register()` binds
`UserDataPathService` as a dependency-free, stateless singleton alongside the
existing `SystemClock` / `SystemAlertQuery` bindings. The unit test proves
both `getenv()` branches, the singleton identity, and the A2 Herd-parity
guard (`backupsPath()` byte-identical to `base_path('storage/app/backups')`
when the env var is unset). 10 tests / 28 assertions green.

**Task 3 — Wave-0 enforcement scaffolding.** Appended the
`noStoragePathHardCodedOutsideUserDataPathService` arch block to
`BoundaryArchTest` (greps Modules/app/config, allow-lists the service,
exempts test + migration dirs and `.blade.php` literals); created the
`UserDataPathResolutionTest` feature-test scaffold (per-test temp dir,
`putenv()` env control, SQLite-parent-dir creation, teardown cascade, one
`todo()` placeholder for Plan 03); created `bin/check-paths.sh` (executable)
plus the `composer check:paths` script.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree missing dependencies + bootstrap files**
- **Found during:** Task 1 RED test run
- **Issue:** The parallel-executor git worktree had no `vendor/`, no `.env`,
  and no `database/database.sqlite`, so `php artisan` / `pest` could not boot.
- **Fix:** Copied the main repo's `vendor/` into the worktree (real copy, not
  a symlink, so the optimized classmap is worktree-local and discovers the
  new service), ran `composer dump-autoload`, copied `.env`, and created an
  empty `database/database.sqlite`. All three are git-ignored — none are
  staged or committed; they are local execution infrastructure only.
- **Files modified:** none tracked (vendor/, .env, database/database.sqlite
  are all in `.gitignore`)
- **Commit:** n/a (no tracked files changed)

## Expected RED State (correct for this wave)

Per the plan's `<verification>` block, two artifacts are intentionally RED
because no call sites are migrated yet — Plan 02 migrates them, Plan 03
verifies they turn green:

- `noStoragePathHardCodedOutsideUserDataPathService` arch test — FAILS
  (offenders: `EmlBlobStore`, `EmitOAuthReauthRequiredAlert`,
  `config/database.php`).
- `composer check:paths` — FAILS, listing the same offenders plus two
  comment-only matches in `CoreServiceProvider` / `RestoreDatabaseCommand`
  (the shell gate does not strip comments; the arch test does — this is the
  documented acceptable redundancy, the arch test is authoritative).

## Verification Results

- `UserDataPathService` unit test: 10 passed / 28 assertions
- `UserDataPathResolutionTest`: discoverable, runs as 1 todo (scaffold valid)
- `phpstan analyse Modules/Core/Public/Services/UserDataPathService.php`:
  no errors (Larastan L10 strict)
- `pint --test` on all 5 changed/created PHP files: passed
- `bash -n bin/check-paths.sh`: no syntax errors; `test -x` confirms executable
- `composer validate`: composer.json valid

## Known Stubs

`UserDataPathResolutionTest.php` carries one intentional `todo()` placeholder
(`it('resolves paths under a simulated NativePHP storage root')`). This is an
intentional Wave-0 scaffold — Plan 03 fills the real `migrate:fresh` /
`db:backup` / OAuth-secrets assertions once Plan 02 has migrated every call
site. Documented in the plan's Task 3 `<action>` as expected.

## Threat Surface

No new security surface beyond the plan's `<threat_model>`. T-13-01
(path traversal via `appPath()`) is mitigated as planned: `appPath()` throws
`InvalidArgumentException` on any `..` segment, covered by a unit test.

## Commits

- `00a3fab` test(13-01): add failing unit test for UserDataPathService
- `04ecb5a` feat(13-01): implement UserDataPathService path-resolution service
- `85647ca` test(13-01): add failing singleton-binding test for UserDataPathService
- `92bb3ea` feat(13-01): bind UserDataPathService as a container singleton
- `f751ba5` chore(13-01): add Wave-0 path-invariant enforcement scaffolding

## TDD Gate Compliance

Tasks 1 and 2 followed the RED → GREEN cycle with explicit failing-test
commits before implementation:
- Task 1: `00a3fab` (RED, test) → `04ecb5a` (GREEN, feat)
- Task 2: `85647ca` (RED, test) → `92bb3ea` (GREEN, feat)

No REFACTOR commits were needed.

## Self-Check: PASSED

- Modules/Core/Public/Services/UserDataPathService.php — FOUND
- Modules/Core/tests/Unit/UserDataPathServiceTest.php — FOUND
- Modules/Core/tests/Feature/UserDataPathResolutionTest.php — FOUND
- bin/check-paths.sh — FOUND (executable)
- Commits 00a3fab, 04ecb5a, 85647ca, 92bb3ea, f751ba5 — all FOUND in git log
