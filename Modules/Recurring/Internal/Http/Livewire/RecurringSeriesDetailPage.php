<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Recurring\Public\Actions\EditRecurringSeriesVarianceTolerance;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Constructor injection is banned on Livewire Component subclasses, so
// collaborators arrive as method parameters on mount(), action methods,
// and render(). Cross-user 404 is enforced at mount() time by re-loading
// the series and throwing NotFoundHttpException when the lookup misses.
final class RecurringSeriesDetailPage extends Component
{
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

    // Tolerance changes are not destructive (the user can simply pick
    // another value), so no Undo affordance is wired on this toast.
    public function editVarianceTolerance(
        int $newTolerancePercent,
        CurrentUser $currentUser,
        EditRecurringSeriesVarianceTolerance $action,
    ): void {
        ($action)($this->seriesId, $currentUser->user(), $newTolerancePercent);
        $this->dispatch('toast', message: 'Tolerance: '.$newTolerancePercent.'%', undoAction: '', undoPayload: null);
    }

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
        CounterpartyProfileQuery $counterparties,
    ): View {
        $user = $currentUser->user();
        $series = $query->forSeries($this->seriesId, $user);
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        $maxPoints = $this->showAllPoints ? 1000 : 24;
        $occurrences = $query->occurrencesForSeries($this->seriesId, $user);
        $trend = $query->amountTrendForSeries($this->seriesId, $user, $maxPoints);
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
        $view->extends('layouts.app', ['title' => $series->displayName().' · beatrax']);

        return $view;
    }

    /**
     * @return array<string, mixed> ApexCharts option object the Blade view injects via
     *
     *     @json. The native-currency line is always present; an EUR shadow series is
     *     appended when any point carries a non-null eur_amount_minor. Amount values convert
     *     from minor units to floats so the client-side y-axis formatter renders them;
     *     negative expense amounts stay negative (ApexCharts renders below-zero lines
     *     without extra config)
     */
    private function buildApexOptions(RecurringSeriesAmountTrendDto $trend): array
    {
        $primaryData = [];
        $shadowData = [];
        $hasShadow = false;
        foreach ($trend->points as $point) {
            $primaryData[] = [
                'x' => $point['date'],
                'y' => self::minorToMajor($point['amount_minor']),
            ];
            if ($point['eur_amount_minor'] !== null) {
                $hasShadow = true;
                $shadowData[] = [
                    'x' => $point['date'],
                    'y' => self::minorToMajor($point['eur_amount_minor']),
                ];
            }
        }

        $series = [[
            'name' => $trend->currency,
            'data' => $primaryData,
        ]];
        if ($hasShadow) {
            $series[] = [
                'name' => 'EUR equivalent',
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
            'stroke' => [
                'curve' => 'straight',
                'width' => $hasShadow ? [3, 2] : [3],
            ],
            'colors' => $hasShadow ? ['#0f172a', '#94a3b8'] : ['#0f172a'],
            'markers' => [
                'size' => 4,
            ],
            'tooltip' => [
                'shared' => true,
                'x' => ['format' => 'dd MMM yyyy'],
            ],
            'legend' => [
                'show' => $hasShadow,
            ],
        ];
    }

    private static function minorToMajor(int $minor): float
    {
        return $minor / 100;
    }
}
