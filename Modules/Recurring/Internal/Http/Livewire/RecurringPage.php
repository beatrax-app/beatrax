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

// Method-parameter DI on every action and on render() (constructor
// injection is banned on Livewire Component subclasses), which also rules
// out #[Computed] getters as a memoisation seam: render() therefore takes
// the round-trip cost (a 3-query batch + a totals SUM) on every action.

/**
 * @link ../../../../../.docs/features/recurring/architecture.md
 */
final class RecurringPage extends Component
{
    // Transfers-section disclosure state, default closed; the Blade view
    // renders the panel inside a <details> element whose open attribute
    // reflects this flag.
    public bool $transfersExpanded = false;

    public function toggleTransfers(): void
    {
        $this->transfersExpanded = ! $this->transfersExpanded;
    }

    // Dispatches via dispatchSync (see the class @link's KEK-posture
    // note) so the always-unlocked request Session's KEK is available in
    // process; short-circuits when unauthenticated as a defence-in-depth
    // check (the route is already auth-gated upstream).
    public function reDetect(CurrentUser $currentUser, Dispatcher $bus): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $bus->dispatchSync(new DetectRecurringSeriesJob($currentUser->user()->id));
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
        $view->extends('layouts.app', ['title' => 'Recurring · beatrax']);

        return $view;
    }
}
