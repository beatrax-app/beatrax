---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 05b
subsystem: chains
tags: [chain-review-queue, dashboard-tile, failed-job-toast, top-nav-badge, wizard-polling, view-factory-composer, chn-03, chn-06, ui-02, d-86, d-87, d-99, d-100, d-103, d-105, issue-1, issue-8, issue-12]

# Dependency graph
requires:
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 04
    provides: ChainLinkQuery::candidatesForReview + ChainLinkQuery::openCandidateCount + ConfirmChainLink + RejectChainLink Public actions; ChainLinkRow DTO
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 05
    provides: ChainDrawer Livewire SFC (companion drawer surface) — same Public action classes power both the drawer and the /chains/review queue per D-86 dual-surface
  - phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
    plan: 02
    provides: chain_links + card_statements + chain_resolution_runs schema; ChainLink/CardStatement/ChainResolutionRun Eloquent models; CardStatementForecastTile DTO
  - phase: 03-ics-cards-multi-currency-display
    provides: CardStatementQuery + ICS-CARD synthetic-IBAN Account model the dashboard tile reads through
provides:
  - "/chains/review route + ChainReviewQueue Livewire SFC + Blade — cursor-paginated batch review (confidence DESC, id DESC) with Confirm/Reject buttons and auto-promotion hint (D-87) at confirmsRemaining===1"
  - ThisPeriodAtAGlanceQuery::nextIcsSettlement(User) — returns CardStatementForecastTile when an open / partially_settled ics_card statement exists (D-99 / D-100); null otherwise (dashboard hides tile entirely)
  - Dashboard "Next ICS settlement" tile (Blade) + persistent failed-job toast backed by chain_resolution_runs audit table (issue #1 + #8 — replaces failed_jobs.payload LIKE substring)
  - Top-nav "Review chains" link with count badge (caps at 99+, hides at 0); badge fed by View Factory contract composer (issue #12 — DI-only invariant preserved)
  - PreviewWizard $chainResolutionStatus / $chainResolutionLinkedCount / $chainResolutionError state + refreshChainResolutionStatus action — polls chain_resolution_runs by exact user_id match; auto-navigates to imports.results on 'complete' (D-105)
  - Acceptance grep gates locked in two regression tests — "no view() global helper anywhere in Modules/Chains/" + "PreviewWizard does NOT contain a substring LIKE payload pattern"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "View Factory contract composer (issue #12 fix) — `$this->app->make(\\Illuminate\\Contracts\\View\\Factory::class)->composer('core::livewire.top-nav', static fn (View $compose) => ...)`. The composer fires when the top-nav Livewire view is rendered; it reads `ChainLinkQuery::openCandidateCount` and injects the integer as `$chainOpenCandidateCount`. The forbidden `view()` global helper never appears in production code (grep gate locked in `ChainReviewQueueTest::no view() global helper anywhere in Modules/Chains/`)."
    - "Audit-table polling — wizard + dashboard read `chain_resolution_runs WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1` (issue #1 + #8 fix). The earlier draft's `failed_jobs.payload LIKE '%userId:N%'` substring pattern is permanently excluded: user_id=1 vs user_id=11 false-positive is impossible against an exact-match column query. Two grep gates lock it (one in WizardChainResolutionStatusTest's file-content check, one in the deviation-aware grep over Modules/Chains/ + Modules/Core/ + Modules/Import/)."
    - "Dashboard wire:poll.5s — the failed-job toast polls every 5s (slower than the wizard's 2s because the dashboard is the long-lived surface). The toast is persistent (no auto-dismiss); it hides when the audit row is cleared (e.g. user retried via /horizon/failed)."
    - "Cursor pagination for /chains/review — `cursorId` + `cursorConfidence` (formatted to 3 decimal places) keep the sort stable when multiple rows share the same confidence value. `ChainLinkQuery::candidatesForReview` already returns 26 rows per page; the Blade renders 25 + a 'Show more' affordance that hands the 26th row's `(id, confidence)` back as the next cursor. (The current Blade renders 'Show more' once `count >= 25` — Phase 5 ships the cursor primitive; a future plan can hook pagination state into a `WithPagination`-style trait if needed.)"
    - "Layout extension via `$view->extends('layouts.app', ['title' => ...])` — same pattern as `Modules/Ledger/Internal/Http/Livewire/TransactionDetail::render()`. Lets `ChainReviewQueue` be wired directly as a `Route::get('/chains/review', ChainReviewQueue::class)` handler without a separate Blade wrapper; the Livewire SupportPageComponents macro produces the `@extends('layouts.app') @section('content')` envelope identical to every other diederik page."
    - "Livewire-test boundary for cross-user actions — `expect(fn () => Livewire::test(...)->call('confirm', ...)) ->toThrow(NotFoundHttpException::class)` does NOT work because Livewire's test harness catches framework exceptions and converts to a 404 response status. Two assertion shapes ship: (1) `assertStatus(404)` against the Livewire harness; (2) direct Public action invocation `expect(fn () => ($confirm)($linkId, $user))->toThrow(NotFoundHttpException::class)` for the deeper guarantee. The combination mirrors `ConfirmChainLinkTest`'s shape."
    - "Larastan-strict-mode container resolution — `$app->make(CurrentUser::class)` returns the concrete `CurrentUserService` per the container binding; PHPStan narrows the return type and rejects a `@var CurrentUser` annotation as a 'not-a-subtype' violation. Drop the `@var` and let the inferred type flow through; the interface methods exist on the concrete service so the actual call site stays type-safe."

key-files:
  created:
    - Modules/Chains/Routes/web.php
    - Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php
    - Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php
    - Modules/Chains/tests/Feature/ChainReviewQueueTest.php
    - Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php
    - Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php
    - Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/deferred-items.md
  modified:
    - Modules/Chains/Providers/ChainsServiceProvider.php
    - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
    - Modules/Core/Internal/Http/Livewire/Dashboard.php
    - Modules/Core/Resources/views/livewire/dashboard.blade.php
    - Modules/Core/Resources/views/livewire/top-nav.blade.php
    - Modules/Import/Internal/Http/Livewire/PreviewWizard.php
    - Modules/Import/Resources/views/livewire/preview-wizard.blade.php

key-decisions:
  - "Top-nav badge fed by View Factory composer on `core::livewire.top-nav` (NOT a parent partial like `layouts.partials.top-nav`). The top-nav is a Livewire SFC (`TopNav.php`) rendering its own view `core::livewire.top-nav`; the composer fires when that view is rendered. Subscribing to the actual rendered view (not a parent template) keeps the data dependency one-hop and lets the badge integer arrive alongside the existing `uncategorizedCount` and `userEmail` data the TopNav component supplies."
  - "Composer treats unauthenticated requests gracefully — `if (! $currentUser->isAuthenticated()) { $compose->with('chainOpenCandidateCount', 0); return; }`. The top-nav is rendered inside an `@auth` guard in `layouts/app.blade.php` so unauthenticated requests should not reach the composer, but the defensive branch keeps the composer harmless if a future change exposes the top-nav on a logout-success flash or similar surface."
  - "Dashboard.refreshFailedChainResolution exists alongside the initial `render()` populate so the toast surfaces immediately on initial load AND refreshes every 5s without a full server-side render of the dashboard. The initial-populate path keeps the toast visible on the first paint; the wire:poll keeps it accurate as the user lingers."
  - "`refreshChainResolutionStatus` reads ALL chain_resolution_runs rows for the user (not only `WHERE status IN (pending, running)`). The wizard surfaces failed states inline too (D-103 + the failed-job toast is a dashboard concern; the wizard inline-failed copy is its own concern). Reading the latest row regardless of status keeps the state machine renderable for any intermediate state the user might hit."
  - "Cross-user assertion shape — two test forms: (1) direct Public action invocation `expect(fn () => ($confirm)($linkId, $user))->toThrow(NotFoundHttpException)` (the deeper guarantee, mirrors ConfirmChainLinkTest); (2) `Livewire::test(...)->call('confirm', $foreignLinkId)->assertStatus(404)` (the harness-aware shape — Livewire converts the exception to a 404 response status, which is the right HTTP behaviour). Both shapes coexist in CrossUserChainLinkIsolationTest."
  - "Cursor pagination semantics — `loadMore($nextCursorId, $nextCursorConfidence)` advances the public cursor properties; the Blade renders the 'Show more' button only when `count($candidates) >= 25` (cursor's limit is 26; if 26 rows came back we know there's at least one more page). This is a minimal pagination primitive; a future enhancement can plug `WithPagination` if the queue grows substantially. The Phase 5 Goal SC#3 ('user can review fuzzy match candidates in a queue, confirm or reject each') is satisfied without a full pagination UX since the project is single-user and the queue at most carries dozens of pending candidates at a time."
  - "ChainReviewQueue.render() uses `$view->extends('layouts.app', ...)` — the same `@phpstan-ignore-next-line method.notFound` pattern TransactionDetail uses (the macro is registered at runtime by Livewire's SupportPageComponents feature so PHPStan can't see it statically). The annotation cost is one line and keeps the page-shell wiring consistent across every full-page Livewire SFC in the project."
  - "Larastan @var annotation conflict — `$app->make(CurrentUser::class)` returns the concrete CurrentUserService per the container binding; PHPStan strict mode rejects a `@var CurrentUser $currentUser` annotation as a 'not-a-subtype' violation. Dropping the annotation lets the inferred type flow through (the interface methods exist on the concrete service so the call site stays type-safe). Same fix applied to the `ChainLinkQuery` resolution in the composer."

metrics:
  duration: ~13 minutes
  completed: 2026-05-16

threat_register:
  T-05-05b-01:
    category: Elevation of Privilege
    component: Cross-user chain_link confirm/reject via /chains/review
    disposition: mitigate
    mitigation: "ConfirmChainLink + RejectChainLink Public actions filter on (id, user_id) and raise NotFoundHttpException when no row matches. Verified by CrossUserChainLinkIsolationTest in two shapes (direct action invocation + Livewire harness assertStatus(404)). The candidate list from ChainLinkQuery::candidatesForReview is itself filtered by user_id, so userA's UI never displays a userB chain_link id to act on; the defensive 404 fires only when a forged wire request supplies a foreign id."
  T-05-05b-02:
    category: Information Disclosure
    component: failed_jobs payload LIKE leak (historic issue #1 + #8)
    disposition: mitigate
    mitigation: "Wizard + dashboard read chain_resolution_runs WHERE user_id = ? — exact-match column query. The forbidden substring `payload LIKE '%userId:N%'` would conflate user_id=1 with user_id=11 (id-prefix false-positive). Two regression gates lock the contract: (a) `PreviewWizard does NOT contain a substring LIKE payload pattern` (file-content grep over the source), (b) `substring-attack guard — user_id matching is exact, not LIKE (issue #8)` (runtime exercise with two users)."
  T-05-05b-03:
    category: Elevation of Privilege
    component: view() global helper bypassing DI-only invariant (issue #12)
    disposition: mitigate
    mitigation: "ChainsServiceProvider resolves the View Factory contract via `$this->app->make(\\Illuminate\\Contracts\\View\\Factory::class)` and calls `->composer(...)` on the resolved instance. The `view()` global helper never appears in production code under Modules/Chains. Locked by ChainReviewQueueTest's `no view() global helper anywhere in Modules/Chains/ production code` regression test."
  T-05-05b-04:
    category: Cross-Site Scripting
    component: counterparty_name + last_error rendered in Blade
    disposition: mitigate
    mitigation: "Every Blade interpolation in `chain-review-queue.blade.php`, the dashboard tile section, the failed-job toast, and the preview-wizard polling section uses the default-escaping `{{ }}` syntax. No `{!! !!}` raw-HTML interpolation anywhere. The toast's `Open Horizon` link is a static href, not a user-supplied string."
  T-05-05b-05:
    category: Denial of Service
    component: wire:poll loops on long-lived dashboard / wizard tabs
    disposition: accept
    mitigation: "Single-user, single-tab usage per FND-01. The dashboard polls every 5s; the wizard polls every 2s only while a chain-resolution status is non-null and non-complete. No timeout cap on the wizard polling — long resolver jobs are acceptable; the user sees the status surface until completion."
  T-05-05b-06:
    category: Cross-Site Request Forgery
    component: Confirm/Reject buttons + wizard polling submit state-changing actions
    disposition: mitigate
    mitigation: "Livewire 4 ships CSRF tokens automatically via the /livewire/update endpoint (Laravel session-bound). All wire:click + wire:poll calls go through the standard Livewire request pipeline."

# Verification
verification:
  tests:
    new: 38   # 13 ChainReviewQueueTest + 9 NextIcsSettlementTileTest + 8 CrossUserChainLinkIsolationTest + 8 WizardChainResolutionStatusTest
    modified: 0
    passing: 38
    files:
      - Modules/Chains/tests/Feature/ChainReviewQueueTest.php
      - Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php
      - Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php
      - Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php
  static_analysis: "Larastan level 10 strict — 199/199 files analysed, 0 errors"
  format: "Pint — clean"
  arch: "BoundaryArchTest — 13/13 invariants green (no Modules/Chains/Internal namespace breach; no Eloquent transactions table mutation in resolvers; no card_statements.state mutation outside CardStatementStateMachine)"
---

# Phase 5 Plan 5b: Review Queue + Dashboard Tile + Wizard Polling + Failed-Job Toast Summary

**One-liner:** Wave 4 (UI half) — `/chains/review` page + dashboard "Next ICS settlement" tile + persistent failed-job toast + top-nav "Review chains" badge + wizard chain-resolution polling, all rooted in the `chain_resolution_runs` audit table (issue #1 + #8) and fed via explicit View Factory contract bindings (issue #12).

## What Shipped

The remaining Phase 5 UI surfaces. Combined with `05-05` (the chain drawer Flux flyout), the entire Phase 5 surface is now functional end-to-end. A user can:

1. **Review pending candidates** at `/chains/review` — a calm batched list sorted by confidence DESC then id DESC, with per-row Confirm + Reject buttons and an inline auto-promotion hint when one more confirmation would trigger the D-87 learning loop.
2. **See the next forecasted ICS settlement** on the dashboard — a single calm tile rendering the open balance + an approximate due date (`due ~20 May`), hidden entirely when no open card_statement exists.
3. **Know when chain resolution failed** — a persistent failed-job toast surfaces in the bottom-right of the dashboard when any `chain_resolution_runs.status='failed'` row exists for the user; links to `/horizon/failed` for retry / inspect.
4. **Navigate to the review queue** from any page — a top-nav "Review chains" link gains a slate-900 numeric badge with the open-candidate count (caps at "99+", hides at 0).
5. **Track post-import chain resolution progress** in the wizard — `wire:poll.2s` surfaces pending → running → complete state; on complete, the wizard auto-navigates to the import summary.

### ChainReviewQueue Livewire SFC

| Method | Role |
| --- | --- |
| `confirm(int $chainLinkId, CurrentUser, ConfirmChainLink)` | Delegate to the Wave 3 Public action — same code path the chain drawer uses (D-86 dual-surface) |
| `reject(int $chainLinkId, CurrentUser, RejectChainLink)` | Delegate to the Wave 3 Public action — per-pair only (D-89) |
| `loadMore(int $nextCursorId, ?string $nextCursorConfidence)` | Advance the cursor for cursor-paginated review queue |
| `render(CurrentUser, ChainLinkQuery, ViewFactory)` | Pull candidates via `ChainLinkQuery::candidatesForReview`, extend `layouts.app`, return the chain-review-queue Blade |

### View Factory composer (issue #12)

Registered in `ChainsServiceProvider::boot()` via `$this->app->make(\Illuminate\Contracts\View\Factory::class)->composer('core::livewire.top-nav', $composeFn)`. The composer runs when the `core::livewire.top-nav` view is rendered (via the existing `TopNav` Livewire SFC); it reads `ChainLinkQuery::openCandidateCount($currentUser->user())` and injects the integer as `$chainOpenCandidateCount`. Defensive `isAuthenticated()` branch keeps the composer harmless on unauthenticated surfaces.

The `view()` global helper does NOT appear in any Modules/Chains production code path. Locked by the `no view() global helper anywhere in Modules/Chains/ production code` regression test, which iterates every PHP file under `Modules/Chains/` (excluding `/tests/`) and strips comments before applying `\bview\(\)` regex.

### Audit-table polling (issue #1 + #8)

`PreviewWizard::refreshChainResolutionStatus` reads `chain_resolution_runs WHERE user_id = $user->id ORDER BY id DESC LIMIT 1` (exact user_id match). On status === 'complete', the wizard redirects to `imports.results`.

The dashboard's `refreshFailedChainResolution` reads `chain_resolution_runs WHERE user_id = $user->id AND status = 'failed' EXISTS` — the toast surfaces when the predicate returns true, hides on the next poll when the audit row is cleared.

The `failed_jobs.payload LIKE '%userId:N%'` substring pattern is permanently excluded. The two regression gates:

1. `WizardChainResolutionStatusTest::PreviewWizard does NOT contain a substring LIKE payload pattern (issue #1 + #8 lock)` — file-content grep over `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`.
2. `WizardChainResolutionStatusTest::substring-attack guard — user_id matching is exact, not LIKE (issue #8)` — runtime exercise with two users where the second user's failed row must not surface for the first user.

### Top-nav "Review chains" badge

Inserted between "Uncategorized" and "Settings" in `top-nav.blade.php`. Numeric badge with `bg-slate-900 text-white rounded-full`. Caps at "99+" when the count exceeds 99; the badge `<span>` does not render when the count is 0 (the link itself stays visible).

### Dashboard tile + failed-job toast

- **Next ICS settlement tile** rendered ABOVE the existing in/out/net + per-currency tile rows; matches the existing tile chrome verbatim (`rounded-lg border border-slate-200 bg-white p-6`). Hides entirely when `$nextSettlement === null` — no "—" placeholder.
- **Failed-job toast** fixed bottom-right, `z-50`, with a 2px rose-600 left stripe and an `Open Horizon` link. Persistent; clears on the next `wire:poll.5s` tick once the audit row is deleted.

## Deviations from Plan

### Rule 1 (Auto-fix bug) — Livewire test harness catches NotFoundHttpException

**Found during:** First run of `CrossUserChainLinkIsolationTest` after writing the GREEN code.

**Issue:** The plan suggested `expect(fn () => Livewire::actingAs(...)->test(...)->call('confirm', $foreignId))->toThrow(NotFoundHttpException::class)`. In practice, Livewire's test harness catches framework exceptions and converts them into a 404 response status on the wire response — the exception never propagates back to the `toThrow` assertion.

**Fix:** Two test shapes coexist in `CrossUserChainLinkIsolationTest`:
1. Direct Public action invocation: `expect(fn () => ($confirm)($linkId, $user))->toThrow(NotFoundHttpException::class)` — the deeper guarantee, mirrors `ConfirmChainLinkTest`'s cross-user assertion.
2. Livewire harness shape: `Livewire::test(...)->call('confirm', $linkId)->assertStatus(404)` — the HTTP-layer surface, validating that the Livewire test harness's exception conversion produces the correct response code.

**Files modified:** `Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php`

**Commit:** Folded into the GREEN feat commit `b7efef9`.

### Rule 1 (Auto-fix bug) — Larastan @var CurrentUser annotation conflict

**Found during:** `composer analyse` after writing the View Factory composer.

**Issue:** `$this->app->make(CurrentUser::class)` resolves to the concrete `CurrentUserService` per the container binding; Larastan strict mode then rejects a `@var CurrentUser $currentUser` annotation with `varTag.type — PHPDoc tag @var with type Modules\Core\Public\Contracts\CurrentUser is not subtype of type Modules\Core\Public\Services\CurrentUserService`.

**Fix:** Drop the `@var` annotation. The inferred concrete type from `$app->make()` flows through, and every interface method (`isAuthenticated()`, `user()`) exists on the concrete service so the call site stays type-safe. Same fix applied to the `ChainLinkQuery` resolution.

**Files modified:** `Modules/Chains/Providers/ChainsServiceProvider.php`

**Commit:** Folded into the GREEN feat commit `b7efef9`.

### Rule 2 (Auto-add missing critical functionality) — Layout extension on the page-handler Livewire SFC

**Found during:** First run of `ChainReviewQueueTest::GET /chains/review for an authenticated user renders the page with candidates`.

**Issue:** Wiring `ChainReviewQueue` directly as a `Route::get('/chains/review', ChainReviewQueue::class)` handler renders the Livewire component WITHOUT the `layouts.app` page shell — the response is just the inner `<div>` content without `<!doctype html>`, top-nav, etc. The test's `assertSeeText('Review chains')` failed because the rendered content was a bare component snippet wrapped in the framework's default page envelope.

**Fix:** Added `$view->extends('layouts.app', ['title' => 'Review chains · diederik'])` to `ChainReviewQueue::render()` — same pattern `Modules/Ledger/Internal/Http/Livewire/TransactionDetail::render()` uses. The macro is registered at runtime by Livewire's SupportPageComponents feature; PHPStan can't see it statically so a `@phpstan-ignore-next-line method.notFound` annotation is required.

**Files modified:** `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php`

**Commit:** Folded into the GREEN feat commit `b7efef9`.

## Authentication Gates

None — this plan is server-side Livewire SFC + Blade work. No external auth surface (Gmail / Microsoft / etc.) touched.

## Verification Results

- `vendor/bin/pest --filter "ChainReviewQueueTest"` → **13/13 passed**
- `vendor/bin/pest --filter "NextIcsSettlementTileTest"` → **9/9 passed**
- `vendor/bin/pest --filter "CrossUserChainLinkIsolationTest"` → **8/8 passed**
- `vendor/bin/pest --filter "WizardChainResolutionStatusTest"` → **8/8 passed**
- `vendor/bin/pest Modules/Chains/tests` → **131/131 passed** (no regressions in earlier Wave 1-3 + 05-05 tests)
- `vendor/bin/pest Modules/Core/tests` → **23/23 passed**
- `vendor/bin/pest --filter "BoundaryArchTest"` → **13/13 invariants green**
- `composer analyse` → **Larastan level 10 strict: 199/199 files analysed, 0 errors**
- `composer format:check` → **Pint: clean**

### Acceptance Grep Gates

1. **Issue #12 lock** — `grep -RIn 'view()' Modules/Chains/ | grep -v _test\|Test\.php` produces zero matches in production code. The only hits are inside PHPDoc comments (referring to the forbidden helper) and inside method names like `candidatesForReview()` / `forTransaction()`.
2. **Issue #1 + #8 lock** — `grep -RIn 'payload.*like.*userId' Modules/Chains/ Modules/Core/ Modules/Import/` returns zero hits in production code. The only match is in `WizardChainResolutionStatusTest` (the regression test that asserts the pattern is absent).
3. **Route registered** — `/chains/review` resolves through the `chains.review` named route in `Modules/Chains/Routes/web.php`.
4. **ViewFactoryContract in provider** — `ChainsServiceProvider::boot()` calls `$this->app->make(ViewFactoryContract::class)->composer('core::livewire.top-nav', ...)`.
5. **chain_resolution_runs in wizard** — `Modules/Import/Internal/Http/Livewire/PreviewWizard.php::refreshChainResolutionStatus` reads `chain_resolution_runs` filtered by `user_id`.

## Phase 5 Success Criteria Status

Combined with `05-05` (drawer), the entire Phase 5 UI surface is now functional end-to-end:

- **SC#1** — User opens a Netflix-via-PayPal transaction and sees the full chain tree back to the ASN or ICS account that ultimately funded it → ✓ delivered by 05-05 (chain drawer) + Wave 2/3 (resolvers).
- **SC#2** — User sees the monthly ASN → ICS iDEAL debit decomposed into the underlying ICS card transactions it settles, with tolerance handling → ✓ delivered by Wave 2 (IcsSettlementResolver) + 05-05 (drawer fan-out).
- **SC#3** — User can review fuzzy match candidates in a queue, confirm or reject each, and confirmed patterns auto-promote similar future candidates → ✓ delivered by THIS plan (`/chains/review` page) + Wave 3 (ConfirmChainLink auto-promotion learning loop).
- **SC#4** — User sees the next forecasted ICS settlement amount before paying it → ✓ delivered by THIS plan (dashboard "Next ICS settlement" tile via `ThisPeriodAtAGlanceQuery::nextIcsSettlement`).

The operator-facing manual UI verification (Task 3 of this plan) is deferred to phase-level verification per the orchestrator pattern; the plan completes here with all 38 new tests green, all acceptance grep gates locked, and Larastan + Pint clean.

## Known Stubs

None — every UI surface this plan ships is wired live:

- `/chains/review` Confirm/Reject buttons invoke the live `ConfirmChainLink` / `RejectChainLink` Public actions against the live `chain_links` table.
- The dashboard tile reads live `card_statements` via the extended `ThisPeriodAtAGlanceQuery::nextIcsSettlement`.
- The failed-job toast reads live `chain_resolution_runs` rows via `Dashboard::refreshFailedChainResolution`.
- The top-nav badge reads live `ChainLinkQuery::openCandidateCount` via the View Factory composer.
- The wizard's polling reads live `chain_resolution_runs` rows via `PreviewWizard::refreshChainResolutionStatus`.

## Deferred Issues

**TransactionTypeTest** (`Modules/Ledger/tests/Unit/TransactionTypeTest.php:74`) — Pre-existing failure, verified identical against `HEAD~3` before any 05-05b changes. Logged to `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/deferred-items.md`. Recommended owner: a separate Ledger plan that audits the `transactions.type` BEFORE-INSERT trigger pair shipped by Phase 1 migration.

## Self-Check: PASSED

- Files exist:
  - `Modules/Chains/Routes/web.php` ✓
  - `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` ✓
  - `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php` ✓
  - `Modules/Chains/tests/Feature/ChainReviewQueueTest.php` ✓
  - `Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php` ✓
  - `Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php` ✓
  - `Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php` ✓
- Commits exist:
  - `82254e0` (RED — review queue + dashboard tile + cross-user isolation tests) ✓
  - `b7efef9` (GREEN — review queue + dashboard tile + failed-job toast + top-nav badge) ✓
  - `75ebef4` (RED — wizard polling tests) ✓
  - `0e50b52` (GREEN — wizard polling implementation) ✓

## Threat Flags

None — every new file (route, Livewire SFC, Blade view, polling action) operates entirely behind the `auth` middleware on `127.0.0.1`. No new network endpoint, no new auth path, no new file-access pattern, no new schema. The threat register above documents all six STRIDE categories with mitigations and runtime tests.
