---
phase: 15
slug: desktop-shell-nativephp-integration
status: draft
nyquist_compliant: true
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
| 15-01-T1 | 01 | 1 | PKG-04 | T-15-01/SC | supply-chain pkg verify | checkpoint | human-verify (Packagist/GitHub) | n/a | ⬜ |
| 15-01-T2 | 01 | 1 | PKG-04/05 | T-15-02 | cleanup_env_keys strips secrets | feature | php artisan about | grep nativephp | ❌ W0 | ⬜ |
| 15-01-T3 | 01 | 1 | PKG-05 | — | arch containment | arch | pest --filter=noNativePhpImportsOutsideDesktopModule | ❌ W0 | ⬜ |
| 15-01-T4 | 01 | 1 | PKG-04 | — | N/A | checkpoint | human-verify (.dmg build+launch) | n/a | ⬜ |
| 15-05-T1 | 05 | 2 | PKG-04 | T-15-17 | published Electron project review | file | test -f resources/brand/logo.svg + icons | ❌ W0 | ⬜ |
| 15-05-T2 | 05 | 2 | PKG-04 | T-15-16 | idempotent migration, no raw path helper | feature | pest --filter=FirstLaunchBootstrap | ❌ W0 | ⬜ |
| 15-05-T3 | 05 | 2 | PKG-08 | T-15-14/15 | entitlements keys present | unit | pest --filter='hardened runtime entitlements' | ❌ W0 | ⬜ |
| 15-05-T4 | 05 | 2 | PKG-07 | — | gates pass on PHP 8.4 | static+suite | pint --test + phpstan + pest on 8.4 | ❌ W0 | ⬜ |
| 15-06-T1 | 06 | 2 | PKG-05 | T-15-18 | theme value validated, not echoed raw | feature | pest --filter=ThemePreference | ❌ W0 | ⬜ |
| 15-06-T2 | 06 | 2 | PKG-05 | T-15-19 | N/A | build | npm run build | existing | ⬜ |
| 15-06-T3 | 06 | 2 | PKG-05 | — | N/A | build | npm run build | existing | ⬜ |
| 15-02-T1 | 02 | 3 | PKG-05 | — | facade quarantine | unit/arch | pest --filter=NativeAppServiceProvider | ❌ W0 | ⬜ |
| 15-02-T2 | 02 | 3 | PKG-05 | T-15-03/04/05 | deep-link to named routes only | unit | pest --filter=DispatchOsNotification | ❌ W0 | ⬜ |
| 15-07-T1 | 07 | 3 | PKG-05 | T-15-20 | N/A | build | npm run build | existing | ⬜ |
| 15-07-T2 | 07 | 3 | PKG-05 | T-15-21 | N/A | build | npm run build | existing | ⬜ |
| 15-07-T3 | 07 | 3 | PKG-05 | — | full-coverage dark guard | arch | pest --filter=dark | ❌ W0 | ⬜ |
| 15-03-T1 | 03 | 4 | PKG-04 | T-15-07 | worker cmd not input-built | feature | schedule:list | grep desktop.email-scan.timer | existing | ⬜ |
| 15-03-T2 | 03 | 4 | PKG-04 | T-15-06 | crash-loop escalation + de-dup | feature | pest --filter=WorkerCrashAlert | ❌ W0 | ⬜ |
| 15-03-T3 | 03 | 4 | PKG-05 | T-15-22 | close_behavior value allow-listed | feature | pest --filter=CloseWindowPrompt | ❌ W0 | ⬜ |
| 15-04-T1 | 04 | 5 | PKG-06 | T-15-12/13 | no nodeIntegration, loopback-only | manual | spike — macOS double-click | n/a | ⬜ |
| 15-04-T2 | 04 | 5 | PKG-06 | T-15-09/11 | path allow-list + canonicalize, no exec | feature | pest --filter=FileOpenedFromOs | ❌ W0 | ⬜ |
| 15-04-T3 | 04 | 5 | PKG-06 | — | staging behind auth, SC3 routing | feature | pest --filter=FileStagingPage | ❌ W0 | ⬜ |
| 15-04-T4 | 04 | 5 | PKG-06 | T-15-10 | session-scoped intent, cross-user check | feature | pest --filter='pending intent' | ❌ W0 | ⬜ |

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

**Approval:** planner-populated 2026-05-22
