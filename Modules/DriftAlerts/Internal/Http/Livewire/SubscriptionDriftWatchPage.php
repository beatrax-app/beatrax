<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Dto\SubscriptionDriftRow;
use Modules\DriftAlerts\Public\Services\SubscriptionDriftWatchQuery;

final class SubscriptionDriftWatchPage extends Component
{
    public function render(CurrentUser $currentUser, SubscriptionDriftWatchQuery $query, ViewFactory $views): View
    {
        $user = $currentUser->user();
        $rows = $query->forUser($user);

        $driftedUp = array_filter($rows, static fn (SubscriptionDriftRow $row): bool => $row->deltaMinor > 0);

        $view = $views->make('drift-alerts::livewire.drift-watch-page', [
            'rows' => $rows,
            'trackedCount' => count($rows),
            'driftedUpCount' => count($driftedUp),
            'monthlyTotal' => $query->monthlyTotalFor($user, $rows),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('drift-alerts::watch.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }
}
