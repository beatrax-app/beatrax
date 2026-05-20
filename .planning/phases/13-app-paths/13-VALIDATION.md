---
phase: 13
slug: app-paths
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-20
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.x (on PHPUnit 11) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=UserDataPath` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~60 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=UserDataPath`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 13-01 Task 1 — Create UserDataPathService | 13-01 | 1 | PKG-01 | unit (tdd) | `php artisan test --filter=UserDataPathService` | ❌ Wave 0 — `Modules/Core/Public/Services/UserDataPathService.php` + `Modules/Core/tests/Unit/UserDataPathServiceTest.php` created in this plan | ⬜ pending |
| 13-01 Task 2 — Singleton binding + both-branch unit test | 13-01 | 1 | PKG-01 | unit (tdd) | `php artisan test --filter=UserDataPathService` | ❌ Wave 0 — `Modules/Core/tests/Unit/UserDataPathServiceTest.php` created in this plan | ⬜ pending |
| 13-01 Task 3 — Wave-0 enforcement scaffolding | 13-01 | 1 | PKG-01 | arch + feature + shell | `php artisan test --filter=UserDataPathResolution && bash -n bin/check-paths.sh && composer run-script check:paths --no-interaction; true` | ❌ Wave 0 — `tests/Contracts/BoundaryArchTest.php` block, `Modules/Core/tests/Feature/UserDataPathResolutionTest.php`, `bin/check-paths.sh` created in this plan | ⬜ pending |
| 13-02 Task 1 — Migrate 3 config files to static accessors | 13-02 | 2 | PKG-01 | feature (regression) | `php artisan config:clear && php artisan about && php artisan test --filter=UserDataPath` | ✅ existing infra (`UserDataPathServiceTest`, `BoundaryArchTest`) | ⬜ pending |
| 13-02 Task 2 — D-04 Core backup/restore consumers + binding cleanup | 13-02 | 2 | PKG-01 | feature (regression) | `php artisan test Modules/Core/tests --filter="Backup\|Restore"` | ✅ existing — `Modules/Core/tests` Backup/Restore suite | ⬜ pending |
| 13-02 Task 3 — Migrate EmailScan + Auth call sites | 13-02 | 2 | PKG-01 | feature (regression) | `php artisan test Modules/EmailScan/tests --filter="EmlBlob\|OAuthClientWizard\|OAuthReauth" && php artisan test Modules/Auth/tests --filter="LegacyEmailOauth\|Migration"` | ✅ existing — EmailScan + Auth module suites | ⬜ pending |
| 13-03 Task 1 — Fill in simulated-NativePHP-env feature test | 13-03 | 3 | PKG-01 | feature (tdd) | `php artisan config:clear && php artisan test --filter=UserDataPathResolution` | ✅ scaffold from 13-01 Task 3 — `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` | ⬜ pending |
| 13-03 Task 2 — Verify arch invariant + CI grep gate green | 13-03 | 3 | PKG-01 | arch + shell | `php artisan test --filter=noStoragePathHardCodedOutsideUserDataPathService && composer check:paths --no-interaction && php artisan test` | ✅ scaffold from 13-01 Task 3 — `tests/Contracts/BoundaryArchTest.php`, `bin/check-paths.sh` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Phase 13 has no pre-existing test infrastructure for path resolution; the
following files are created in **13-01 (Wave 1)** before any consuming task
runs. 13-02 and 13-03 depend on them. Confirmed against the executable plans:

- [ ] `Modules/Core/Public/Services/UserDataPathService.php` — the service under test (13-01 Task 1)
- [ ] `Modules/Core/tests/Unit/UserDataPathServiceTest.php` — both-branch unit coverage incl. the A2 Herd-parity regression guard (13-01 Tasks 1-2)
- [ ] `tests/Contracts/BoundaryArchTest.php` — append `noStoragePathHardCodedOutsideUserDataPathService` `it()` block (13-01 Task 3; deliberately RED until 13-02 migrates all call sites — turned green in 13-03 Task 2)
- [ ] `Modules/Core/tests/Feature/UserDataPathResolutionTest.php` — simulated-NativePHP-env feature test; scaffold in 13-01 Task 3, real assertions filled in 13-03 Task 1
- [ ] `bin/check-paths.sh` + `composer.json` `check:paths` script — standalone CI grep gate (13-01 Task 3)
- [ ] No framework install needed — Pest 4 + `pest-plugin-arch` already present

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Path resolution inside an actual NativePHP-bundled build | PKG-01 | NativePHP integration does not exist until Phase 15; Phase 13 can only validate against a simulated `NATIVEPHP_STORAGE_PATH` env var | Deferred to Phase 15 desktop-shell verification |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending — `wave_0_complete: false` until 13-01 (Wave 1) executes
</content>
