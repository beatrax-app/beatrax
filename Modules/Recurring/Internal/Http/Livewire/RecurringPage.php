<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

/**
 * `/recurring` page — the grouped view of approved recurring
 * series, split into "Recurring expenses" + "Recurring income" +
 * "Recurring transfers" (collapsed by default) sections with a
 * single net-flow summary header.
 *
 * Method-parameter DI on every action and on render — constructor
 * injection is banned on Livewire `Component` subclasses by
 * phpstan-strict-rules; the pattern mirrors the chain-review-queue
 * and recurring-review-page shapes. This DI-only constraint also
 * rules out `#[Computed]` getters as a memoisation seam: the
 * attribute invokes the method with no arguments, so collaborators
 * cannot enter the page that way. `render()` therefore takes the
 * round-trip cost (a 3-query batch + a totals SUM) on every
 * action — `toggleTransfers()` included. The two queries are
 * read-mostly, user-scoped, and indexed; the cost is documented
 * here rather than papered over.
 *
 * Public state:
 *  - `transfersExpanded` — false by default. The `toggleTransfers()`
 *    action flips it; the Blade view conditionally renders the
 *    transfers panel based on the flag.
 */
final class RecurringPage extends Component
{
    /**
     * Transfers-section disclosure state. Default closed; the Blade
     * view renders the panel inside a `<details>` element whose
     * `open` attribute reflects this flag.
     */
    public bool $transfersExpanded = false;

    public function toggleTransfers(): void
    {
        $this->transfersExpanded = ! $this->transfersExpanded;
    }

    /**
     * Dispatches the same `DetectRecurringSeriesJob` the daily sweep
     * dispatches. The job's per-user `ShouldBeUniqueUntilProcessing`
     * lock collapses spam-clicks into a single queued pass at the
     * worker boundary. The HTTP layer only enqueues — no detector
     * runs synchronously, so the
     * `noSynchronousDetectionInRequestLifecycle` arch invariant stays
     * green.
     *
     * Short-circuits when the caller is unauthenticated (mirrors the
     * top-nav badge composer's defensive guard). The route is auth-
     * gated upstream so this is a defence-in-depth check rather than
     * a runtime expectation.
     */
    public function reDetect(CurrentUser $currentUser, Dispatcher $bus): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $bus->dispatch(new DetectRecurringSeriesJob($currentUser->user()->id));
        $this->dispatch('toast', message: 'Detecting recurring series…', undoAction: '', undoPayload: null);
    }

    public function render(
        CurrentUser $currentUser,
        FixedPaymentsViewQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $sections = $query->viewForUser($user);
        $totals = $query->monthlyEquivalentTotals($user);

        $view = $views->make('recurring::livewire.recurring-page', [
            'sections' => $sections,
            'totals' => $totals,
            'transfersExpanded' => $this->transfersExpanded,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Recurring · diederik']);

        return $view;
    }
}
