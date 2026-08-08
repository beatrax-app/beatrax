<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;

/**
 * @link ../../../../../.docs/features/drift-alerts/architecture.md
 */
final class SubscriptionDriftWatchPage extends Component
{
    public function render(CurrentUser $currentUser, SubscriptionDriftWatchQuery $query, ViewFactory $views): View
    {
        $rows = $query->forUser($currentUser->user());

        $driftedUp = array_filter($rows, static fn ($row): bool => $row->deltaMinor > 0);

        $view = $views->make('drift-alerts::livewire.drift-watch-page', [
            'rows' => $rows,
            'trackedCount' => count($rows),
            'driftedUpCount' => count($driftedUp),
            'totalMonthlyMinor' => array_sum(array_map(static fn ($row): int => $row->monthlyEquivalentMinor, $rows)),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('drift-alerts::watch.page_title').' · Beatrax']);

        return $view;
    }
}
