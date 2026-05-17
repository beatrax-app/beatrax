<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

/**
 * `/recurring` page — the calm grouped view of approved recurring
 * series, split into a "Recurring expenses" + "Recurring income" +
 * "Recurring transfers" (collapsed by default) layout with a single
 * net-flow summary header.
 *
 * Method-parameter DI on every action and on render — constructor
 * injection is banned on Livewire `Component` subclasses by
 * phpstan-strict-rules; the pattern mirrors the chain-review-queue
 * and recurring-review-page shapes.
 *
 * Public state:
 *  - `transfersExpanded` — false by default per D-852. The
 *    `toggleTransfers()` action flips it; the Blade view conditionally
 *    renders the (currently always empty) transfers panel based on the
 *    flag.
 *
 * Re-detection, the dashboard tile, the top-nav badge, and bulk
 * actions land in Plan 05. The toggle handler is the only mutating
 * interaction this plan ships.
 */
final class RecurringPage extends Component
{
    /**
     * Transfers-section disclosure state. Default closed per D-852;
     * the Blade view renders the panel inside a `<details>` element
     * whose `open` attribute reflects this flag.
     */
    public bool $transfersExpanded = false;

    public function toggleTransfers(): void
    {
        $this->transfersExpanded = ! $this->transfersExpanded;
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
