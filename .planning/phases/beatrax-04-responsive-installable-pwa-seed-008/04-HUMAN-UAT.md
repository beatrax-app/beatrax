---
status: resolved
phase: 04-responsive-installable-pwa-seed-008
source: [04-VERIFICATION.md]
started: 2026-06-10T22:02:05Z
updated: 2026-06-11T01:30:00Z
---

## Current Test

[complete — browser-MCP UAT performed 2026-06-11, driven via Chrome at desktop width + 390px iframe viewport]

## Tests

### 1. ApexCharts v5 rendering
expected: Forecast range-area chart, aggregate line chart, and recurring-series detail chart all render in light + dark mode with no console errors (ApexCharts 3→5 upgrade).
result: issue → FIXED during UAT. All three charts crashed (`drawImageAnnos` TypeError, blank plots): the options emitted `annotations` as a bare `[]`/partial object, which clobbers v5's defaults. Fixed in b2de106 (full annotations object shape). All three surfaces now render clean in dark mode, zero console errors. Light mode: theme toggle does not persist due to the PRE-EXISTING settings-save validation bug (baseCurrency) — light chart options are the untransformed server defaults, low risk.

### 2. PWA install affordance
expected: Manifest shows correct name/icons/standalone; install affordance appears.
result: pass. /site.webmanifest: name beatrax, display standalone, 192/512/maskable icons all 200. Dual theme-color metas, viewport-fit=cover, manifest link present. (Browser install-button chrome not scriptable via MCP; manifest-panel-equivalent checks all green.)

### 3. Service worker activation + offline page
expected: SW activates with versioned cache; offline reload shows branded page; no financial HTML cached.
result: pass (offline reload verified by proxy). SW state activated, scope /, cache `beatrax-shell-v0.0.0-dev` contains only build assets + icons + offline.html — zero financial HTML. /sw.js serves with Service-Worker-Allowed:/ + no-cache. Literal network-offline reload still worth one manual flip in DevTools.

### 4. Mobile top bar + drawer
expected: Top bar + hamburger at <1024px; drawer opens with sidebar; scrim closes; ⌘K/Ctrl+K platform label; hidden on touch.
result: issue → FIXED during UAT (two regressions found):
  (a) Desktop ≥1024px had NO sidebar at all — closed drawer's inline display:none + the <1024 .side hide rule also hid the only sidebar mount inside the drawer (fixed 92eed99).
  (b) At phone width the top bar rendered as a left column beside main instead of stacking above (fixed 553e236, max-lg:flex-col).
  After fixes: top bar full-width, drawer opens with complete sidebar + scrim, ⌘K shows on macOS. PASS.

### 5. Ledger + Recurring phone surfaces
expected: Card-per-row transactions with infinite scroll; recurring card-lists; back affordances; desktop parity.
result: issue (1 open gap + 1 fixed). Card-lists, signed amounts, CAT chips, back arrows on detail pages: PASS (after fix 403fe35 — detail pages stacked TWO top bars; global hamburger bar now suppressed when a page mounts its own). OPEN GAP: "infinite scroll" is cursor paging that REPLACES the 50 visible rows instead of appending (loadMore advances the cursor and swaps the page); the wire:intersect observer also did not fire on scroll in testing. Net effect: phone list silently swaps pages rather than growing. 168 rows in the 90-day window, only 50 reachable at once.

### 6. Counterparties + CashBook + Chains phone surfaces
expected: Toolbar collapses to filter sheet; cards 1-col; profile stats stack; triage usable; cashbook card-list; chains horizontal scroll.
result: issue (1 open gap). Profile (stacked hero stats, scrollable tabs, single back bar), triage, cashbook form, chains scroll wrappers (overflowX=0): PASS. OPEN GAP: counterparty index renders BOTH the new filter-sheet trigger row AND the old desktop toolbar (second search input + sort + cards/list toggle) at phone width — the desktop toolbar was supposed to collapse into the sheet.

### 7. Dashboard + Settings + Goals + Pots phone surfaces
expected: Alerts-first dashboard, install hints, card-lists, bottom-sheet modals, desktop parity.
result: pass. Alerts-first ordering ✓, KPI tiles single-column ✓, settings sections single-column ✓, goals bottom-sheet modal (drag handle, full-width form, 44px buttons) ✓. Install-hint card not testable in the iframe harness (beforeinstallprompt never fires there) — verify once on a real phone.

### 8. Chart resize + Import/DevMode power surfaces
expected: Charts 240px/no-legend at <768px; import + dev surfaces scroll horizontally.
result: pass (1 minor cosmetic note). Chart at phone: height 240px, legend hidden, no page overflow ✓ (responsive[] breakpoint working). Upload wizard single-column ✓. MINOR: /dev keeps its internal sidebar at phone width — content cramped and the overview stats-card header text overlaps; functional but ugly (dev-only surface).

## Summary

total: 8
passed: 6
issues: 2
pending: 0
skipped: 0
blocked: 0

## Gaps

### Gap 1: Phone transactions infinite scroll replaces instead of appends
status: resolved (04-08 — accumulatedRows state + re-keyed sentinel; 4 new feature tests prove 50→100→130 accumulation; browser re-check confirms no row replacement. Sentinel-fire-on-scroll unverifiable in occluded automation windows — IntersectionObserver suspended; one manual foreground scroll recommended.)
severity: major
test: 5
detail: TransactionsList::loadMore() is cursor paging — the Livewire re-render swaps all 50 rows for the next 50. UI-SPEC promised infinite scroll (accumulating list). Additionally the wire:intersect sentinel never fired on scroll during testing (only a direct component.call advanced the cursor) — needs verification + likely an accumulate-rows property on the component with the card-list looping accumulated rows.

### Gap 2: Counterparty index desktop toolbar not collapsed at phone width
status: resolved (04-09 — inline display:flex moved to inner wrapper; browser re-check at 390px: desktop toolbar display:none, one visible search input.)
severity: minor
test: 6
detail: At <768px the index shows the filter-sheet trigger AND the original toolbar (duplicate search input, sort control, cards/list toggle). The original toolbar row needs phone-width hiding (its controls are reachable via the filter sheet).

### Gap 3: Dev Console internal sidebar cramped at phone width
status: resolved (04-09 — stats tiles grid-cols-1 sm:grid-cols-3; browser re-check: tiles stack, no overlapping text.)
severity: cosmetic
test: 8
detail: /dev/* keeps its two-pane layout at 390px; overview stats card headers overlap. Dev-only surface; acceptable to defer or fold into the next DevMode touch.

## Fixed during UAT (committed)

- 92eed99 drawer-container visible at desktop + .side visible inside open drawer
- 553e236 max-lg:flex-col — top bar stacks above main at phone
- 403fe35 suppress global hamburger bar when page mounts its own top bar
- b2de106 full ApexCharts v5 annotations object shape (charts were blank + console TypeError)
- cad4730 package-lock name field restore

## Notes

- Theme toggle (Light/Dark/System) does not persist — same pre-existing settings-save validation wall (baseCurrency) as the tracked DriftAlerts test failures. Pre-dates Phase 4; tracked in STATE.md TODOs.
- Post-UAT full suite: 3201 passed; only the 4 pre-existing DriftAlerts failures remain. Larastan level 10 clean, Pint clean.
