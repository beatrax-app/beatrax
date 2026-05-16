---
phase: 05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition
plan: 05b
type: execute
wave: 4
depends_on: ["05-01", "05-01b", "05-02", "05-03", "05-04", "05-05"]
files_modified:
  - Modules/Chains/Routes/web.php
  - Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php
  - Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php
  - Modules/Chains/Providers/ChainsServiceProvider.php
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/Import/Internal/Http/Livewire/PreviewWizard.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Chains/tests/Feature/ChainReviewQueueTest.php
  - Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php
  - Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php
  - Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php
autonomous: false
requirements:
  - CHN-03
  - CHN-06
  - UI-02

must_haves:
  truths:
    - "/chains/review page renders the user's open candidates sorted by confidence DESC, then updated_at DESC (D-86), with Confirm and Reject buttons per row"
    - "Dashboard tile 'Next ICS settlement' renders amount + due-date when ThisPeriodAtAGlanceQuery::nextIcsSettlement returns non-null; hides entirely when null (D-99)"
    - "Wizard 'Resolving chains…' status surface polls /chain_resolution_status via wire:poll.2s (D-105) and auto-navigates to import-results on 'complete'"
    - "Wizard polling reads chain_resolution_runs audit table by user_id (latest row) — never failed_jobs.payload LIKE '%userId:N%' substring match (issue #1 + #8 fix)"
    - "Failed-job toast renders via dashboard wire:poll.5s against chain_resolution_runs.status='failed' for the user; persistent (no auto-dismiss); links to /horizon/failed (D-103)"
    - "Top-nav 'Review chains' link with count badge inserted between Uncategorized and Settings; badge hides when openCandidateCount = 0; caps at 99+ (UI-SPEC)"
    - "Top-nav badge fed via explicit View Factory binding (issue #12 fix) — NEVER view()->composer global helper; ChainsServiceProvider::boot uses $this->app->make(View Factory::class)->composer(...)"
    - "ConfirmChainLink + RejectChainLink dispatched from the /chains/review page (same Public action class powers both drawer and review-queue per D-86 dual-surface)"
    - "Cross-user 404 verified at HTTP layer for /chains/review surfaces (per Phase 4 cross-user safety precedent)"
    - "**D-100:** Forecast amount = `open_balance_minor` of most-recent card_statement in (open, partially_settled); 5-day forecast lag from period_end — see 05-CONTEXT.md `<decisions>` for full text"
  artifacts:
    - path: Modules/Chains/Routes/web.php
      provides: "/chains/review route"
      contains: "chains.review"
    - path: Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php
      provides: "Livewire SFC for /chains/review page (CHN-03 dedicated surface)"
      exports: ["ChainReviewQueue"]
    - path: Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php
      provides: "Review queue Blade with Confirm/Reject buttons + auto-promotion hint"
      contains: "wire:click"
  key_links:
    - from: Modules/Core/Resources/views/livewire/dashboard.blade.php
      to: Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery::nextIcsSettlement
      via: "@if ($nextSettlement) ... @endif Blade conditional"
      pattern: "nextIcsSettlement"
    - from: Modules/Core/Resources/views/livewire/top-nav.blade.php
      to: Modules/Chains/Public/Services/ChainLinkQuery::openCandidateCount
      via: "View Factory composer registered explicitly via $this->app->make() — NOT view() global helper (issue #12)"
      pattern: "openCandidateCount"
    - from: Modules/Import/Resources/views/livewire/preview-wizard.blade.php
      to: chain_resolution_runs table
      via: "wire:poll.2s='refreshChainResolutionStatus' — reads chain_resolution_runs by user_id (issue #1 + #8)"
      pattern: "refreshChainResolutionStatus"
---

