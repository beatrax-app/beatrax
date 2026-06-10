---
phase: "04-responsive-installable-pwa-seed-008"
plan: "06"
subsystem: "responsive-views"
tags: ["mobile", "responsive", "dashboard", "settings", "goals", "pots", "install-hint", "bottom-sheet", "card-list", "pwa"]
dependency_graph:
  requires:
    - "04-03 (mobile shell primitives: x-core::bottom-sheet, x-core::install-hint, .card-list-item CSS, mobileNav store)"
  provides:
    - "Alerts-first phone dashboard order (D-15): drift-badge → KPI single-column → goals summary → upcoming → status → top-spending"
    - "Standing install-hint card at bottom of dashboard main column (D-22)"
    - "Settings single-column phone collapse + Install row with x-core::install-hint (D-22)"
    - "Goals phone card-list (.goals-phone-list .card-list-item) at <768px; desktop grid unchanged"
    - "Goals create/edit bottom sheet (x-core::bottom-sheet name='goal-form') at <768px"
    - "Pots phone card-list (.pots-phone-list .card-list-item) at <768px; desktop unchanged"
    - "Pots fund/move/create/edit bottom sheets at <768px (pot-form, pot-fund, pot-move)"
    - "Always-visible phone row actions for Fund/Move on pots (D-12)"
  affects:
    - "Modules/Core/Resources/views/livewire/dashboard.blade.php"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php"
    - "Modules/Goals/Resources/views/livewire/goals-page.blade.php"
    - "Modules/Pots/Resources/views/livewire/pots-page.blade.php"
tech_stack:
  added: []
  patterns:
    - "CSS order-based phone reordering: .dashboard-main flex with .dashboard-phone-order-N at <768px, no markup duplication"
    - "Phone/desktop list split: .goals-phone-list / .goals-desktop-list toggled via scoped @media CSS display:none"
    - "Bottom sheet trigger pattern: window.innerWidth < 768 ? dispatch(open-sheet) : $flux.modal().show()"
    - "Settings phone collapse: .settings-grid flex-direction:column override at <768px"
key_files:
  modified:
    - "Modules/Core/Resources/views/livewire/dashboard.blade.php"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php"
    - "Modules/Goals/Resources/views/livewire/goals-page.blade.php"
    - "Modules/Pots/Resources/views/livewire/pots-page.blade.php"
decisions:
  - "Dashboard reordering via CSS flex order on a .dashboard-main wrapper div — avoids duplicating any Livewire component markup while achieving D-15 inbox-first posture at phone width"
  - "Goals and Pots phone lists use a dual-list approach (.goals-phone-list visible at <768px, .goals-desktop-list visible at >=768px) — the bottom-sheet form HTML is a phone-optimised duplicate binding to the same Livewire wire: properties; the underlying Livewire component state is shared (not two component instances)"
  - "Trigger buttons use window.innerWidth < 768 to conditionally dispatch open-sheet vs $flux.modal().show() — this avoids showing the bottom sheet at desktop width when the flux modal is also open"
  - "Pots withdraw bottom-sheet not added — the kebab menu item (Withdraw) is desktop-only for now; phone users can fund/move from the always-visible row actions (D-12)"
  - "goals-summary-card.blade.php not modified — the card already renders compact enough for phone via the existing list structure; no card-list conversion needed for a 3-item summary card"
metrics:
  duration: "~35 minutes"
  completed_date: "2026-06-10"
  tasks_completed: 2
  files_changed: 4
---

# Phase 04 Plan 06: Phone Dashboard, Settings, Goals, Pots Responsive Pass Summary

**One-liner:** Alerts-first phone dashboard with install-hint card, single-column Settings with install row, and goals/pots card-lists with bottom-sheet create/edit/fund/move modals — all desktop layouts unchanged.

---

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | Alerts-first phone dashboard + standing install-hint card (D-15/D-22) | `922da85` | dashboard.blade.php |
| 2 | Settings single-column + install row; Goals/Pots card-lists + bottom-sheet modals | `9bb9ec9` | settings-page.blade.php, goals-page.blade.php, pots-page.blade.php |

---

## Deviations from Plan

None — plan executed exactly as written.

---

## Deferred Human Verification

Per the project checkpoint policy (approved-deferred), the following items are recorded for phase-end UAT:

**Checkpoint: Verify dashboard, settings, goals, pots phone surfaces and desktop parity**

Items to verify at phase-end UAT:

