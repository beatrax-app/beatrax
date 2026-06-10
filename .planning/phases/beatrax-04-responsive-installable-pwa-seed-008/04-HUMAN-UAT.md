---
status: partial
phase: 04-responsive-installable-pwa-seed-008
source: [04-VERIFICATION.md]
started: 2026-06-10T22:02:05Z
updated: 2026-06-10T22:02:05Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. ApexCharts v5 rendering
expected: Forecast range-area chart, aggregate line chart, and recurring-series detail chart all render in light + dark mode with no console errors (ApexCharts 3→5 upgrade).
result: [pending]

### 2. PWA install affordance
expected: Chrome DevTools → Application → Manifest shows correct name, icons (192/512/maskable), standalone display; the browser offers an install affordance.
result: [pending]

### 3. Service worker activation + offline page
expected: SW activates with a versioned cache name (beatrax-shell-v…); going offline and reloading shows the branded offline page (not a browser error); DevTools Cache Storage contains no financial HTML pages.
result: [pending]

### 4. Mobile top bar + drawer
expected: At <1024px a top bar with hamburger appears; hamburger opens the drawer with the sidebar inside; scrim click closes it. Sidebar kbd hint shows ⌘K on macOS / Ctrl+K elsewhere and is hidden on touch devices.
result: [pending]

### 5. Ledger + Recurring phone surfaces
expected: At phone width, transactions render as card-per-row with infinite scroll (no "Load more" button needed); recurring pages render card-lists; series detail + transaction detail show a back arrow in the top bar. Desktop (≥1024px) is unchanged.
result: [pending]

### 6. Counterparties + CashBook + Chains phone surfaces
expected: Counterparty index collapses toolbar to a filter sheet and cards to one column; profile hero stats stack single-column with usable tab bar; triage kbd hints hidden on touch; cashbook renders card-list; chains queues scroll horizontally without breaking layout. Desktop unchanged.
result: [pending]

### 7. Dashboard + Settings + Goals + Pots phone surfaces
expected: Dashboard reorders alerts-first at phone width with a standing install-hint card; settings stack single-column with an install row; goals/pots render card-lists and their modals open as bottom sheets at phone width. Desktop unchanged.
result: [pending]

### 8. Chart resize + Import/DevMode power surfaces
expected: Charts shrink to 240px height with reduced ticks and no legend at <768px; all 4 import surfaces and all 9 /dev/* surfaces scroll horizontally at phone width instead of breaking. Desktop restored at full width.
result: [pending]

## Summary

total: 8
passed: 0
issues: 0
pending: 8
skipped: 0
blocked: 0

## Gaps
