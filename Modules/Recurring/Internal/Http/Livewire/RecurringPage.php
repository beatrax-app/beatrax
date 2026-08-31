<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

// Constructor injection is banned on Livewire components, which also rules
// out #[Computed] as a memoisation seam — so render() pays a 3-query batch
// plus a totals SUM on every action.

/**
 * @link ../../../../../.docs/features/recurring/detection-encryption-posture.md#the-two-dispatch-origins
 */
final class RecurringPage extends Component
{
    use DispatchesToast;

    public bool $transfersExpanded = false;

    public function toggleTransfers(): void
    {
        $this->transfersExpanded = ! $this->transfersExpanded;
    }

    // dispatchSync, not dispatch: the detector needs the request Session's KEK
    // in process, and a queued worker would not have it.
    public function reDetect(CurrentUser $currentUser, Dispatcher $bus): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $bus->dispatchSync(new DetectRecurringSeriesJob($currentUser->user()->id));
        $this->toast(Lang::get('recurring::index.detecting_toast'));
    }

    public function render(
        CurrentUser $currentUser,
        FixedPaymentsViewQuery $query,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();
        $sections = $query->viewForUser($user);
        $totals = $query->monthlyEquivalentTotals($user);

        $view = $views->make('recurring::livewire.recurring-page', [
            'sections' => $sections,
            'totals' => $totals,
            'transfersExpanded' => $this->transfersExpanded,
            'today' => $clock->now(),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('recurring::index.title').' · Beatrax']);

        return $view;
    }
}
