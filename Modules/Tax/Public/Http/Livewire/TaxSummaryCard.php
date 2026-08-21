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
            ]);
        }

        $year = FilingSeason::defaultYear($clock->now());

        $summary = $query->summaryForUser($currentUser->user()->id, $year);

        return $views->make('tax::livewire.tax-summary-card', [
            'total' => $summary->totalMinor,
            'count' => $summary->count,
            'year' => $year,
        ]);
    }
}
