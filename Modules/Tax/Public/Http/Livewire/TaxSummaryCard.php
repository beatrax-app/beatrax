<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Internal\Support\FilingSeason;
use Modules\Tax\Public\Services\TaxTagQuery;

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
                'unconvertedList' => '',
            ]);
        }

        $year = FilingSeason::defaultYear($clock->now());

        $summary = $query->summaryForUser($currentUser->user()->id, $year);

        // The cockpit this tile links to names the currency it could not price,
        // and a tile stating a smaller total without saying so is the two
        // screens disagreeing about money with only one of them explaining.
        return $views->make('tax::livewire.tax-summary-card', [
            'total' => $summary->totalMinor,
            'count' => $summary->count,
            'year' => $year,
            'unconvertedList' => $summary->isPartial() ? $summary->unconvertedList() : '',
        ]);
    }
}
