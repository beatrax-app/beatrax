---
phase: 15-desktop-shell-nativephp-integration
plan: 06
subsystem: dark-theme
tags: [dark-mode, tailwind, livewire, settings, arch-test]
requires:
  - users table (Core)
  - SettingsPage Livewire component (Core)
  - app layout (resources/views/layouts/app.blade.php)
provides:
  - users.theme preference column (light/dark/system)
  - Tailwind v4 class-strategy dark variant
  - Settings Appearance Light/Dark/System control (D-16)
  - layout dark-class wiring with no flash (D-15)
  - OsThemeSignal Public contract (Desktop)
  - darkCompanionUtilitiesOnThemedViews arch guard
affects:
  - every Core/Auth/Ledger/Import/Forecasting Blade view (dark: companions)
tech-stack:
  added: []
  patterns:
    - "Tailwind v4 @custom-variant dark class strategy"
    - "Server-side dark class + pre-paint prefers-color-scheme script"
    - "Instant-apply Livewire control (raw query-builder write, no submit)"
key-files:
  created:
    - Modules/Core/Database/Migrations/2026_05_22_000001_add_theme_to_users.php
    - Modules/Desktop/Public/Contracts/OsThemeSignal.php
    - Modules/Core/tests/Feature/ThemePreferenceTest.php
  modified:
    - resources/css/app.css
    - resources/views/layouts/app.blade.php
    - Modules/Core/Models/User.php
    - Modules/Core/Internal/Http/Livewire/SettingsPage.php
    - Modules/Core/Resources/views (7 Blade views)
    - Modules/Auth/Resources/views (8 Blade views)
    - Modules/Ledger/Resources/views (3 Blade views)
    - Modules/Import/Resources/views (6 Blade views)
    - Modules/Forecasting/Resources/views (11 Blade views)
    - tests/Contracts/BoundaryArchTest.php
decisions:
  - "theme column lives in Modules/Core (Core owns the users table) per PATTERNS.md"
  - "setTheme() validates against the light/dark/system allow-list before the raw write (T-15-18)"
  - "dark-companion arch guard allow-lists ONLY the 7 plan-07 modules; plan-06 modules are enforced"
metrics:
  duration: ~32m
  completed: 2026-05-23
---

# Phase 15 Plan 06: Dark Theme Infrastructure & High-Traffic View Retrofit Summary

Dark mode is real and correct end-to-end: a `users.theme` preference (light/dark/system),
a Tailwind v4 class-strategy dark variant, a no-flash server-side dark class on `<html>`,
an instant-apply Settings Light/Dark/System control, and `dark:` companion utilities
across ~35 Blade views in the five highest-traffic modules — all gated by a new
dark-companion arch guard.

## What Was Built

**Task 1 — Dark-mode infrastructure (RED `2989e2c`, GREEN `c591fab`)**

- `users.theme` column (`string(16)`, default `system`) via an anonymous-class
  migration in `Modules/Core/Database/Migrations/`, mirroring the
  `add_auto_import_drop_folder_to_users` analog. `theme` added to the `User` model's
  `$fillable`, `$casts`, and `$attributes` defaults.
- `resources/css/app.css`: added `@custom-variant dark (&:where(.dark, .dark *));` —
  the Tailwind v4 class strategy.
- `resources/views/layouts/app.blade.php`: resolves the authenticated user's `theme`
  and toggles the `dark` class on `<html>` server-side. For an explicit `light`/`dark`
  the class is decided server-side; for `system`, a synchronous pre-paint `<head>`
  script reads `prefers-color-scheme` before first paint to prevent a flash. When the
  `OsThemeSignal` binding is present (desktop bundle), `system` also resolves
  server-side. `<html>` and `<body>` carry `dark:bg-slate-950` / `dark:text-slate-100`
  companions.
- `SettingsPage` + the settings-page Blade view: a new `## Appearance` section with a
  Light/Dark/System segmented control. `setTheme()` is instant-apply (no submit
  button) — it validates `theme` against `required|in:light,dark,system` then writes
  via the raw query builder, exactly mirroring `toggleAutoImport`.
