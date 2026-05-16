---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 05
subsystem: chains
tags: [chain-drawer, flux-flyout, livewire-sfc, ui-02, chn-04, d-90, d-91, d-92, d-93, three-tier-chip, fan-out-pagination, explicit-props, issue-13]

# Dependency graph
requires:
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 04
    provides: ChainLinkQuery::forTransaction (BFS ChainTree walker) + ConfirmChainLink + RejectChainLink Public action classes + ChainTreeNode DTO
  - phase: 02-asn-camt053-mt940-ingestion
    provides: TransactionDetail Livewire SFC + transaction-detail.blade.php (target for the "View chain" trigger button)
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 02
    provides: chain_links + card_statements schema; ChainLink/CardStatement Eloquent models; 5 Public DTOs
provides:
  - ChainDrawer Livewire SFC — project's first Flux flyout consumer, listens via #[On('chain-drawer:open')], owns $fanoutPage + $collapsedLegs state, delegates Confirm/Reject to the same Public action classes the /chains/review queue uses (D-86)
  - chain-drawer.blade.php — top-level <flux:modal flyout position="right" class="md:w-2xl"> with sticky header + four-state render (pre-mount / no chain / root-only / waterfall)
  - chain-node.blade.php partial — explicit @props(['node', 'fanoutPage']) declaration (issue #13 fix); three-tier confidence chip (D-91 — Deterministic / Confirmed / Candidate, no hue encoding); inline Confirm + Reject chips on candidate legs; ICS bulk-settle fan-out container with "Show 10 more · X of N" pagination (D-93); empty-fan-out copy "No ICS charges in this settlement"
  - TransactionDetail "View chain" trigger — wire:click+x-on:click pair dispatches chain-drawer:open AND modal-show so the drawer SFC and the Flux flyout open as one user-visible action
affects: [05-05b]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Flux modal flyout — first project use. Markup is `<flux:modal name='chain-drawer-{N}' flyout position='right' class='md:w-2xl'>`. The `name` attribute is parameterised with the root transaction id so the same drawer SFC can mount once and the trigger button targets it via `modal-show` with the matching `name`. Flux owns open/close/escape/click-outside; the SFC owns data and pagination state."
    - "Two-event open contract — the trigger button dispatches BOTH `chain-drawer:open` (consumed by the Livewire SFC via `#[On(...)]` to set $transactionId) AND `modal-show { name: 'chain-drawer-{N}' }` (consumed by Flux to open the flyout). Splitting the events keeps the data layer (Livewire) and the presentation layer (Flux) independently testable. The Livewire-only test path (`Livewire::test(ChainDrawer::class)->call('open', $txId)->assertSee(...)`) does not need to also dispatch modal-show; Flux's open state is irrelevant to the rendered chain tree's HTML output."
    - "Explicit @props([...]) on Blade partials — issue #13 fix. The `chain-node.blade.php` partial declares `@props(['node', 'fanoutPage'])` at its top AND every `@include` call passes both keys explicitly. This avoids the Livewire-component-scope implicit-inheritance footgun where a child partial silently picks up a property from its parent Livewire component (drawer's `$fanoutPage`) without it being part of the partial's documented contract. The arch invariant is grep-verifiable (one of the 12 ChainDrawerTest cases is a file-content `expect(...)->toContain(\"@props(['node', 'fanoutPage'])\")` regression gate)."
    - "ICS bulk-settle fan-out child reconstruction — the Public ChainTree DTO leaves `ChainTreeNode.children` empty (Wave 3 contract — the drawer is the sole consumer that needs it). The drawer's `render()` re-emits the tree by querying `chain_links` once per visited node (single bounded query, filtered to `kind=ics_bulk_settle` + `state ∈ {confirmed, candidate}` + `from_transaction_id ∈ visited`) and re-attaching the children. A fan-out is recognised only when a node has ≥2 outgoing bulk-settle legs — a 1-charge bulk-settle stays a flat hop in the waterfall (no fan-out container, no pagination)."
    - "Three-tier confidence chip without hue encoding (D-91 + UI-SPEC § Color) — chip text is the differentiator (`Deterministic` / `Confirmed` / `Candidate`); colour treatment is uniform `slate-50` chrome with `text-slate-500` on Candidate (calm dimming) vs `text-slate-900` elsewhere. The chip's `aria-label` carries the long-form (\"Confidence: deterministic match\" / \"Confidence: confirmed\" / \"Confidence: candidate; needs review\") so screen-reader users hear the semantic, never just the colour."
    - "Forward-only fan-out pagination (D-93) — `$fanoutPage` starts at 0 (rows 1-10 visible), `showMoreFanout()` increments to render rows 1 to 10·(page+1). No collapse-back affordance per UI-SPEC; opening a different transaction resets the counter via `open()`. The visible-slice math (`array_slice($node->children, 0, $pageSize * ($fanoutPage + 1))`) is intentional, not buggy — paginating in the chain-node partial keeps the drawer-level state minimal (one integer)."
    - "Type coercion helpers (private static `toInt(mixed)` / `toString(mixed)`) on ChainDrawer mirror the same helpers on ChainLinkQuery — both are Larastan-strict-mode-friendly null/scalar narrowing wrappers around raw stdClass query-builder rows. The duplication is intentional: the alternative is exporting them as a separate static class, which would land an over-engineered helper for two callers."

key-files:
  created:
    - Modules/Chains/Internal/Http/Livewire/ChainDrawer.php
    - Modules/Chains/Resources/views/livewire/chain-drawer.blade.php
    - Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php
  modified:
    - Modules/Chains/Providers/ChainsServiceProvider.php
    - Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php
    - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php

key-decisions:
  - "Trigger button dispatches BOTH `chain-drawer:open` (Livewire) AND `modal-show` (Flux). The Livewire `wire:click` is the canonical data-side event (the SFC's `#[On(...)]` listener consumes it); the Alpine `x-on:click` dispatches both events as a single user gesture so Flux opens the flyout in the same click. This is the simplest contract that keeps Livewire and Flux orthogonal — the test suite asserts on the data-side event name only (`chain-drawer:open`), so a future replacement of Flux with another flyout library leaves the data contract untouched."
  - "`$chainAvailable = true` always in TransactionDetail::render() — the prior conditional rendering (only show the button when at least one chain_link exists for the user) added a per-page-render `ChainLinkQuery::forTransaction` call that touches at least 2 tables (`transactions` + `chain_links`). Since the drawer itself renders the empty state copy (\"No funding chain found\") cleanly when no chain exists, gating the button is overkill. The user gets a consistent affordance on every detail page; the drawer is calm enough that opening it on an empty chain is not a footgun."
  - "Single-link bulk-settle is NOT a fan-out — a node is treated as a fan-out parent only when it has ≥2 outgoing `ics_bulk_settle` chain_links. A single ICS charge settling via a 1-charge bulk-iDEAL transfer (rare but legitimate; e.g. a refund-only month) renders as a normal flat hop in the waterfall, not a fan-out container with a single child + no pagination button. This keeps the drawer's visual hierarchy honest: fan-outs imply 'this leg covers many things', so the container should be visually distinct only when there ARE many things to cover."
  - "Fan-out children are SUPPRESSED from the top-level waterfall — once a node renders as a fan-out parent, its children are emitted INSIDE the partial's nested list. The top-level waterfall's `foreach ($tree->nodes as $node)` skips any node id that appears in any fan-out parent's children set. This prevents the same ICS charge from rendering twice (once at depth N inside its bulk-settle's fan-out container; once at depth N+1 as a standalone waterfall leg). The duplication would be especially confusing during the user's first sighting of the drawer — UI-SPEC § Chain drill-down drawer explicitly forbids it."
  - "ChainDrawer DI shape — `render(CurrentUser $currentUser, ChainLinkQuery $query, DatabaseManager $db, ViewFactory $views)`. Three collaborators arriving as render() parameters (Livewire 4 supports DI on render() / mount() / action methods). DatabaseManager arrives here ONLY for the fan-out child reconstruction pass — a future move to a dedicated `ChainFanoutQuery` Public service would let ChainDrawer drop the DatabaseManager DI entirely. Acceptable to defer (one extra DI parameter is cheaper than a one-method service that nothing else consumes today)."
  - "Top-level wrapper `<div>` around `<flux:modal>` — Livewire requires every component render to return a single root element. `<flux:modal>` compiles to a `<div data-flux-modal ...>` so wrapping it in another `<div>` is structurally redundant but doctrinally cheap. Removing the wrapper means `<flux:modal>` becomes the root and any future sibling element (a toast, a notification) inside the SFC's render would break the single-root contract. Keeping the wrapper costs ~25 bytes of HTML and one extra DOM node."
  - "Removed the prior executor's `file_put_contents('/tmp/drawer.html', ...)` debug line from `tests/Feature/ChainDrawerTest.php` before commit. The line was a leftover from the prior session's iteration on the empty-fan-out test case; leaving it in would write a stale-rendered HTML snapshot to /tmp on every test run, which is both a noise-on-disk artifact and a CI-environment surprise (tmp may be ephemeral on some runners)."

metrics:
  duration: ~3min (resume only — RED commit e3215a9 already in place from prior session)
  completed: 2026-05-16

threat_register:
  T-05-05-01:
    category: Information Disclosure
    component: Chain drawer renders cross-user transaction data
    disposition: mitigate
    mitigation: "Every chain_links + transactions + accounts read in `ChainDrawer::attachFanoutChildren()` and `ChainLinkQuery::forTransaction()` filters on `user_id = $user->id`. Cross-user opens throw `NotFoundHttpException` at the root-transaction read."
  T-05-05-02:
    category: Cross-Site Scripting
    component: counterparty_name rendered in Blade
    disposition: mitigate
    mitigation: "Every interpolation in `chain-drawer.blade.php` and `chain-node.blade.php` uses Blade's default-escaping `{{ }}` syntax. No `{!! !!}` raw-HTML interpolation anywhere in the drawer or the partial."
  T-05-05-03:
    category: Cross-Site Request Forgery
    component: Confirm/Reject buttons submit state-changing actions
    disposition: mitigate
    mitigation: "Livewire 4 ships CSRF tokens automatically via the `/livewire/update` endpoint (Laravel session-bound). The drawer's Confirm/Reject `wire:click` calls go through the standard Livewire request pipeline."
  T-05-05-04:
    category: Tampering
    component: Implicit-scope partial inherits unexpected variables (issue #13 root cause)
    disposition: mitigate
    mitigation: "`@props(['node', 'fanoutPage'])` declared at the top of `chain-node.blade.php`. Every `@include` call passes BOTH values explicitly. ChainDrawerTest contains a grep-style regression gate for both the props declaration AND the explicit `@include` array — a future refactor that drops either side trips the test immediately."
  T-05-05-05:
    category: Tampering
    component: Optimistic flip on Confirm/Reject leaves the UI out of sync if the server roundtrip fails
    disposition: accept
    mitigation: "Out of scope for Wave 4 — the optimistic-flip + 12px text-rose-600 error flash UX is a 05-05b enhancement (the review queue inherits the same pattern). The current GREEN implementation lets Livewire's default request lifecycle re-render the drawer on every action; a failed action leaves the chip in its prior state with no inline error, which is calm-default behaviour rather than a misleading optimistic state."

# Verification
verification:
  tests:
    new: 0   # All 12 RED tests already existed in commit e3215a9
    modified: 1   # Removed debug file_put_contents line from one case in ChainDrawerTest.php
    passing: 12
    file: Modules/Chains/tests/Feature/ChainDrawerTest.php
  static_analysis: "Larastan level 10 strict — 197/197 files analysed, 0 errors"
  format: "Pint — clean"
  arch: "BoundaryArchTest — 13/13 invariants green"
---

# Phase 5 Plan 5: Chain Drawer Flux Flyout Summary

**One-liner:** Wave 4 drawer half — shipped `ChainDrawer` Livewire SFC behind a `<flux:modal flyout>` (project's first Flux flyout) that renders the BFS chain tree as a vertical waterfall, with three-tier confidence chips (D-91), inline Confirm/Reject chips on candidate legs, and ICS bulk-settle fan-out paginated at 10 children per click (D-93).

## What Shipped

The chain drill-down drawer (UI-02 + CHN-04 — both requirements close after Wave 4 + Wave 4 review-queue half 05-05b lands the open-candidate-count badge). After this plan, a user can click any transaction on `/transactions/{id}` → click "View chain" → see the full funding chain as a vertical waterfall in a right-side Flux flyout, with each leg showing its confidence tier, inline Confirm/Reject buttons on candidates, and a paginated fan-out container for ICS bulk-iDEAL settlements that cover many charges.

### ChainDrawer Livewire SFC

| Method | Role |
| --- | --- |
| `#[On('chain-drawer:open')] open(int $transactionId)` | Set `$transactionId`; reset `$fanoutPage` / `$expandedFanoutId` / `$collapsedLegs` so re-opening on a different transaction starts clean |
| `confirm(int $chainLinkId, CurrentUser, ConfirmChainLink)` | Delegate to the Wave 3 Public action — same code path the `/chains/review` queue uses |
| `reject(int $chainLinkId, CurrentUser, RejectChainLink)` | Delegate to the Wave 3 Public action — per-pair only (D-89) |
| `showMoreFanout()` | Increment `$fanoutPage` (forward-only, no collapse-back) |
| `toggleLeg(int $chainLinkId)` | Per-leg collapse state (D-92) — wired into `$collapsedLegs` array for a future expand/collapse affordance; rendering is fully-expanded by default per D-92 |
| `render(CurrentUser, ChainLinkQuery, DatabaseManager, ViewFactory)` | Pull the tree via `ChainLinkQuery::forTransaction`; re-emit with `ChainTreeNode.children` populated for every node that has ≥2 outgoing `ics_bulk_settle` legs |

### Blade view inventory

| File | Role |
| --- | --- |
| `chain-drawer.blade.php` | `<flux:modal flyout>` shell + sticky header (`sticky top-0 bg-white z-10`) + four-state render (pre-mount / no chain / root-only / waterfall). Passes `['node' => $node, 'fanoutPage' => $fanoutPage]` to every `@include` call (issue #13 fix). |
| `partials/chain-node.blade.php` | `@props(['node', 'fanoutPage'])` at top (issue #13 fix). Three-tier confidence chip (D-91, no hue encoding). Inline Confirm + Reject chips on candidates. ICS bulk-settle fan-out container with "Show 10 more · X of N" pagination + empty-fan-out copy. |

### Issue #13 fix — explicit @props pattern

The partial declares `@props(['node', 'fanoutPage'])` at its top AND every `@include` call passes both values explicitly. The two-sided contract is grep-verifiable: ChainDrawerTest contains a regression gate (`expect(file_get_contents(...))->toContain("@props(['node', 'fanoutPage'])")`) that trips immediately if a future refactor drops the declaration or stops passing `fanoutPage` through the `@include` array.

### Flux flyout API quirks discovered during implementation

- **Two-event open contract** — the trigger button has to dispatch BOTH `chain-drawer:open` (consumed by Livewire) AND `modal-show { name: 'chain-drawer-{N}' }` (consumed by Flux). The Livewire test path (`Livewire::test(...)->call('open', $txId)`) does NOT need to also dispatch `modal-show` — Flux's open state is presentation-only and doesn't affect the rendered HTML's content; the test asserts on the data-side render output. The `name` attribute is parameterised with the root transaction id so the same SFC instance can host any transaction's drawer (no per-id Livewire components).
- **Single-root requirement** — Livewire's render-must-return-single-root-element rule means the drawer wraps `<flux:modal>` in a top-level `<div>`. Removing the wrapper means `<flux:modal>` becomes the root and any future sibling element inside the SFC's render() breaks single-root. The 25-byte cost is worth the doctrinal safety.
- **Sticky header** — `<flux:heading>` with `class="sticky top-0 bg-white z-10 pb-3 -mx-6 px-6"` produces the intended sticky-on-scroll behaviour. The `-mx-6 px-6` pair extends the bg-white background across the flyout's horizontal padding so the heading visually attaches to the flyout edge during scroll.
- **The `data-flux-modal` attribute is the test signal** — the `assertSeeHtml('data-flux-modal')` smoke test confirms the Flux modal markup compiled out; it's the most stable invariant since Flux's internal class names are subject to change.

### Three-tier confidence chip (D-91)

| Tier | Trigger | Chip text | Chip chrome | Aria label |
| --- | --- | --- | --- | --- |
| Deterministic | `state='confirmed' AND resolver='auto' AND confidence=1.0` | `Deterministic` | `bg-white text-slate-900 ring-1 ring-slate-200` | "Confidence: deterministic match" |
| Confirmed | `state='confirmed'` (any other resolver / confidence) | `Confirmed` | `bg-slate-50 text-slate-900 ring-1 ring-slate-200` | "Confidence: confirmed" |
| Candidate | `state='candidate'` | `Candidate` | `bg-slate-50 text-slate-500 ring-1 ring-slate-200` | "Confidence: candidate; needs review" |

No hue encoding (UI-SPEC § Color forbids it). Candidate cards also carry `opacity-60` for the calm "dimmed" treatment.

### Fan-out pagination (D-93)

The ICS bulk-settle fan-out container renders inside the settlement leg's card with up to 10 covered ICS charges. When more children exist, a "Show {nextChunk} more · {visibleCount} of {totalCount}" button (e.g. "Show 10 more · 10 of 23") triggers `showMoreFanout()` which increments `$fanoutPage`. Pagination is forward-only — there is no collapse-back affordance.

The empty-fan-out edge case (a refund-only month where the bulk-settle covers zero ICS charges) renders the locked copy "No ICS charges in this settlement" per UI-SPEC § Copywriting.

## Deviations from Plan

### Rule 1 (Auto-fix bug) — Removed leftover debug file_put_contents

**Found during:** post-RED-commit code review while resuming this plan.

**Issue:** The previous executor (which crashed on a Claude API 500 mid-GREEN) left a `file_put_contents('/tmp/drawer.html', $component->html());` debug line in the `empty-fan-out` test case of `tests/Feature/ChainDrawerTest.php`. The line was a one-time iteration aid that would write a stale-rendered HTML snapshot to `/tmp` on every test run, creating a noise-on-disk artifact and a CI-environment surprise.

**Fix:** Restored the test case to its plan-specified shape (`Livewire::actingAs(...)->test(...)->call('open', $rootCharge->id)->assertSee('No ICS charges in this settlement')`). All 12 tests still pass; the gate on the debug line was incidental, not intentional.

**Files modified:** `Modules/Chains/tests/Feature/ChainDrawerTest.php`

**Commit:** Folded into the GREEN feat commit `0e6d1a2` since the test file was modified in the RED commit `e3215a9` and the debug line was a leftover, not an intentional RED artifact.

### Rule 2 (Auto-add missing critical functionality) — Larastan strict-mode coercion helpers

**Found during:** Task 1 verification (`composer analyse`).

**Issue:** Initial GREEN implementation of `ChainDrawer::attachFanoutChildren()` + `makeChildNode()` used raw `(int) $row->id` / `(string) $row->counterparty_name` casts on `stdClass` rows returned by query-builder `->get()`. Larastan level 10 strict's `cast.int` + `cast.useless` rules flagged 10 errors because `mixed` cannot be cast to `int` without a numeric guard, and casting `(string) $x` where `is_string($x)` was already true is a useless cast.

**Fix:** Added private static `toInt(mixed)` / `toString(mixed)` helpers on `ChainDrawer` (mirroring the same helpers on `ChainLinkQuery`). Each helper does the strict-rules-friendly narrowing inline (`is_numeric($value) ? (int) $value : 0` / `is_string($value) ? $value : (is_scalar($value) ? (string) $value : '')`). Re-ran `composer analyse` → 0 errors across 197 files.

**Files modified:** `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php`

**Commit:** Folded into the GREEN feat commit `0e6d1a2` since the helpers landed alongside the initial SFC creation.

### Rule 2 (Auto-add missing critical functionality) — Pint formatting on the new SFC

**Found during:** Task 1 verification (`composer format:check`).

**Issue:** Initial GREEN SFC had `fully_qualified_strict_types` / `unary_operator_spaces` / `not_operator_with_successor_space` / `ordered_imports` violations. The biggest was the inline `\Modules\Core\Models\User $user` parameter type that should have been imported at the top of the file.

**Fix:** Added `use Modules\Core\Models\User;` to the imports + dropped the inline FQN. Re-ran `vendor/bin/pint Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` → clean.

**Files modified:** `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php`

**Commit:** Folded into the GREEN feat commit `0e6d1a2`.

## Authentication Gates

None — this plan is server-side Livewire SFC + Blade work; no external auth surface (Gmail / Microsoft / etc.) touched.

## Verification Results

- `vendor/bin/pest --filter "ChainDrawerTest"` → **12/12 passed** (24 assertions; 0.51s)
- `vendor/bin/pest Modules/Chains/tests` → **101/101 passed** (376 assertions; 4.66s)
- `vendor/bin/pest --filter "BoundaryArchTest"` → **13/13 invariants green** (35 assertions; 2.88s)
- `composer analyse` → **Larastan level 10 strict: 0 errors across 197 analysed files**
- `composer format:check` → **Pint: clean**
- All 7 plan-defined grep gates pass:
  - `chain-drawer:open` in `transaction-detail.blade.php` ✓
  - `flux:modal` in `chain-drawer.blade.php` ✓
  - `@props` in `chain-node.blade.php` ✓
  - `'fanoutPage' => $fanoutPage` in `chain-drawer.blade.php` ✓
  - `#[On('chain-drawer:open')]` in `ChainDrawer.php` ✓

## Known Stubs

None. The "View chain" trigger is wired live to the drawer SFC; the drawer renders against live `chain_links` data via the Wave 3 `ChainLinkQuery::forTransaction` walker; Confirm + Reject chips invoke the Wave 3 Public action classes against the live `chain_links` table. The optimistic-flip + inline error-flash UX (T-05-05-05) is **deliberately deferred** to 05-05b's review queue work — the current drawer relies on Livewire's default request lifecycle to re-render the chip in its post-action state, which is calm-default behaviour rather than a misleading optimistic state.

## Self-Check: PASSED

- Files exist:
  - `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` ✓
  - `Modules/Chains/Resources/views/livewire/chain-drawer.blade.php` ✓
  - `Modules/Chains/Resources/views/livewire/partials/chain-node.blade.php` ✓
- Commits exist:
  - `e3215a9` (RED) ✓
  - `0e6d1a2` (GREEN) ✓
