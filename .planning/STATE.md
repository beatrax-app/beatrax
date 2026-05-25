---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: Public Release — Desktop Packaging, Multi-User, Developer Mode
status: executing
stopped_at: Phase 16.1.1 UI-SPEC approved
last_updated: "2026-05-25T22:27:44.865Z"
last_activity: 2026-05-25 -- Phase 16.1.1 planning complete
progress:
  total_phases: 12
  completed_phases: 6
  total_plans: 48
  completed_plans: 38
  percent: 79
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-19)

**Core value:** Show me, in one place, what I actually owe and where the money truly came from — across every account chain — so my monthly finances stop being a manual reconciliation puzzle.
**Current focus:** Phase 16.1 — zero-friction-first-run-setup-wizard-manual-transaction-rena

## Current Position

Phase: 16.1 (zero-friction-first-run-setup-wizard-manual-transaction-rena) — EXECUTING
Plan: 1 of 8
Status: Ready to execute
Last activity: 2026-05-25 -- Phase 16.1.1 planning complete

## Performance Metrics

**Velocity (v1.0 baseline):**

- Total plans completed: 87 (v1.0 close)
- v2.0 plans completed: 0
- Total execution time: —

**v2.0 Phases:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 12. Multi-User Activation | — | — | — |
| 13. AppPaths | — | — | — |
| 14. Queue Rewire + Horizon Carve-out | — | — | — |
| 15. Desktop Shell (NativePHP Integration) | — | — | — |
| 16. Developer Mode UI | — | — | — |
| 17. CI/CD Pipeline + Code Signing | — | — | — |
| 18. Auto-Update Plumbing | — | — | — |
| 19. Public Release Boundary | — | — | — |
| 20. v1.0 UAT Close-Out | — | — | — |
| 21. Invite-Only Beta Cycle | — | — | — |
| 12 | 8 | - | - |
| 13 | 3 | - | - |
| 14 | 3 | - | - |
| 15 | 7 | - | - |

*Updated after each plan completion*
| Phase 15 P01 | 205m | 4 tasks | 30 files |
| Phase 15 P06 | 32m | 3 tasks | 37 files |
| Phase 15 P05 | 50m | 4 tasks | 22 files |
| Phase 15 P07 | 105m | 3 tasks | 27 files |
| Phase 15 P02 | 92m | 2 tasks | 11 files |
| Phase 15 P03 | 11min | 3 tasks tasks | 8 created, 7 modified files |
| Phase 15 P04 | 24 | - tasks | - files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table. Major v2.0 architectural decisions (locked at roadmap time):

- **Strict dependency build order (Phases 12 → 21).** Multi-user activation (12) precedes everything because the auth contract is load-bearing for every later phase. AppPaths (13) precedes NativePHP integration because hard-coded paths break the moment Electron boots. Queue rewire (14) precedes desktop shell (15) because the shipped bundle cannot ship Redis. Dev Mode UI (16) waits for the desktop shell. CI/CD (17) waits for a real build target + dev build to gate. Auto-update (18) waits for signed artifacts to verify. Public release boundary (19) is the final pre-public scope. UAT close-out (20) sits between H and BETA so anything found is fixed before the partner sees it. Beta (21) is last.
- **Drop Laravel Horizon + Redis from the shipped desktop bundle.** Horizon stays installed and gated on `DIEDERIK_RUNTIME=herd` for dev mode only. Shipped build runs `database` queue driver + `database` cache locks; `ShouldBeUniqueUntilProcessing` lock store moves from `redis` to `database` via `config('cache.locks_store')`.
- **One shared SQLite per machine, scoped by `user_id`.** Multi-user activation flips the dormant `BelongsToUser` schema on; both users see only their own data via the existing `UserScope` global scope. Partner-sharing "spaces" (read/write modes) are explicitly out of scope.
- **Three new bounded modules (Auth + Desktop + DevMode).** Quarantines NativePHP imports inside `Modules/Desktop/`; quarantines dev-console UI inside `Modules/DevMode/`. New arch-test invariants enforce the boundary.
- **No SMTP password reset.** Recovery codes (10 single-use, printed at signup) + owner-resets-partner flow + `diederik:reset-password` CLI fallback cover the use case without an SMTP relay.
- **Hippocratic License 3.0** (source-available, not OSI-approved). NOTICE.md explains the trade-off; README clearly says "source-available", not "open source".