<objective>
Wave 4 (review queue + dashboard + wizard half): Ship the remaining Phase 5 UI surfaces — `/chains/review` page, dashboard "Next ICS settlement" tile, top-nav "Review chains" badge, wizard "Resolving chains…" polling, and failed-job toast — all backed by the `chain_resolution_runs` audit table (issue #1 + #8) instead of the unsafe `failed_jobs.payload` LIKE substring match.

This is the companion to `05-05` (chain drawer Flux flyout). Split per issue #6 (19 files in old 05-05 exceeded the 15-file warning threshold).

Purpose: After this plan AND `05-05` (drawer), the entire Phase 5 surface is functional end-to-end. The wizard polling is rooted in an explicit audit table (issue #1 + #8) so cross-user data does not leak via substring matching on serialized payloads. The top-nav badge is fed by explicit View Factory binding (issue #12) so the DI-only invariant holds.

Output:
- `Modules/Chains/Routes/web.php` with `/chains/review` route.
- `ChainReviewQueue` Livewire SFC + Blade view + chain-review-queue partials.
- Extensions to `ThisPeriodAtAGlanceQuery` (add `nextIcsSettlement()` method).
- Extensions to `Dashboard` Livewire SFC + Blade (add tile + failed-job toast).
- Extensions to `top-nav.blade.php` (add Review chains link with badge).
- Extensions to `PreviewWizard` (add wizard polling status backed by chain_resolution_runs).
- Tests: ChainReviewQueueTest (Livewire), NextIcsSettlementTileTest (DTO + Blade conditional), CrossUserChainLinkIsolationTest (HTTP-layer cross-user 404 on /chains/review), WizardChainResolutionStatusTest (wizard polling against chain_resolution_runs).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-RESEARCH.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-UI-SPEC.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-04-PLAN.md
@.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-05-PLAN.md

# Livewire SFC analogs:
@Modules/Categorization/Internal/Http/Livewire/TriageInbox.php
@Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php
@Modules/Core/Internal/Http/Livewire/Dashboard.php
@Modules/Core/Resources/views/livewire/dashboard.blade.php
@Modules/Core/Resources/views/livewire/top-nav.blade.php
@Modules/Categorization/Routes/web.php
@Modules/Import/Internal/Http/Livewire/PreviewWizard.php
@Modules/Import/Resources/views/livewire/preview-wizard.blade.php
@Modules/Chains/Public/Services/ChainLinkQuery.php
@Modules/Chains/Public/Services/CardStatementQuery.php
@Modules/Chains/Public/Actions/ConfirmChainLink.php
@Modules/Chains/Public/Actions/RejectChainLink.php
@Modules/Chains/Models/ChainResolutionRun.php

<interfaces>
<!-- Public surface this wave creates / extends. -->

From Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (EXTENDED):
```php
public function nextIcsSettlement(\Modules\Core\Models\User $user): ?\Modules\Chains\Public\Dto\CardStatementForecastTile;
```

From Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php (NEW):
```php
namespace Modules\Chains\Internal\Http\Livewire;

final class ChainReviewQueue extends \Livewire\Component
{
    public ?int $cursorId = null;
    public ?string $cursorConfidence = null;

    public function confirm(int $chainLinkId, \Modules\Core\Public\Contracts\CurrentUser $currentUser, \Modules\Chains\Public\Actions\ConfirmChainLink $confirm): void;
    public function reject(int $chainLinkId, \Modules\Core\Public\Contracts\CurrentUser $currentUser, \Modules\Chains\Public\Actions\RejectChainLink $reject): void;
    public function loadMore(int $nextCursorId, ?string $nextCursorConfidence = null): void;
    public function render(\Modules\Core\Public\Contracts\CurrentUser $currentUser, \Modules\Chains\Public\Services\ChainLinkQuery $query, \Illuminate\Contracts\View\Factory $views): \Illuminate\Contracts\View\View;
}
```

From Modules/Import/Internal/Http/Livewire/PreviewWizard.php (EXTENDED):
```php
public ?string $chainResolutionStatus = null;       // 'pending' | 'running' | 'complete' | 'failed' | null
public ?int $chainResolutionLinkedCount = 0;
public ?string $chainResolutionError = null;
public function refreshChainResolutionStatus(...): void;   // wire:poll.2s target — reads chain_resolution_runs
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: /chains/review page (ChainReviewQueue Livewire SFC + Blade) + ThisPeriodAtAGlanceQuery::nextIcsSettlement + dashboard tile + top-nav badge via explicit View Factory binding (issue #12 fix)</name>
  <files>Modules/Chains/Routes/web.php, Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php, Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php, Modules/Chains/Providers/ChainsServiceProvider.php, Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php, Modules/Core/Internal/Http/Livewire/Dashboard.php, Modules/Core/Resources/views/livewire/dashboard.blade.php, Modules/Core/Resources/views/livewire/top-nav.blade.php, Modules/Chains/tests/Feature/ChainReviewQueueTest.php, Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php, Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php</files>
  <read_first>
    - Modules/Categorization/Internal/Http/Livewire/TriageInbox.php (canonical paginated Livewire SFC with cursor + per-row actions — 103 lines)
    - Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php (canonical Blade pattern for paginated review surface)
    - Modules/Categorization/Routes/web.php + Modules/Ledger/Routes/web.php (Route::get Livewire class-as-handler pattern)
    - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (whole file — extend with nextIcsSettlement; mirror for() / forByCurrency() sibling shape)
    - Modules/Core/Internal/Http/Livewire/Dashboard.php (extend render() + Blade for the new tile + failed-job toast)
    - Modules/Core/Resources/views/livewire/dashboard.blade.php (existing tile chrome to mirror)
    - Modules/Core/Resources/views/livewire/top-nav.blade.php (existing nav link pattern + Uncategorized badge to mirror)
    - Modules/Chains/Public/Services/ChainLinkQuery.php (Wave 3 — `openCandidateCount`, `candidatesForReview`)
    - Modules/Chains/Public/Actions/ConfirmChainLink.php / RejectChainLink.php (Wave 3)
    - Modules/Chains/Public/Dto/ChainLinkRow.php / CardStatementForecastTile.php (Wave 1)
    - Modules/Chains/Models/ChainResolutionRun.php (Wave 1 audit table for failed-job toast — issue #1 + #8)
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-PATTERNS.md sections: "Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php", "Modules/Chains/Routes/web.php", "Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (MODIFIED)"
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-UI-SPEC.md (entirety — locks every visual + interaction contract for /chains/review, the dashboard tile, the top-nav, the failed-job toast)
  </read_first>
  <behavior>
    - Test 1: `GET /chains/review` requires auth (Phase 1 Fortify-auth middleware) — unauth response is a redirect to `/login`.
    - Test 2: `GET /chains/review` for an authenticated user renders the page with the open candidates list.
    - Test 3: Empty-state copy when no candidates exist: heading "Nothing to review", body per UI-SPEC.
    - Test 4: Each candidate row shows the from/to counterparties, kind label, Confirm + Reject buttons.
    - Test 5: Auto-promotion hint ("One more confirm and similar links auto-confirm.") renders on a row where `confirmsRemaining === 1`.
    - Test 6: Clicking Confirm dispatches `wire:click="confirm({{ $row->chainLinkId }})"` which invokes ConfirmChainLink — verified via Livewire test harness.
    - Test 7: Clicking Reject invokes RejectChainLink.
    - Test 8: Cross-user 404 on `/chains/review`: user A's session sees user A's candidates only; trying to confirm a chain_link belonging to user B raises NotFoundHttpException.
    - Test 9: `nextIcsSettlement()` returns null when no open card_statements exist (dashboard tile hidden); returns a CardStatementForecastTile when an open statement exists.
    - Test 10: Dashboard tile renders amount in EUR nl_NL locale + secondary line `due ~20 May`.
    - Test 11: Tile hides entirely when nextIcsSettlement returns null.
    - Test 12: Top-nav "Review chains" link inserted between Uncategorized and Settings; badge shows openCandidateCount when > 0, hides when 0, caps at "99+" when > 99.
    - Test 13: Failed-job toast renders on dashboard via wire:poll.5s when chain_resolution_runs.status='failed' for the user (issue #1 + #8 — reads audit table, NOT failed_jobs.payload LIKE substring).
    - Test 14: ChainsServiceProvider::boot uses `$this->app->make(\Illuminate\Contracts\View\Factory::class)->composer(...)` (issue #12 fix) — NEVER `view()->composer(...)` global helper. Verifiable: `grep "view()" Modules/Chains` returns ZERO matches.
  </behavior>
  <action>
**Step 1 — `Modules/Chains/Routes/web.php`** (per 05-PATTERNS § "Modules/Chains/Routes/web.php"):

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/chains/review', ChainReviewQueue::class)
        ->name('chains.review');
});
```

`Route::facade` is allowed in route files (BoundaryArchTest exempts `Modules\*\Routes`).

**Step 2 — `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php`** (mirror TriageInbox).

Use the verbatim skeleton from 05-PATTERNS § "Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php". Key details:
- `final class extends Component`.
- Properties: `?int $cursorId = null`, `?string $cursorConfidence = null`.
- Method `confirm(int $chainLinkId, CurrentUser $currentUser, ConfirmChainLink $confirm): void` — parameter injection.
- Method `reject(int $chainLinkId, CurrentUser $currentUser, RejectChainLink $reject): void`.
- Method `loadMore(int $nextCursorId, ?string $nextCursorConfidence = null): void`.
- `render(CurrentUser $currentUser, ChainLinkQuery $query, ViewFactory $views): View` — returns the Blade view with `candidates` (list) + `nextCursor` + `openCandidateCount`.

**Step 3 — Register Livewire components + the View Factory composer in `ChainsServiceProvider::boot()` (issue #12 fix).**

```php
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Modules\Chains\Internal\Http\Livewire\ChainDrawer;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;

public function boot(LivewireManager $livewire): void
{
    $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
    $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');
    $livewire->component('chains.chain-review-queue', ChainReviewQueue::class);
    $livewire->component('chains.chain-drawer', ChainDrawer::class);

    // Issue #12 fix: use the View Factory contract via $this->app->make() — NEVER the
    // view() global helper, which Larastan strict + CLAUDE.md feedback rule
    // `feedback_laravel_di_only.md` forbid (constructor DI only — no facades, no
    // global helpers). The View Factory is the framework-internal cross-cutting
    // mechanism for layout-shared data injection; using it via container resolution
    // keeps the DI-only invariant obvious.
    $factory = $this->app->make(ViewFactoryContract::class);
    $factory->composer('layouts.partials.top-nav', function ($view) {
        /** @var ChainLinkQuery $query */
        $query = $this->app->make(ChainLinkQuery::class);
        /** @var CurrentUser $currentUser */
        $currentUser = $this->app->make(CurrentUser::class);
        if ($currentUser->user() !== null) {
            $view->with('chainOpenCandidateCount', $query->openCandidateCount($currentUser->user()));
        }
    });
}
```

Acceptance gate: `grep -RIn 'view()' Modules/Chains` returns ZERO matches. Only the View Factory contract method is permitted. (The forbidden surface is the `view()` global helper, NOT the `$factory->composer()` method on the contract.)

**Step 4 — `Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php`** per UI-SPEC § Copywriting + Component Inventory.

```blade
<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-3xl">
        <h1 class="text-xl font-semibold text-slate-900">Review chains</h1>
        <p class="mt-2 text-sm text-slate-500">
            Confirm or reject candidate links the chain resolver could not auto-confirm.
        </p>
    </header>

    @if (count($candidates) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900">Nothing to review</h2>
            <p class="mt-2 text-sm text-slate-500">
                Every chain link is either confirmed or rejected. New candidates will appear here as imports land.
            </p>
        </div>
    @else
        <ul class="space-y-md">
            @foreach ($candidates as $row)
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4 opacity-90">
                    <div class="flex items-start justify-between gap-md">
                        <div>
                            <p class="text-sm text-slate-900">
                                {{ $row->fromCounterparty }} · {{ $row->fromAmount->format('nl_NL') }}
                                <span aria-hidden="true" class="mx-1 text-slate-500">←</span>
                                {{ $row->toCounterparty }} · {{ $row->toAmount->format('nl_NL') }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $row->kind === 'paypal_funding' ? 'PayPal funding' : 'Bulk iDEAL settlement' }} · {{ $row->fromPostedAt->format('d M Y') }} → {{ $row->toPostedAt->format('d M Y') }}
                            </p>
                            @if ($row->confirmsRemaining === 1)
                                <p class="mt-1 flex items-center gap-1 text-xs text-emerald-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    One more confirm and similar links auto-confirm.
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-sm">
                            <button type="button"
                                wire:click="confirm({{ $row->chainLinkId }})"
                                class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700 focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                                aria-label="Confirm chain link {{ $row->chainLinkId }}">
                                Confirm
                            </button>
                            <button type="button"
                                wire:click="reject({{ $row->chainLinkId }})"
                                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                                aria-label="Reject chain link {{ $row->chainLinkId }}">
                                Reject
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($nextCursor !== null)
            <button type="button"
                wire:click="loadMore({{ $nextCursor['id'] }}, '{{ $nextCursor['confidence'] }}')"
                class="mt-md text-xs text-slate-500 hover:text-slate-900">Show more</button>
        @endif
    @endif
</div>
```

**Step 5 — Extend `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` with `nextIcsSettlement()`:**

```php
public function nextIcsSettlement(User $user): ?CardStatementForecastTile
{
    $row = $this->db->connection()
        ->table('card_statements')
        ->join('accounts', 'accounts.id', '=', 'card_statements.account_id')
        ->where('card_statements.user_id', $user->id)
        ->where('accounts.kind', 'ics_card')
        ->whereIn('card_statements.state', ['open', 'partially_settled'])
        ->orderByDesc('card_statements.period_end')
        ->select(
            'card_statements.id',
            'card_statements.open_balance_minor',
            'card_statements.period_end',
            'card_statements.state',
        )
        ->first();

    if ($row === null) {
        return null;
    }

    $periodEnd = \Carbon\CarbonImmutable::parse(self::toString($row->period_end));
    return new CardStatementForecastTile(
        amount: Money::ofMinor(self::toInt($row->open_balance_minor), 'EUR'),
        dueDate: $periodEnd->addDays(5)->startOfDay(),
        statementId: self::toInt($row->id),
        state: self::toString($row->state),
    );
}
```

**Step 6 — Extend `Modules/Core/Internal/Http/Livewire/Dashboard.php` and `dashboard.blade.php`** to render the tile (D-99) + the failed-job toast (D-103) backed by chain_resolution_runs (issue #1 + #8).

In `Dashboard.php`:
- Inject the existing `ThisPeriodAtAGlanceQuery` and call `$query->nextIcsSettlement($user)` — pass the result as `$nextSettlement` to the view.
- Add a `wire:poll.5s="refreshFailedChainResolution"` method that reads the chain_resolution_runs audit table for the user (issue #1 + #8 fix — replaces failed_jobs.payload LIKE substring):

```php
public function refreshFailedChainResolution(\Illuminate\Database\DatabaseManager $db, \Modules\Core\Public\Contracts\CurrentUser $currentUser): void
{
    $user = $currentUser->user();
    $this->failedChainResolutionExists = $db->connection()->table('chain_resolution_runs')
        ->where('user_id', $user->id)
        ->where('status', 'failed')
        ->exists();
}
```

Property: `public bool $failedChainResolutionExists = false;`. Initial value populated on mount.

In `dashboard.blade.php`:
- Before the existing per-currency tile row, add the "Next ICS settlement" tile wrapped in `@if ($nextSettlement !== null)`:

```blade
@if ($nextSettlement !== null)
    <section class="mb-2xl">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-base font-semibold text-slate-900">Next ICS settlement</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ $nextSettlement->amount->format('nl_NL') }}
            </p>
            <p class="mt-1 text-xs text-slate-500">due ~{{ $nextSettlement->dueDate->format('d M') }}</p>
        </div>
    </section>
@endif
```

- At the bottom of the Blade, add the failed-job toast wrapped in `@if ($failedChainResolutionExists)`:

```blade
<div wire:poll.5s="refreshFailedChainResolution"></div>
@if ($failedChainResolutionExists)
    <div role="status" aria-live="polite"
         class="fixed bottom-4 right-4 z-50 max-w-sm rounded-lg border-l-2 border-rose-600 bg-white p-4 shadow-md">
        <p class="text-sm text-slate-900">Chain resolution failed.</p>
        <p class="mt-1 text-xs text-slate-500">One or more chain-resolution jobs hit an error. Open Horizon to retry or inspect.</p>
        <a href="/horizon/failed" class="mt-2 inline-block text-xs font-medium text-slate-900 underline">Open Horizon</a>
    </div>
@endif
```

**Step 7 — Extend `Modules/Core/Resources/views/livewire/top-nav.blade.php`** with the Review chains link + badge.

```blade
<a href="{{ route('chains.review') }}"
   class="{{ request()->is('chains/review') ? 'text-slate-900 font-medium' : 'text-slate-500' }} hover:text-slate-900">
    Review chains
    @if (($chainOpenCandidateCount ?? 0) > 0)
        <span class="ml-1.5 inline-flex items-center rounded-full bg-slate-900 px-1.5 py-0.5 text-xs font-medium text-white">
            {{ $chainOpenCandidateCount > 99 ? '99+' : $chainOpenCandidateCount }}
        </span>
    @endif
</a>
```

The `$chainOpenCandidateCount` variable is injected by the View Factory composer registered in Step 3 (issue #12 fix). Browser test: visit `/` → top-nav shows the badge with the count.

**Step 8 — Tests.**

`Modules/Chains/tests/Feature/ChainReviewQueueTest.php`:
```php
beforeEach(function (): void {
    // Seed user + 5 candidate chain_links of mixed kinds + confidences.
});

it('requires auth', function (): void {
    $this->get(route('chains.review'))->assertRedirect('/login');
});

it('renders the page with candidates sorted by confidence DESC', function (): void {
    $this->actingAs($this->user)->get(route('chains.review'))
        ->assertOk()
        ->assertSeeText('Review chains')
        ->assertSeeText('Confirm or reject candidate links');
});

it('renders empty state when no candidates exist', function (): void {
    \Modules\Chains\Models\ChainLink::query()->where('user_id', $this->user->id)->delete();
    $this->actingAs($this->user)->get(route('chains.review'))
        ->assertOk()
        ->assertSeeText('Nothing to review');
});

it('Confirm button invokes ConfirmChainLink', function (): void {
    \Livewire\Livewire::actingAs($this->user)->test(\Modules\Chains\Internal\Http\Livewire\ChainReviewQueue::class)
        ->call('confirm', $this->candidateId)
        ->assertOk();
    $link = \Modules\Chains\Models\ChainLink::find($this->candidateId);
    expect($link->state)->toBe('confirmed');
});

it('Reject button invokes RejectChainLink', function (): void {
    \Livewire\Livewire::actingAs($this->user)->test(\Modules\Chains\Internal\Http\Livewire\ChainReviewQueue::class)
        ->call('reject', $this->candidateId);
    $link = \Modules\Chains\Models\ChainLink::find($this->candidateId);
    expect($link->state)->toBe('rejected');
});

it('renders the auto-promotion hint when confirms_remaining === 1', function (): void {
    $this->actingAs($this->user)->get(route('chains.review'))
        ->assertSeeText('One more confirm and similar links auto-confirm.');
});

it('ChainsServiceProvider uses View Factory contract (issue #12)', function (): void {
    $providerPath = base_path('Modules/Chains/Providers/ChainsServiceProvider.php');
    $contents = file_get_contents($providerPath);
    // No `view()` global helper anywhere in the module.
    expect($contents)->not->toMatch('/\bview\(\)/');
    // The provider explicitly resolves ViewFactoryContract.
    expect($contents)->toContain('ViewFactoryContract');
});

it('no view() global helper anywhere in Modules/Chains/', function (): void {
    $hits = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Chains'), RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        if (preg_match('/\bview\(\)/', $stripped) === 1) {
            $hits[] = $file->getPathname();
        }
    }
    expect($hits)->toBe([], "view() global helper is forbidden in Modules/Chains. Offenders:\n  ".implode("\n  ", $hits));
});
```

`Modules/Chains/tests/Feature/NextIcsSettlementTileTest.php`:
```php
it('returns CardStatementForecastTile when an open card_statement exists', function (): void {
    $tile = $this->app->make(\Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery::class)
        ->nextIcsSettlement($this->user);
    expect($tile)->toBeInstanceOf(\Modules\Chains\Public\Dto\CardStatementForecastTile::class);
    expect((int) $tile->amount->toMinor())->toBe($this->expectedOpenBalanceMinor);
});

it('returns null when no open card_statements exist', function (): void {
    expect($this->app->make(\Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery::class)
        ->nextIcsSettlement($this->user))->toBeNull();
});

it('dashboard hides the tile when nextIcsSettlement returns null', function (): void {
    $this->actingAs($this->user)->get('/')
        ->assertDontSeeText('Next ICS settlement');
});

it('dashboard renders the tile when nextIcsSettlement returns non-null', function (): void {
    $this->actingAs($this->user)->get('/')
        ->assertSeeText('Next ICS settlement')
        ->assertSeeText('due ~');
});

it('failed-job toast renders when chain_resolution_runs.status=failed for the user (issue #1 + #8)', function (): void {
    // Seed a chain_resolution_runs row with status='failed'.
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'failed',
        'last_error' => 'TestException: something went wrong',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($this->user)->get('/')
        ->assertSeeText('Chain resolution failed')
        ->assertSeeText('Open Horizon');
});

it('failed-job toast hidden when chain_resolution_runs has no failed rows for this user (cross-user isolation issue #8)', function (): void {
    // Seed a chain_resolution_runs row with status='failed' for a DIFFERENT user.
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($this->user)->get('/')
        ->assertDontSeeText('Chain resolution failed');
});
```

`Modules/Chains/tests/Feature/CrossUserChainLinkIsolationTest.php`:
```php
it('cross-user 404 on /chains/review confirm — user A cannot confirm user B\'s chain_link', function (): void {
    \Livewire\Livewire::actingAs($this->userA)->test(\Modules\Chains\Internal\Http\Livewire\ChainReviewQueue::class)
        ->call('confirm', $this->userBCandidateId)
        ->assertStatus(404);
});

it('top-nav badge shows only the user\'s open candidate count', function (): void {
    $this->actingAs($this->userA)->get('/')
        ->assertSeeText('3');
});

it('top-nav badge hides when openCandidateCount === 0', function (): void {
    \Modules\Chains\Models\ChainLink::query()->delete();
    $this->actingAs($this->user)->get('/')
        ->assertDontSeeHtml('rounded-full bg-slate-900');
});
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter "ChainReviewQueueTest"</automated>
    <automated>vendor/bin/pest --filter "NextIcsSettlementTileTest"</automated>
    <automated>vendor/bin/pest --filter "CrossUserChainLinkIsolationTest"</automated>
    <automated>composer analyse 2>&amp;1 | tail -3</automated>
    <automated>composer format:check</automated>
    <automated>grep -q 'chains.chain-review-queue' Modules/Chains/Providers/ChainsServiceProvider.php</automated>
    <automated>grep -q 'nextIcsSettlement' Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php</automated>
    <automated>grep -q 'Next ICS settlement' Modules/Core/Resources/views/livewire/dashboard.blade.php</automated>
    <automated>grep -q 'Review chains' Modules/Core/Resources/views/livewire/top-nav.blade.php</automated>
    <automated>grep -q "Route::get('/chains/review'" Modules/Chains/Routes/web.php</automated>
    <automated>grep -RIn 'view()' Modules/Chains/ | grep -v '^\s*\*\|^\s*//' | grep -v '_test\|Test\.php' | wc -l | awk '{exit !($1 == 0)}'</automated>
    <automated>grep -q 'ViewFactoryContract' Modules/Chains/Providers/ChainsServiceProvider.php</automated>
    <automated>grep -q 'failed_chain_resolution\|chain_resolution_runs' Modules/Core/Internal/Http/Livewire/Dashboard.php</automated>
  </verify>
  <acceptance_criteria>
    - `/chains/review` route registered + accessible at `GET /chains/review`; redirects to login when unauthenticated.
    - `ChainReviewQueue` Livewire SFC exists with `confirm`, `reject`, `loadMore`, and `render` methods.
    - Confirm button click invokes `ConfirmChainLink::__invoke()`.
    - Reject button click invokes `RejectChainLink::__invoke()`.
    - Empty state copy matches UI-SPEC verbatim ("Nothing to review" / "Every chain link is either confirmed or rejected. New candidates will appear here as imports land.").
    - Auto-promotion hint copy matches UI-SPEC verbatim and appears only on rows where `confirmsRemaining === 1`.
    - `ThisPeriodAtAGlanceQuery::nextIcsSettlement(User)` exists and returns `?CardStatementForecastTile`.
    - Dashboard renders the tile when nextIcsSettlement returns non-null; hides when null.
    - Top-nav "Review chains" link inserted; badge displays openCandidateCount; hides when 0; caps at "99+" when > 99.
    - `ChainsServiceProvider::boot` uses `$this->app->make(ViewFactoryContract::class)->composer(...)` — NEVER `view()->composer(...)` (issue #12 fix). Verifiable: `grep "view()" Modules/Chains` returns ZERO matches outside test files / comments.
    - Failed-job toast reads chain_resolution_runs.status='failed' (issue #1 + #8) — NEVER failed_jobs.payload LIKE substring; verified by the test that seeds a failed run for a different user and asserts the toast does NOT appear for the current user.
    - Cross-user 404 on `/chains/review` confirm — verified.
    - Larastan level 10 strict passes with zero NEW errors. Pint clean.
  </acceptance_criteria>
  <done>
    /chains/review page exists, ChainReviewQueue Livewire SFC ships Confirm/Reject + auto-promotion hint, dashboard tile + failed-job toast surface CHN-06, top-nav badge surfaces openCandidateCount via explicit View Factory binding (issue #12 fix), failed-job toast backed by chain_resolution_runs audit table (issue #1 + #8), all cross-user safety tests green.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Wizard polling backed by chain_resolution_runs audit table (issue #1 + #8 fix) + WizardChainResolutionStatusTest</name>
  <files>Modules/Import/Internal/Http/Livewire/PreviewWizard.php, Modules/Import/Resources/views/livewire/preview-wizard.blade.php, Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php</files>
  <read_first>
    - Modules/Import/Internal/Http/Livewire/PreviewWizard.php (extend with chain-resolution-status polling)
    - Modules/Import/Resources/views/livewire/preview-wizard.blade.php (extend with the polling status surface)
    - Modules/Chains/Models/ChainResolutionRun.php (Wave 1 audit table)
    - .planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-UI-SPEC.md § "Wizard 'Resolving chains…' status surface (D-103 / D-105)"
  </read_first>
  <behavior>
    - Test 1: PreviewWizard adds `$chainResolutionStatus` + `$chainResolutionLinkedCount` + `$chainResolutionError` + `$lastImportRunId` properties.
    - Test 2: `refreshChainResolutionStatus()` reads `chain_resolution_runs` table (NOT failed_jobs LIKE substring — issue #1 + #8), filters by user_id, orders by id DESC, takes the most recent row.
    - Test 3: When the row has status='pending' or 'running', wizard renders "Resolving chains…" surface; surface polls `wire:poll.2s`.
    - Test 4: When the row has status='complete', wizard auto-navigates to `import.results` (or whichever post-confirm route exists).
    - Test 5: When the row has status='failed', wizard renders the inline failed copy with `Open Horizon` link + uses the audit row's `last_error` for display (truncated to <= 200 chars).
    - Test 6: Cross-user isolation — the wizard only reads the current user's chain_resolution_runs rows. Verifiable: seed a failed run for user B; wizard for user A reports status=null (no row exists for user A).
    - Test 7: Query is exact-match on user_id (NEVER substring LIKE) — verifiable by inserting a row for `user_id=11` and querying for `user_id=1` returns no result (issue #8 — prevents the id-prefix substring false-positive).
  </behavior>
  <action>
**Step 1 — Extend `Modules/Import/Internal/Http/Livewire/PreviewWizard.php`** with chain-resolution-status polling backed by chain_resolution_runs (issue #1 + #8 fix — replaces failed_jobs.payload LIKE '%userId:N%' substring match).

```php
public ?string $chainResolutionStatus = null;       // 'pending' | 'running' | 'complete' | 'failed' | null
public int $chainResolutionLinkedCount = 0;
public ?string $chainResolutionError = null;
public ?int $lastImportRunId = null;                // set when ConfirmImport runs

public function refreshChainResolutionStatus(
    \Illuminate\Database\DatabaseManager $db,
    \Modules\Core\Public\Contracts\CurrentUser $currentUser,
): void {
    if ($this->lastImportRunId === null) {
        return;
    }
    $user = $currentUser->user();

    // Issue #1 + #8 fix: read from chain_resolution_runs audit table by exact
    // user_id match. NEVER use `payload LIKE '%userId:N%'` against failed_jobs
    // (vulnerable to id-prefix false positives — user_id=11 would match
    // user_id=1 in a substring search).
    $row = $db->connection()->table('chain_resolution_runs')
        ->where('user_id', $user->id)
        ->orderByDesc('id')
        ->limit(1)
        ->first(['status', 'linked_count', 'last_error']);

    if ($row === null) {
        $this->chainResolutionStatus = null;
        return;
    }

    $this->chainResolutionStatus = (string) $row->status;
    $this->chainResolutionLinkedCount = (int) ($row->linked_count ?? 0);
    $this->chainResolutionError = is_string($row->last_error) ? substr($row->last_error, 0, 200) : null;

    if ($this->chainResolutionStatus === 'complete') {
        $this->redirect(route('import.results', ['importRun' => $this->lastImportRunId]));
    }
}
```

(`import.results` route may need to be added if not already; coordinate with existing post-confirm route name.)

**Step 2 — In `preview-wizard.blade.php`** add (after the existing post-confirm step):

```blade
@if ($chainResolutionStatus !== null && $chainResolutionStatus !== 'complete')
    <section class="mt-md rounded-lg border border-slate-200 bg-white p-6 space-y-3"
             wire:poll.2s="refreshChainResolutionStatus">
        <h3 class="text-base font-semibold text-slate-900">Resolving chains…</h3>
        <p class="text-sm text-slate-500">
            @if ($chainResolutionStatus === 'pending')
                Queued. The chain resolver will start shortly.
            @elseif ($chainResolutionStatus === 'running')
                Linking funding chains and decomposing statement settlements.
            @elseif ($chainResolutionStatus === 'failed')
                Chain resolution failed: {{ $chainResolutionError ?? 'an unknown error occurred' }}. <a href="/horizon/failed" class="font-medium text-slate-900 underline">Open Horizon</a> to retry or inspect.
            @endif
        </p>
        <span class="h-2 w-2 rounded-full bg-slate-400 animate-pulse"></span>
    </section>
@endif
```

**Step 3 — In `ConfirmImport` (or the wizard's confirm action)**, after dispatch, set the wizard's `$lastImportRunId = $importRunId` so the polling starts. (ConfirmImport already inserts the chain_resolution_runs row with status='pending' BEFORE dispatching — see 05-03 Step 2b.)

**Step 4 — Tests at `Modules/Chains/tests/Feature/WizardChainResolutionStatusTest.php`:**

```php
beforeEach(function (): void {
    $this->user = \Modules\Core\Models\User::query()->create([
        'email' => 'wizard-test@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->otherUser = \Modules\Core\Models\User::query()->create([
        'email' => 'wizard-other@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    // Seed an import_run for the user (the wizard requires an import run id).
    $this->importRunId = \DB::table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('wizard reads chain_resolution_runs by user_id (exact match — issue #1 + #8)', function (): void {
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'running',
        'linked_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($this->user)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'running');
});

it('wizard auto-navigates on status=complete', function (): void {
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'complete',
        'linked_count' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($this->user)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertRedirect(route('import.results', ['importRun' => $this->importRunId]));
});

it('wizard renders failed copy + last_error on status=failed', function (): void {
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'failed',
        'last_error' => 'TestException: simulated failure',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($this->user)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'failed')
        ->assertSet('chainResolutionError', 'TestException: simulated failure');
});

it('cross-user isolation — wizard does NOT see other user\'s chain_resolution_runs (issue #1 + #8)', function (): void {
    // Seed a failed run for the OTHER user.
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($this->user)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', null);  // current user has no rows
});

it('id-prefix substring is NOT used for user matching (issue #8 — prevents user_id=11 matching user_id=1)', function (): void {
    // Seed a row for user_id=11. Query as if user has id=1 (none of their own runs exist).
    // The exact-match query should return null; a substring LIKE would falsely match.
    $bigUser = \Modules\Core\Models\User::query()->create([
        'email' => 'big-id-user@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    // Manually set the user id to 11 (or just rely on the test seed ordering).
    \DB::table('chain_resolution_runs')->insert([
        'user_id' => $bigUser->id,
        'status' => 'failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($this->user)  // user with id=1 (or whatever the seed produces)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', null);  // NO row for this user — exact-match query confirms

    // Sanity: the bigUser DOES see their row.
    \Livewire\Livewire::actingAs($bigUser)
        ->test(\Modules\Import\Internal\Http\Livewire\PreviewWizard::class)
        ->set('lastImportRunId', $this->importRunId)
        ->call('refreshChainResolutionStatus')
        ->assertSet('chainResolutionStatus', 'failed');
});

it('PreviewWizard does NOT query failed_jobs.payload LIKE substring (issue #1 + #8 lock)', function (): void {
    $wizardPath = base_path('Modules/Import/Internal/Http/Livewire/PreviewWizard.php');
    $contents = file_get_contents($wizardPath);
    // The unsafe substring pattern must not appear in PreviewWizard.
    expect($contents)->not->toMatch('/payload.*?like.*?userId/i');
    expect($contents)->not->toMatch("/'%userId:'/");
    // The safe audit-table query must appear.
    expect($contents)->toContain('chain_resolution_runs');
});
```
  </action>
  <verify>
    <automated>vendor/bin/pest --filter "WizardChainResolutionStatusTest"</automated>
    <automated>composer analyse 2>&amp;1 | tail -3</automated>
    <automated>composer format:check</automated>
    <automated>grep -q 'chain_resolution_runs' Modules/Import/Internal/Http/Livewire/PreviewWizard.php</automated>
    <automated>! grep -E 'payload.+like.+userId' Modules/Import/Internal/Http/Livewire/PreviewWizard.php</automated>
    <automated>grep -q 'wire:poll.2s' Modules/Import/Resources/views/livewire/preview-wizard.blade.php</automated>
  </verify>
  <acceptance_criteria>
    - PreviewWizard's `refreshChainResolutionStatus` reads `chain_resolution_runs` filtered by `where('user_id', $user->id)` (exact match — issue #8 fix).
    - PreviewWizard does NOT contain any `payload LIKE '%userId:N%'` substring pattern (issue #1 + #8 lock).
    - Wizard polls `wire:poll.2s="refreshChainResolutionStatus"`.
    - Wizard auto-navigates to `import.results` route on status === 'complete'.
    - Wizard surfaces inline failed copy with `Open Horizon` link + the audit row's `last_error` (truncated to 200 chars).
    - All WizardChainResolutionStatusTest cases pass (running / complete / failed / cross-user isolation / id-prefix substring guard).
    - Larastan level 10 strict + Pint clean.
  </acceptance_criteria>
  <done>
    Wizard polls chain_resolution_runs audit table by exact user_id match (issue #1 + #8 fix); fails-fast on cross-user data; id-prefix substring vulnerability eliminated. Failed copy + last_error surfaced. Cross-user isolation verified.
  </done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <name>Task 3: End-to-end manual verification of Phase 5 UI against Wave 0 fixture</name>
  <what-built>
    Phase 5 review queue + dashboard tile + top-nav badge + wizard polling + failed-job toast. Combined with 05-05 (drawer), the entire UI surface is functional end-to-end.
  </what-built>
  <how-to-verify>
    Pre-requisites:
    - Docker Redis container running (`docker ps | grep diederik-redis`)
    - `php artisan horizon` running in a second terminal
    - App available at `http://diederik.test` or `http://127.0.0.1`
    - Wave 0 / Wave 1 / Wave 2 / Wave 3 / Wave 4 (05-05) complete

    1. **Visit `/` (dashboard):**
       - **Expected:** "Next ICS settlement" tile renders when an open card_statement exists. Tile hides for settled-state statements.

    2. **Visit `/transactions` → click any ICS-expense row:**
       - **Expected:** The transaction detail page shows a "View chain" link (from 05-05).
       - Click it: drawer opens (from 05-05).

    3. **Visit `/chains/review`:**
       - **Expected (with the clean variant):** "Nothing to review" empty state.
       - To exercise the candidate path: import a PayPal expense whose fuzzy-match scored 0.7 — verify the candidate row renders with Confirm + Reject chips.

    4. **Top-nav badge:**
       - When candidates exist: "Review chains" link shows a small slate-900 badge with the count.
       - When count drops to 0: badge hides.

    5. **Failed-job toast (issue #1 + #8 — backed by chain_resolution_runs, NOT failed_jobs.payload LIKE):**
       - **Force a failure:** Temporarily stop the Redis container (`docker stop diederik-redis`).
       - Import another CSV → wizard's polling reflects 'failed' status (sourced from chain_resolution_runs.status='failed').
       - Visit `/` (dashboard) → failed-job toast renders bottom-right: "Chain resolution failed." with `Open Horizon` link.
       - Restart Redis (`docker start diederik-redis`); the failed status persists until cleared from chain_resolution_runs.

    6. **Cross-user isolation:**
       - Create two users. Seed a failed chain_resolution_runs row for user B.
       - Log in as user A → dashboard does NOT show the failed-job toast.

    7. **Wizard polling source verification (issue #1 + #8):**
       ```bash
       grep -n 'chain_resolution_runs\|payload' Modules/Import/Internal/Http/Livewire/PreviewWizard.php
       ```
       Expected: matches `chain_resolution_runs`. NO matches for `payload LIKE` or `payload', 'like'`.

    8. **View Factory binding verification (issue #12):**
       ```bash
       grep -n 'view()\|ViewFactoryContract' Modules/Chains/Providers/ChainsServiceProvider.php
       ```
       Expected: matches `ViewFactoryContract`. NO matches for `view()` (the global helper).

    9. **Full quick-filter test suite:**
       ```bash
       vendor/bin/pest --filter "Chains|PairLookup|BoundaryArchTest|HorizonBoots|ResolveChainLinksJob|CardStatement|ChainLink|ChainDrawer|ChainReviewQueue|NextIcsSettlement|CrossUserChainLink|WizardChainResolutionStatus|ChainResolutionRuns"
       ```
       Expected: all green.

    10. **Full static-analysis + format gates:**
        ```bash
        composer analyse && composer format:check
        ```
        Expected: zero NEW errors; Pint clean.
  </how-to-verify>
  <resume-signal>
    Type "approved" if all 10 manual checks pass. Type "fail: {description}" with the specific failing check if any verification fails so the planner can revise.
  </resume-signal>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| User browser ←→ Livewire SFC | Confirm/Reject buttons send the chainLinkId as user input; ConfirmChainLink/RejectChainLink filter on user_id via firstOrFail. |
| Failed-job toast ←→ chain_resolution_runs table | Polls a server-side audit table by exact user_id match (issue #1 + #8 — replaces unsafe failed_jobs.payload LIKE substring). |
| Top-nav badge ←→ View Factory composer | View Factory contract method (not global helper) injects $chainOpenCandidateCount; ChainLinkQuery filters by user_id. |
| Wizard polling ←→ chain_resolution_runs table | Reads by exact user_id; never substring LIKE. |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-05-05b-01 | Elevation of Privilege | Cross-user chain_link confirm/reject via /chains/review | mitigate | firstOrFail() + where('user_id', $user->id) in both Public actions raises NotFoundHttpException. CrossUserChainLinkIsolationTest covers. ASVS V4. |
| T-05-05b-02 | Information Disclosure | failed_jobs payload LIKE query historically leaked one user's failure to another (issue #1 + #8) | mitigate | Wizard + dashboard now read chain_resolution_runs by EXACT user_id match. Grep gate `! grep -E 'payload.+like.+userId'` enforces. Test seeds user_id=11 + queries user_id=1 to confirm no false-positive match. ASVS V4. |
| T-05-05b-03 | Elevation of Privilege | view() global helper bypasses Larastan strict + CLAUDE.md DI-only rule | mitigate | ChainsServiceProvider explicitly resolves the View Factory contract via `$this->app->make(ViewFactoryContract::class)`. Grep gate `grep -RIn 'view()' Modules/Chains` returns ZERO matches (issue #12 fix). The `feedback_laravel_di_only.md` memory rule enforces. |
| T-05-05b-04 | Denial of Service | wire:poll.2s every 2s creates load if user leaves wizard open | accept | Single-user app; one wizard tab at a time. Polling stops on auto-navigate. ASVS V11. |
| T-05-05b-05 | Cross-Site Scripting | counterparty_name rendered in Blade without escaping | mitigate | Blade `{{ $variable }}` escapes by default. ASVS V5. |
| T-05-05b-06 | Cross-Site Request Forgery | Confirm/Reject buttons submit state-changing actions | mitigate | Livewire components ship CSRF tokens automatically. ASVS V13. |
</threat_model>

<verification>
- ChainReviewQueueTest + NextIcsSettlementTileTest + CrossUserChainLinkIsolationTest + WizardChainResolutionStatusTest all green.
- ChainsServiceProvider uses View Factory contract (issue #12) — `grep -RIn 'view()' Modules/Chains/` returns ZERO matches.
- PreviewWizard reads chain_resolution_runs by exact user_id (issue #1 + #8) — no `payload LIKE` substring.
- Failed-job toast cross-user isolation verified.
- Top-nav badge correctly fed by View Factory composer.
- Larastan level 10 strict + Pint clean.
- Operator confirms /chains/review, the dashboard tile, the top-nav badge, the wizard polling, and the failed-job toast all behave per UI-SPEC.
</verification>

<success_criteria>
05-05b complete when (PHASE 5 + UI HALF COMPLETE):
- [ ] `/chains/review` page (ChainReviewQueue Livewire SFC) renders candidates sorted by confidence DESC + updated_at DESC, with Confirm + Reject per row + auto-promotion hint at confirms_remaining === 1.
- [ ] ThisPeriodAtAGlanceQuery::nextIcsSettlement returns the open card_statement's forecast tile (D-99 + D-100); Dashboard conditionally renders the tile when non-null.
- [ ] Failed-job toast renders on dashboard via wire:poll.5s when chain_resolution_runs.status='failed' for the user (issue #1 + #8 fix — exact user_id match, never failed_jobs.payload LIKE substring); persistent; links to /horizon/failed.
- [ ] Top-nav "Review chains" link with badge; openCandidateCount injected via View Factory contract (issue #12 fix — never `view()` global helper).
- [ ] PreviewWizard polls chain_resolution_runs via wire:poll.2s after ConfirmImport dispatches the job; auto-navigates to import-results on 'complete'; surfaces inline failed status with `last_error` + `Open Horizon` link.
- [ ] Cross-user 404 verified on /chains/review confirm/reject AND wizard polling AND failed-job toast.
- [ ] `grep -RIn 'view()' Modules/Chains/` returns ZERO matches (issue #12 lock).
- [ ] `grep -E 'payload.+like.+userId' Modules/Import/Internal/Http/Livewire/PreviewWizard.php` returns ZERO matches (issue #1 + #8 lock).
- [ ] All Phase 5 BoundaryArchTest invariants stay green.
- [ ] Larastan level 10 strict + Pint clean.
- [ ] Operator-verified end-to-end against the synthesised Wave 0 fixture (Task 3 checkpoint approved).

PHASE 5 SUCCESS CRITERIA (from ROADMAP.md):
- [ ] SC#1 — User opens a Netflix-via-PayPal transaction and sees the full chain tree back to the ASN or ICS account that ultimately funded it.
- [ ] SC#2 — User sees the monthly ASN → ICS iDEAL debit decomposed into the underlying ICS card transactions it settles, with partial-payment / overpayment / carry-forward credit handled within ±€5 / ±2% / ±10-day tolerances.
- [ ] SC#3 — User can review fuzzy match candidates in a queue, confirm or reject each, and confirmed patterns auto-promote similar future candidates.
- [ ] SC#4 — User sees the next forecasted ICS settlement amount before paying it, computed from cleared ICS lines since the last settlement.
</success_criteria>

<output>
After completion, create `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-05b-SUMMARY.md` documenting: review queue + dashboard tile + wizard polling + top-nav badge behaviors observed, the View Factory binding result (issue #12), the chain_resolution_runs read path (issue #1 + #8), and the operator-confirmed end-to-end behaviors against the Wave 0 fixture (candidate review, settlement forecast, failed-job toast, wizard polling).

Then create `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/SUMMARY.md` (phase-level summary): the 4 SCs from ROADMAP § Phase 5 + their pass/fail status + the locked-in artifacts inventory + the cross-source synthesis approach (D-107/D-108) + lessons learned for Phase 6 (which will extend the queue + Horizon infrastructure with launchd plists).
</output>
