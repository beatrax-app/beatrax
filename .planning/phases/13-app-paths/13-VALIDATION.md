---
phase: 13
slug: app-paths
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-20
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (on PHPUnit 11) |
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

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD — planner fills this table from the executable plans | | | PKG-01 | | | | | | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Planner to confirm during planning.*

- [ ] `tests/Feature/Core/UserDataPathServiceTest.php` — stubs for PKG-01 (path resolution under simulated NativePHP env)
- [ ] `BoundaryArchTest::noStoragePathHardCodedOutsideUserDataPathService` — new invariant extending the existing arch test class

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Path resolution inside an actual NativePHP-bundled build | PKG-01 | NativePHP integration does not exist until Phase 15; Phase 13 can only validate against a simulated `NATIVEPHP_STORAGE_PATH` env var | Deferred to Phase 15 desktop-shell verification |

*If none: "All phase behaviors have automated verification."*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
