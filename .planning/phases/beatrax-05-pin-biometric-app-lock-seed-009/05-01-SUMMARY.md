---
phase: 05-pin-biometric-app-lock-seed-009
plan: 01
subsystem: auth
tags: [libsodium, argon2id, secretbox, app-lock, pin, biometric, migrations, tdd]

# Dependency graph
requires:
  - phase: 04-dev-mode-desktop-seed-008
    provides: existing Auth module structure, migration patterns, BackupEncryptor sodium discipline
provides:
  - user_app_lock_configs DB table with PIN hash, KDF salt, wrapped key blobs, lock config columns
  - user_biometric_credentials DB table with per-device credential and wrap-secret columns
  - PinHasher: Argon2id PIN hash/verify (D-09)
  - AppLockKdf: 32-byte wrap-key derivation via Argon2id INTERACTIVE, salt generation
  - AppLockKeyWrap: secretbox wrap/unwrap returning base64(nonce||ciphertext), false on failure
  - LockStateManager: session lock-state R/W with beatrax_locked + beatrax_data_key keys
  - AppLockKeyService: public LOCK-04 boundary; release(?string)/withhold() API
  - 7 Wave 0 RED test stubs for plans 05-02 through 05-06
affects: [05-02, 05-03, 05-04, 05-05, 05-06, phase-14]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Argon2id INTERACTIVE limits (vs MODERATE in BackupEncryptor) for real-time unlock latency
    - base64(nonce||ciphertext) blob for secretbox wrap — self-contained, no separate nonce column
    - LockStateManager as narrow session-state owner; AppLockKeyService as sole cross-module release point
    - Container-resolved DatabaseManager in migrations (no facade, consistent with existing migrations)
    - Wave 0 RED stubs: real failing assertions on class_exists() + method checks, not skips

key-files:
  created:
    - Modules/Auth/Database/Migrations/2026_06_11_000001_create_user_app_lock_configs_table.php
    - Modules/Auth/Database/Migrations/2026_06_11_000002_create_user_biometric_credentials_table.php
    - Modules/Auth/Internal/Lock/PinHasher.php
    - Modules/Auth/Internal/Lock/AppLockKdf.php
    - Modules/Auth/Internal/Lock/AppLockKeyWrap.php
    - Modules/Auth/Internal/Lock/LockStateManager.php
    - Modules/Auth/Public/Services/AppLockKeyService.php
    - Modules/Auth/tests/Unit/PinHasherTest.php
    - Modules/Auth/tests/Unit/AppLockKdfTest.php
    - Modules/Auth/tests/Unit/AppLockKeyWrapTest.php
    - Modules/Auth/tests/Unit/LockStateManagerTest.php
    - Modules/Auth/tests/Unit/AppLockKeyServiceTest.php
    - Modules/Auth/tests/Feature/AppLockMiddlewareTest.php
    - Modules/Auth/tests/Feature/AppLockLivewireTest.php
    - Modules/Auth/tests/Feature/PinVerificationServiceTest.php
    - Modules/Auth/tests/Feature/ForgotPinFlowTest.php
    - Modules/Auth/tests/Feature/BiometricEnrollmentTest.php
    - Modules/Auth/tests/Unit/BiometricDeviceStoreTest.php
    - Modules/Desktop/tests/Feature/LockOnWindowHiddenTest.php
  modified: []

key-decisions:
  - "AppLockKeyService::release() casts session->get() via is_string() guard (PHPStan level 10 requires ?string, Session::get() returns mixed)"
  - "isLocked() defaults to false when SESSION_KEY absent — unset session = not locked (covers no-PIN-set case)"
  - "INTERACTIVE Argon2id limits for PIN KDF (vs MODERATE for backup) — unlock must complete in <1s on a request cycle"
  - "Worktree autoload override: custom vendor/autoload.php patches Composer classmap to point Modules/Auth + Modules/Desktop to worktree paths before main repo"

