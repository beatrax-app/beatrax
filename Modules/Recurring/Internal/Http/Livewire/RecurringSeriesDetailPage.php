<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Actions\EditRecurringSeriesVarianceTolerance;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecurringSeriesDetailPage extends Component
{
    use DispatchesToast;

    // Locked because it arrives as a route segment, which puts it outside
    // TamperedUrlParameterContractTest's reach: that test drives #[Url]
    // properties only. Unlocked, editVarianceTolerance() wrote series 9
    // while the address bar still read /recurring/series/5.
    #[Locked]
    public int $seriesId = 0;

    public bool $showAllPoints = false;

    public function mount(int $seriesId, CurrentUser $currentUser, RecurringSeriesQuery $query): void
    {
        $this->seriesId = $seriesId;
        $series = $query->forSeries($seriesId, $currentUser->user());
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
    }

    public function toggleAllPoints(): void
    {
        $this->showAllPoints = ! $this->showAllPoints;
    }

    public function editVarianceTolerance(
        int $newTolerancePercent,
        CurrentUser $currentUser,
        EditRecurringSeriesVarianceTolerance $action,
    ): void {
        ($action)($this->seriesId, $currentUser->user(), $newTolerancePercent);
        $this->toast(Lang::get('recurring::detail.tolerance_toast', ['percent' => $newTolerancePercent]));
    }

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        RecurringOccurrenceQuery $occurrenceQuery,
        ViewFactory $views,
        CounterpartyProfileQuery $counterparties,
    ): View {
        $user = $currentUser->user();
        $series = $query->forSeries($this->seriesId, $user);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        $maxPoints = $this->showAllPoints ? 1000 : 24;
        $occurrences = $occurrenceQuery->occurrencesForSeries($this->seriesId, $user);
        $trend = $occurrenceQuery->amountTrendForSeries($this->seriesId, $user, $maxPoints);
        $apexOptions = $this->buildApexOptions($trend);

        $counterpartyId = $query->counterpartyIdForSeries($this->seriesId, $user);
        $counterpartyLink = $counterpartyId !== null
            ? $counterparties->identityForId($user, $counterpartyId)
            : null;

        $view = $views->make('recurring::livewire.recurring-series-detail-page', [
            'series' => $series,
            'occurrences' => $occurrences,
            'apexOptions' => $apexOptions,
            'showAllPoints' => $this->showAllPoints,
            'counterpartyLink' => $counterpartyLink,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('recurring::detail.page_title', ['name' => $series->displayName()])]);

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApexOptions(RecurringSeriesAmountTrendDto $trend): array
    {
        $primaryData = [];
        $shadowData = [];
        $shadowCurrency = '';
        foreach ($trend->points as $point) {
            // Its own currency, not the series header's: the divisor is not a
            // hundred everywhere, and a JPY980,000 occurrence under a euro
            // header plotted at -9,800 beside an axis labelled in yen.
            $pointCurrency = $point['currency'];
            $primaryData[] = [
                'x' => $point['date'],
                'y' => Money::majorUnits($point['amount_minor'], $pointCurrency === '' ? $trend->currency : $pointCurrency),
            ];
            if ($point['settled_amount_minor'] !== null) {
                $shadowCurrency = $point['settled_currency'] ?? '';
                $shadowData[] = [
                    'x' => $point['date'],
                    'y' => Money::majorUnits($point['settled_amount_minor'], $shadowCurrency),
                ];
            }
        }

        $series = [[
            'name' => $trend->currency,
            'data' => $primaryData,
        ]];
        if ($shadowData !== []) {
            $series[] = [
                'name' => Lang::get('recurring::detail.settled_equivalent', ['code' => $shadowCurrency]),
                'data' => $shadowData,
            ];
        }

        return [
            'chart' => [
                'type' => 'line',
                'height' => 320,
                'toolbar' => ['show' => false],
                'animations' => ['enabled' => false],
            ],
            'series' => $series,
            'xaxis' => [
                'type' => 'datetime',
            ],
            // The axis is money, and the shared chart helper formats it as
            // money only once the chart has declared one.
            'yaxis' => [
                'forceNiceScale' => true,
            ],
            'stroke' => [
                'curve' => 'straight',
                'width' => $shadowData !== [] ? [3, 2] : [3],
            ],
            'colors' => $shadowData !== [] ? ['#0f172a', '#94a3b8'] : ['#0f172a'],
            'markers' => [
                'size' => 4,
            ],
            'tooltip' => [
                'shared' => true,
                'x' => ['format' => 'dd MMM yyyy'],
            ],
            'legend' => [
                'show' => $shadowData !== [],
            ],
        ];
    }
}
