<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/recurring/series/{id}` drill-in page. Renders the amount-over-time
 * ApexCharts visualisation (native-currency primary + EUR shadow when
 * the native currency is not EUR) plus the full per-occurrence table
 * linking each row to its underlying transaction.
 *
 * Constructor-injection is banned on Livewire `Component` subclasses by
 * phpstan-strict-rules; collaborators arrive as method parameters on
 * `mount()`, action methods, and `render()` — the same shape every
 * other Livewire SFC in this module follows.
 *
 * Public state:
 *  - `seriesId` — route-bound integer; the mount-time cross-user 404
 *    is the load-bearing guard.
 *  - `showAllPoints` — when true the chart re-renders with the full
 *    occurrence set instead of the 24-point default; flipped via the
 *    `toggleAllPoints` action.
 *
 * Cross-user 404 is enforced at `mount()` time by re-loading the
 * series through the Public read API and throwing
 * NotFoundHttpException when the lookup misses for the current user.
 */
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

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
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

        $view = $views->make('recurring::livewire.recurring-series-detail-page', [
            'series' => $series,
            'occurrences' => $occurrences,
            'apexOptions' => $apexOptions,
            'showAllPoints' => $this->showAllPoints,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => $series->displayName().' · diederik']);

        return $view;
    }

    /**
     * Compose the ApexCharts option object the Blade view injects via
     * `@json`. The native-currency line is always present; an EUR
     * shadow series is appended when any point carries a non-null
     * `eur_amount_minor`.
     *
     * Amount values are converted from minor units to floats so the
     * client-side chart can format them through its own y-axis
     * formatter. Negative expense amounts stay negative — ApexCharts
     * renders below-zero lines without extra config.
     *
     * @return array<string, mixed>
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
