<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
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

        // The tile presents the absolute magnitude, so it reads as "potential
        // annualized cost" rather than as a negative movement.
        return $views->make('drift-alerts::livewire.dashboard-drift-badge', [
            'openCount' => $openCount,
            'totalAnnualizedImpact' => $converted->minor,
            'totalFormatted' => Money::ofMinor(abs($converted->minor), $reporting)->format(),
            'unconvertedCurrencies' => $converted->unconverted,
        ]);
    }
}
