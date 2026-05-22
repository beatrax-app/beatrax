---
phase: 15
slug: desktop-shell-nativephp-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-22
---

# Phase 15 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (PHPUnit 11) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --parallel` |
| **Full suite command** | `php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse` |
| **Estimated runtime** | ~120 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --parallel`
- **After every plan wave:** Run `php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

> Populated by the planner during plan creation. Each PLAN.md task maps to a row here.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 15-XX-XX | XX | X | PKG-XX | — | N/A | arch/feature | `php artisan test --parallel` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Contracts/BoundaryArchTest.php` — extend with `noNativePhpImportsOutsideDesktopModule` invariant
- [ ] Feature/arch test stubs for PKG-04..PKG-08

*Manual-build verifications (native:build output, file-association double-click) are inherently manual — see below.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `php artisan native:build` produces installable `.dmg` | PKG-04 | Build artifact + OS install cannot run in CI headlessly on dev box | Run `php artisan native:build`, install the `.dmg`, confirm `/Applications/diederik.app` launches a native window showing the dashboard |
| Native chrome present (window/menu/tray/notifications/dark-follows-OS) | PKG-05 | Visual + OS-integration behavior | Launch built app, inspect window, app menu, system tray menu, fire a notification, toggle OS dark mode |
| `.eml`/`.csv` double-click opens app with ingestion intent | PKG-06 | Requires OS file-association registration + double-click | Double-click a `.eml` and a `.csv` in Finder; confirm app focuses and routes to staging page |
| macOS Hardened Runtime entitlements allow bundled PHP to execute | PKG-08 | Entitlements only exercised under a signed/notarized runtime | Inspect generated entitlements file contains the two required keys; confirmed fully in Phase 17 |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