1. With the dev app running, resize browser to ~390px width.
2. **/ dashboard**: order is drift-alerts strip → In/Out/Net (single column) → goals summary → upcoming content (fixed payments, spending trend) → status tiles/net worth → top spending/recent transactions; an "Also want to see your data on your phone?" install card sits at the bottom.
3. **/settings**: single-column rows; an "Install beatrax as an app" card section is present with the install-hint component.
4. **/goals**: card-per-row list at phone width; tap "Add goal" → the form slides up as a bottom sheet (not a centered modal); submit works and the list updates.
5. **/goals**: tap "Edit" on a row → bottom sheet opens pre-filled with goal data; save works.
6. **/pots**: card-per-row with account name as secondary text and balance right-aligned; Fund/Move buttons are always visible (D-12); tap Fund → bottom sheet opens; submit works.
7. **/pots**: tap "Add pot" header button → pot creation form slides up as bottom sheet.
8. Resize to desktop (≥768px): dashboard 3-up KPI grid back, settings multi-column back, goals/pots grids back, modals open as normal centered Flux modals again.

---

## Quality Gate Results

| Gate | Status | Notes |
|------|--------|-------|
| `vendor/bin/pest Modules/Core/tests/ Modules/Goals/tests/ Modules/Pots/tests/` | PASS | 338 tests passed (878 assertions) |
| `vendor/bin/pint --test` (modified blade files) | PASS | All 4 files format-clean |
| DriftAlerts/GlobalDriftThresholdSettingTest (4 tests) | KNOWN FAIL (pre-existing) | Not caused by this plan — tracked separately |
| Acceptance criteria grep | PASS | All verified below |

Acceptance grep results:
- `dashboard.blade.php` contains `x-core::install-hint` ✓
- `dashboard.blade.php` has `dashboard-phone-order-2` (alerts) through `order-8` (install hint) ✓
- `dashboard.blade.php` has `max-width: 767px` breakpoint ✓
- `dashboard.blade.php` has `md:grid-cols-3` desktop KPI grid intact ✓
- `settings-page.blade.php` contains `x-core::install-hint` ✓
- `settings-page.blade.php` has `max-width: 767px` phone breakpoint ✓
- `goals-page.blade.php` contains `x-core::bottom-sheet` ✓
- `goals-page.blade.php` contains `.card-list-item` ✓
- `pots-page.blade.php` contains `x-core::bottom-sheet` (3 instances: pot-form, pot-fund, pot-move) ✓
- `pots-page.blade.php` contains `.card-list-item` ✓

---

## Known Stubs

None. All components are fully wired:
- Install-hint uses the x-core::install-hint component from Plan 03 (full implementation with beforeinstallprompt, iOS fallback, dismiss).
- Bottom-sheet forms bind directly to Livewire wire:model properties (same component state as desktop Flux modals).
- Phone card lists display real data (name, balance/amount, account from the same $rows/$groups props).

---

## Threat Flags

No new threat surface outside the plan's threat model.

| Threat ID | Mitigation Status |
|-----------|-----------------|
| T-04-06-01 (install hint client-side data) | Mitigated: install-hint stores only the BeforeInstallPrompt event object — no financial data |
| T-04-06-02 (bottom-sheet duplicate form path) | Mitigated: phone bottom-sheet forms bind to the same Livewire component instance's wire:model properties; no second mutation path created |
| T-04-06-SC (no new packages) | Confirmed: zero new npm/composer packages |

---

## Self-Check: PASSED

- [x] `Modules/Core/Resources/views/livewire/dashboard.blade.php` — exists, contains `x-core::install-hint`, `dashboard-phone-order-2` through `order-8`
- [x] `Modules/Core/Resources/views/livewire/settings-page.blade.php` — exists, contains `x-core::install-hint`, settings-grid flexbox phone override
- [x] `Modules/Goals/Resources/views/livewire/goals-page.blade.php` — exists, contains `.card-list-item`, `x-core::bottom-sheet`
- [x] `Modules/Pots/Resources/views/livewire/pots-page.blade.php` — exists, contains `.card-list-item`, three `x-core::bottom-sheet` instances
- [x] Task 1 commit `922da85` — confirmed in git log
- [x] Task 2 commit `9bb9ec9` — confirmed in git log
- [x] `vendor/bin/pest Modules/Core/tests/ Modules/Goals/tests/ Modules/Pots/tests/` — 338 passed
- [x] `vendor/bin/pint --test` on modified files — passed