Recent decisions affecting current work:

- v2.0 ROADMAP.md created 2026-05-19 with 10 phases (Phase 12–21); 47 requirements mapped 100% to phases (no orphans, no duplicates)
- Phase-name slug convention matches v1.0 brevity: `12-multi-user-activation`, `13-app-paths`, `14-queue-rewire-horizon-carveout`, `15-desktop-shell-nativephp-integration`, `16-developer-mode-ui`, `17-cicd-pipeline-code-signing`, `18-auto-update-plumbing`, `19-public-release-boundary`, `20-v1-uat-close-out`, `21-invite-only-beta-cycle`
- `DEVUI-01` (`is_developer` flag + `EnsureDeveloperMode` middleware + Settings toggle) lives entirely in Phase 16 since its three deliverables — column migration, Settings UI, middleware — all belong to the DevMode module's surface; Phase 12's signup-creates-developer behavior consumes the column but does not own it
- All 47 requirements have explicit phase assignments in REQUIREMENTS.md Traceability table
- [Phase 15-01]: NativePHP desktop `^2.2` installed; `Modules/Desktop/` bounded module registered; `noNativePhpImportsOutsideDesktopModule` arch invariant green; a launchable macOS `.dmg` builds and opens a native window rendering the diederik web UI (PKG-04 verified)
- [Phase 15-01]: NativePHP builds use deterministic ad-hoc macOS signing via a `mac.identity:null` prebuild hook (no paid Apple Developer ID for dev builds) — real Developer ID notarization deferred to Phase 17; `bootstrap/cache/*.php` gitignored as per-environment artifacts; standard Laravel `post-autoload-dump` composer script added so the in-bundle `composer install --no-dev` regenerates package discovery against the `--no-dev` vendor tree
- [Phase ?]: [Phase 15-06]: dark theme delivered via Tailwind v4 class strategy — users.theme column (light/dark/system) drives a server-side dark class on <html> with a pre-paint prefers-color-scheme script (D-15/D-16); dark-companion arch guard enforces the plan-06 modules
- [Phase 15-05]: Plan 15-05: brand assets + entitlements stay tracked while nativephp/electron + /build output stay gitignored; a prebuild hook stages tracked files into the working dir on every native:build
- [Phase 15-05]: Plan 15-05: composer.json php constraint relaxed from ^8.5 to ^8.4 — shipped bundle uses nativephp/php-bin 8.4, dev box runs 8.5; CI runs 8.4 on a single-axis matrix that Phase 17 will extend to 8.4+8.5
- [Phase 15-05]: Plan 15-05: FirstLaunchBootstrap — every-launch idempotent migration via Migrator + UserDataPathService-routed SQLite path; absorbs Phase 18 auto-update migrations on the next boot (D-23)
- [Phase 15-07]: dark-theme retrofit complete — every remaining module's Blade views (Categorization, Chains, EmailScan, Receipts, Recurring, DriftAlerts) themed; arch-guard allow-list deleted, full-coverage regression lock active
- [Phase ?]: [Phase 15-02]: native chrome wired — D-09 tray, D-10 stateful window, D-11 app menu with verbatim diederik-specific entries; OsThemeSignal contract implemented by OsThemeProbe (bundle-gated binding); D-12 OS notifications via DispatchOsNotification with D-13 focus-gate via WindowFocusState; D-14 deep-link via NotificationDeepLink Public event (listener lands in 15-04)
- [Phase ?]: [Phase 15-02]: bundle-gated subscriptions pattern — every native-shell-side-effect (contract bindings, event subscriptions, OS notifications) gates on nativephp-internal.running=true; under Herd / CI / tests, the in-app SystemAlertsBanner handles the case and the binding/subscription is absent
- [Phase ?]: Phase 15 P03: D-05/D-06 worker+scheduler config, D-07 SurfaceWorkerCrashAlert (rolling-window detector + focus-gated OS notification), D-08 CloseWindowPrompt + WindowCloseBehavior (users.close_behavior + allow-list guard)
- [Phase ?]: [Phase 15-04]: PKG-06 file associations shipped — FileOpenIntake security boundary, FileStagingPage with read-and-clear at mount, PendingFileIntent session-store with stale-path realpath check, ContinuePendingFileIntentAfterLogin for the D-04 round-trip; .csv routes to Modules/Import (SC3 caveat), .eml routes to Modules/Receipts via the Public FileOpenedFromOs event
- [Phase ?]: [Phase 15-04]: 15-02 NotificationDeepLink click listener + 15-03 close-window-choice JS glue consolidated here as deferred items — NavigateOnNotificationDeepLink + ApplyCloseWindowChoice + in-layout JS hook + /desktop/close-action route. Full Electron-side closable(false) pre-prompt intercept remains a Phase 21 beta HUMAN-UAT item

