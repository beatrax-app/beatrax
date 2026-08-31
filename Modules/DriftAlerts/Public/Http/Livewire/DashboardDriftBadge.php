<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Internal\Enums\AnnualImpactTrend;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;

final class DashboardDriftBadge extends Component
{
    public function render(
        CurrentUser $currentUser,
        DriftAlertQuery $query,
        ViewFactory $views,
        CrossCurrencyTotal $fx,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $openCount = $query->openCountForUser($user);

        $reporting = $baseCurrency->forUser($user);
        $converted = $fx->of($query->openAnnualizedImpactByCurrencyForUser($user), $reporting);

        // Already a magnitude: the query counts the rises and returns what they
        // cost, so the tile reads as "potential annualized cost" without a
        // second abs() deciding the sign here. Nothing counted means nothing
        // rose, which the tile has to say rather than point an arrow at.
        return $views->make('drift-alerts::livewire.dashboard-drift-badge', [
            'openCount' => $openCount,
            'totalAnnualizedImpact' => $converted->minor,
            'totalFormatted' => Money::ofMinor($converted->minor, $reporting)->format(),
            'impactTrend' => AnnualImpactTrend::forMinor($converted->minor),
            'unconvertedCurrencies' => $converted->unconverted,
        ]);
    }
}
