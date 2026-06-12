<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Public\Services\TaxTagQuery;

/**
 * Compact dashboard "Tax" summary card (D-18).
 *
 * Shows the tagged total + item count for the seasonally-computed default year.
 * Entire card is an anchor to /tax.
 *
 * Does NOT call `->extends('layouts.app')` — renders inline in the dashboard
 * (`@livewire('tax.summary-card')`), same pattern as GoalsSummaryCard.
 *
 * Method-parameter DI on render — constructor injection is banned on Livewire
 * Component subclasses by phpstan-strict-rules.
 *
 * D-22 seasonal default: January–April → previous year; May–December → current year.
 */
final class TaxSummaryCard extends Component
{
    public function render(
        CurrentUser $currentUser,
        TaxTagQuery $query,
        ViewFactory $views,
        Clock $clock,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('tax::livewire.tax-summary-card', [
                'total' => null,
                'count' => 0,
                'year' => 0,
            ]);
        }

        $now = $clock->now();
        // D-22: same seasonal rule as TaxPage::mount().
        $year = $now->month <= 4 ? $now->year - 1 : $now->year;

        $summary = $query->summaryForUser($currentUser->user()->id, $year);

        return $views->make('tax::livewire.tax-summary-card', [
            'total' => $summary->totalMinor,
            'count' => $summary->count,
            'year' => $year,
        ]);
    }
}
