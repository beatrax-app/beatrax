<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DriftAlerts\Public\Dto\SavingsInsight;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;

final class SavingsInsightsCard extends Component
{
    public function dismiss(string $key, CurrentUser $currentUser, SavingsInsightsQuery $query): void
    {
        $query->dismiss($currentUser->user(), $key);
    }

    // The order is the conversion: "ways to save" ranks by what each costs the
    // reader, so a card holding three currencies was ranked through rates it
    // never named. Derived here rather than off the query, whose facts cache
    // outlives the request the reporting currency and the rates belong to.
    public function render(
        CurrentUser $currentUser,
        SavingsInsightsQuery $query,
        ViewFactory $views,
        CrossCurrencyTotal $fx,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $insights = $query->forUser($user);

        $reporting = $baseCurrency->forUser($user);
        $rates = $fx->ratesTo(
            array_map(static fn (SavingsInsight $insight): string => $insight->currency, $insights),
            $reporting,
        );

        $unconverted = [];
        foreach ($insights as $insight) {
            if ($insight->currency !== $reporting && ! $rates->has($insight->currency)) {
                $unconverted[$insight->currency] = true;
            }
        }

        return $views->make('drift-alerts::livewire.savings-insights-card', [
            'insights' => $insights,
            'conversion' => ConversionDisclosure::of($rates, array_keys($unconverted)),
        ]);
    }
}