patterns-established:
  - "Crypto primitives as final classes in Modules\\Auth\\Internal\\Lock namespace, mirroring BackupEncryptor posture"
  - "Wave 0 RED stubs: class_exists() assertions fail RED, go GREEN automatically when later plans create the class"
  - "Migration: Container-resolved DatabaseManager, schema()/db() helpers, no facades"

requirements-completed: [LOCK-01, LOCK-04]

# Metrics
duration: 85min
completed: 2026-06-11
---

# Phase 05 Plan 01: App-Lock Foundation Summary

**Argon2id crypto primitives (PinHasher, AppLockKdf, AppLockKeyWrap), two app-lock DB tables, LockStateManager + AppLockKeyService public boundary, and 11 test files (5 GREEN, 7 RED stubs) forming the Wave 0 foundation for the PIN/biometric app-lock phase**

## Performance

- **Duration:** ~85 min
- **Started:** 2026-06-11T21:05:00Z
- **Completed:** 2026-06-11T22:30:00Z
- **Tasks:** 3
- **Files created:** 19 (2 migrations, 5 production classes, 12 test files)
- **Files modified:** 0

## Accomplishments
- Created `user_app_lock_configs` and `user_biometric_credentials` migrations using Container-resolved DatabaseManager pattern (matching existing Auth migrations); both run cleanly via `migrate:fresh --env=testing`
- Implemented three sodium-based crypto primitives: PinHasher (Argon2id verify string, D-09), AppLockKdf (32-byte INTERACTIVE wrap-key derivation), AppLockKeyWrap (secretbox base64 blob, false-not-garbage on failure per T-05-02); all PHPStan level 10 clean
- Implemented LockStateManager (session lock-state owner with `beatrax_locked` + `beatrax_data_key` keys) and AppLockKeyService (public LOCK-04 release boundary that Phase 14 will consume); PHPStan level 10 clean
- Created 7 Wave 0 RED stub test files covering the 5 remaining plans (05-02 through 05-06); all exit non-zero with real assertions (not skips)
- Established worktree autoload override pattern: custom `vendor/autoload.php` patches Composer classmap + PSR-4 to point `Modules/Auth` and `Modules/Desktop` paths to the worktree, enabling pest/phpstan/artisan to resolve new classes during worktree execution

## Task Commits

Each task was committed atomically:

1. **Task 1: Create migrations** - `3dd8031` (feat)
2. **Task 2: Crypto primitives RED** - `4043981` (test — RED)
3. **Task 2: Crypto primitives GREEN** - `6367162` (feat — GREEN)
4. **Task 3: LockStateManager + AppLockKeyService RED** - `ef0a462` (test — RED)
5. **Task 3: LockStateManager + AppLockKeyService GREEN** - `1cf4a29` (feat — GREEN)
6. **Task 3: Wave 0 RED stubs** - `1adfc12` (test — all RED stubs)

## Files Created/Modified

- `Modules/Auth/Database/Migrations/2026_06_11_000001_create_user_app_lock_configs_table.php` — user_app_lock_configs schema (pin_hash, kdf_salt, wrapped keys, lock config, attempt tracking)
- `Modules/Auth/Database/Migrations/2026_06_11_000002_create_user_biometric_credentials_table.php` — per-device biometric credential schema
- `Modules/Auth/Internal/Lock/PinHasher.php` — Argon2id PIN hash + verify (D-09, T-05-01)
- `Modules/Auth/Internal/Lock/AppLockKdf.php` — 32-byte wrap-key derivation via Argon2id INTERACTIVE; generateSalt() (T-05-03)
- `Modules/Auth/Internal/Lock/AppLockKeyWrap.php` — secretbox wrap/unwrap; unwrap returns false on any failure (T-05-02)
- `Modules/Auth/Internal/Lock/LockStateManager.php` — session lock-state R/W; SESSION_KEY='beatrax_locked', DATA_KEY_SESSION='beatrax_data_key'
- `Modules/Auth/Public/Services/AppLockKeyService.php` — LOCK-04 public release boundary; release(?string)/withhold() API (T-05-04)
- 5 GREEN unit test files (PinHasherTest, AppLockKdfTest, AppLockKeyWrapTest, LockStateManagerTest, AppLockKeyServiceTest)
- 7 RED stub test files (AppLockMiddlewareTest, AppLockLivewireTest, PinVerificationServiceTest, ForgotPinFlowTest, BiometricEnrollmentTest, BiometricDeviceStoreTest, LockOnWindowHiddenTest)

