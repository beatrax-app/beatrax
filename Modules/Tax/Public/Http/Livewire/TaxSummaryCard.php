<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
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

        // TaxPage::mount() resolves the same seasonal default; the card and the
        // page it links to have to agree.
        $now = $clock->now();
        $year = $now->month <= 4 ? $now->year - 1 : $now->year;

        $summary = $query->summaryForUser($currentUser->user()->id, $year);

        return $views->make('tax::livewire.tax-summary-card', [
            'total' => $summary->totalMinor,
            'count' => $summary->count,
            'year' => $year,
        ]);
    }
}