### Pending Todos

[From .planning/todos/pending/ — ideas captured during sessions]

None yet for v2.0.

### Blockers/Concerns

Phase-research flags carried from research/SUMMARY.md (to address during `/gsd:plan-phase` for each):

- **Phase 15 (D):** NativePHP file-association handler behavior on Windows + Linux (docs are macOS-leaning); 2-day spike likely needed during planning
- **Phase 17 (F):** Apple notarization workflow on GitHub Actions runners; Azure Trusted Signing for Windows (untested in this project); Linux `.AppImage` + `.deb` installer validation
- **Phase 18 (G):** electron-updater signature verification across all three platforms; first-install-can't-auto-update UX
- **Phase 21 (BETA):** Beta cohort >1 user concurrency on the same SQLite (file-locking behaviour under WAL)

Phases with standard patterns (skip deeper research):

- **Phase 12 (A):** Fortify integration is well-documented; `CurrentUserProvider` already exists in v1.0
- **Phase 14 (C):** `database` queue driver + `Cache::lock()` pattern is canonical Laravel 11+
- **Phase 16 (E):** Bespoke Livewire component pattern is the v1.0 default
- **Phase 19 (H):** Cross-module review + license + docs is standard release hygiene

Gaps to address during phase planning (from research/SUMMARY.md):

- **PHP 8.5-vs-8.4 in shipped bundle** — `nativephp/php-bin` ships 8.1–8.4 only; project pin is `^8.5`. Run a Larastan-L10-on-8.4 spike during Phase 13 start; if it passes, bundle 8.4 and keep dev pin at 8.5 + add an 8.4 axis to the PR-gate matrix (Phase 17)
- **Windows code-signing pricing + provider final pick** — Recommended Azure Trusted Signing ($10/mo, GitHub-hosted-runner compatible). Validate during Phase 17 start before committing the matrix
- **macOS notarization timing in CI** — First notarization can take 5-15 minutes; CI matrix needs a generous timeout. Verify in Phase 17

## Deferred Items

Items acknowledged at v1.0 milestone close on 2026-05-19. Carried forward into v2.0 for human resolution — most close out in **Phase 20: v1.0 UAT Close-Out**.

| Category | Item | Status | Deferred At | Target Phase |
|----------|------|--------|-------------|--------------|
| uat_gap | 03-HUMAN-UAT.md (5 pending scenarios) | partial | 2026-05-19 | Phase 20 |
| uat_gap | 04-HUMAN-UAT.md (2 pending scenarios) | partial | 2026-05-19 | Phase 20 |
| uat_gap | 06-HUMAN-UAT.md (8 pending scenarios) | partial | 2026-05-19 | Phase 20 |
| uat_gap | 08-HUMAN-UAT.md (3 pending scenarios) | partial | 2026-05-19 | Phase 20 |
| uat_gap | 11-HUMAN-UAT.md (7 pending scenarios) | partial | 2026-05-19 | Phase 20 |
| verification_gap | 03-VERIFICATION.md | human_needed | 2026-05-19 | Phase 20 |
| verification_gap | 08-VERIFICATION.md | human_needed | 2026-05-19 | Phase 20 |
| verification_gap | 11-VERIFICATION.md | human_needed | 2026-05-19 | Phase 20 |
| seed | SEED-001-public-release-milestone | activated | 2026-05-19 | v2.0 milestone (Phases 12–21) |
| deferred_with_trigger | ING-09 PayPal Reporting API | deferred | v1.0 → v2.0 | Out of scope (trigger: PayPal Business upgrade) |

## Session Continuity

Last session: 2026-05-25T18:49:01.811Z
Stopped at: Phase 16.1.1 UI-SPEC approved
Resume: Run `/gsd:execute-phase 15` to pick up the next Phase 15 plan (02, 03, or 04)

Next action: `/gsd:execute-phase 15` — continue with one of plans 02 (native chrome), 03 (worker daemon), or 04 (file associations)