## Decisions Made
- `AppLockKeyService::release()` casts `$session->get()` via `is_string()` guard — `Session::get()` returns `mixed`, PHPStan level 10 requires the return type to be `?string`. The guard is the correct fix (not a @var annotation).
- `isLocked()` defaults to `false` when `SESSION_KEY` is absent — an unset session is not locked, which covers both "no PIN set up yet" and "fresh session boot" cases.
- INTERACTIVE Argon2id limits chosen for PIN KDF (vs MODERATE used in BackupEncryptor for offline backup passphrase) — the unlock flow runs in a web request, so the ~64 MB / 1 op INTERACTIVE tier is the latency-appropriate choice.
- Wave 0 RED stubs use `class_exists()` assertions rather than attempting to instantiate missing classes — this produces clean "Failed asserting that false is true" failures that will go GREEN automatically when later plans create the referenced classes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] PHPStan type fix: AppLockKeyService::release() mixed→?string**
- **Found during:** Task 3 (LockStateManager + AppLockKeyService implementation)
- **Issue:** `$session->get()` returns `mixed` (Illuminate Session contract); PHPStan level 10 flagged the mismatch with the declared `?string` return type
- **Fix:** Added `is_string($key) ? $key : null` guard after retrieving the session value — correct behavior, not a suppression
- **Files modified:** `Modules/Auth/Public/Services/AppLockKeyService.php`
- **Verification:** PHPStan level 10 reports 0 errors; unit tests still pass
- **Committed in:** `1cf4a29` (Task 3 GREEN commit)

---

**Total deviations:** 1 auto-fixed (Rule 2 — missing type correctness)
**Impact on plan:** Necessary for PHPStan level 10 compliance. No scope creep.

## Issues Encountered

**Worktree autoloader discovery (infrastructure, not plan deviation):** The git worktree shares `vendor/` with the main repo via symlink, but Composer's generated classmap hardcodes `$baseDir` = main repo root (symlink dereferencing in PHP). This caused `php artisan migrate:fresh` and `vendor/bin/pest` to resolve all `Modules/Auth/*` files from the main repo, not the worktree. Resolution: created a custom `vendor/autoload.php` wrapper that loads the main repo autoload, then patches the Composer ClassLoader's classmap to override `Modules/Auth` and `Modules/Desktop` entries to point to the worktree directory, plus prepends PSR-4 paths for new classes. Also created wrapper pest/phpstan/pint scripts that use this custom autoload path. This is a worktree-local artifact and is not committed to the branch (only the production/test source files are committed).

## Next Phase Readiness

Ready for Plan 05-02: the middleware and PIN verification service have their RED test stubs in place. Plan 05-02 needs:
- `Modules/Auth/Internal/Http/Middleware/AppLockMiddleware.php` — implement the redirect-to-lock middleware
- `Modules/Auth/Internal/Lock/PinVerificationService.php` — implement PIN verify + backoff
- `Modules/Auth/Internal/Lock/AppLockProvisioner.php` — generate + wrap the data key on enable
- AuthServiceProvider wiring (singletons + Livewire::addPersistentMiddleware)
- `Modules/Auth/Routes/web.php` — `auth.lock` named route

All upstream contracts (interfaces in `<interfaces>` block) are finalized and stable.

## Self-Check: PASSED

All 19 created files verified present on disk. All 6 task commits verified in git log.

---
*Phase: 05-pin-biometric-app-lock-seed-009*
*Completed: 2026-06-11*