- `Modules/Desktop/Public/Contracts/OsThemeSignal.php`: a one-method
  (`currentOsTheme(): string`) Public contract — the cross-module seam the layout uses
  for `system` resolution without importing Desktop internals. Plan 02's `OsThemeProbe`
  implements it; absence of the binding is the fall-back signal.
- `darkCompanionUtilitiesOnThemedViews` arch guard added to `BoundaryArchTest.php`:
  scans `resources/views` + every `Modules/*/Resources/views`, flags any class string
  with a `bg-white` / `text-slate-900` utility lacking a `dark:` companion. Its
  allow-list excludes ONLY the seven plan-07 modules (Categorization, Chains,
  EmailScan, Receipts, Recurring, DriftAlerts, Desktop) — the plan-06 modules are
  enforced from the moment the guard landed.

**Task 2 — Dark variants for the app shell, Auth, and Ledger (`e42283c`)**

`dark:` companion utilities added across all 7 Core, 8 Auth, and 3 Ledger Blade views
per the UI-SPEC token table. The top-nav `$isActive` helper, count pills, the
SystemAlertsBanner amber/rose alert chrome (`amber-950/800/200`, `rose-950/800/200`),
and the recovery-codes `@class` block all got dark variants. Caption text steps to
`slate-400` on dark (slate-500 body text is forbidden on dark surfaces).

**Task 3 — Dark variants for Import and Forecasting (`cb17313`)**

`dark:` companions added across all 6 Import and 11 Forecasting Blade views. The
forecast page's seven `@class` segmented-control blocks, the net-diff tile's
positive/negative `$tint` ternary (`dark:emerald-500` / `dark:rose-500`), and the
series-confidence chip `match` were themed by hand; the remaining mechanical edits
followed the token table.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking issue] Restored the declared `apexcharts` npm dependency**
- **Found during:** Task 1 (`npm run build` verification step)
- **Issue:** `npm run build` failed — `apexcharts` is declared in `package.json`
  (`^3.54.1`, committed in `8550949`) but was absent from `node_modules`, so Rollup
  could not resolve the `import "apexcharts"` in `resources/js/app.js`.
- **Fix:** ran `npm install` to restore the already-declared dependency tree. This is
  not a new package install (not the slopsquatting-exclusion case) — `apexcharts` is a
  pre-existing committed dependency; only its installed state was missing.
- **Files modified:** `package-lock.json` (reconciled with `package.json`)
- **Commit:** `c591fab`

## Notes

- **Guard sequencing.** Task 1's acceptance lists `php artisan test --filter="dark"`
  as passing. Because the guard scans every in-scope view, it could only go fully
  green once Tasks 2 & 3 themed the Core/Auth/Ledger/Import/Forecasting views — this
  is the deliberate phased design (each task is independently committed; the guard is
  green at plan close). At Task 1's commit the guard correctly flagged the not-yet-
  themed views; the layout + Settings views Task 1 themed were already clean.
- **Transient tooling.** A one-off Python helper drove the mechanical `dark:`
  companion edits; it was deleted before the Task 3 commit and is not part of the
  repo (codebase stays free of build-time scratch artifacts).

## TDD Gate Compliance

Task 1 (`tdd="true"`) followed RED → GREEN:
- RED: `test(15-06): add failing tests for dark-theme preference` (`2989e2c`) — 6
  failing tests.
- GREEN: `feat(15-06): stand up dark-mode infrastructure` (`c591fab`) — all 6 pass.
No REFACTOR commit was needed.

## Verification

- `php artisan test --filter="ThemePreference"` — 6 passed.
- `php artisan test --filter="dark"` — 5 passed (the dark-companion guard green for
  all plan-06 module views).
- `npm run build` — succeeds.
- Full suite: 2084 passed, 6 todos + 6 skipped (the expected Wave 0 scaffolding stubs
  for plans 15-02 / 15-04).
- Larastan level 10 (strict, `--memory-limit=1G`) — no errors.
- Laravel Pint — passed.
- Manual (carried to phase HUMAN-UAT): toggle Settings Light/Dark/System and the OS
  theme; confirm no flash and correct dark rendering on shell, login, dashboard,
  imports, forecast.

## Self-Check: PASSED

All created files exist on disk; all four task commits (`2989e2c`, `c591fab`,
`e42283c`, `cb17313`) are present in the git history.
