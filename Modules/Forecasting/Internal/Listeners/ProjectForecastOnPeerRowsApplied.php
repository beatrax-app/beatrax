<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Listeners;

use Modules\Forecasting\Internal\Support\ForecastReprojection;
use Modules\Sync\Public\Events\PeerRowsApplied;

// The arrival side of the four listeners beside this one. forecast_runs and
// forecast_shortfall_windows are derived and device-local while every input
// those listeners fire on travels, so a series a household member approved
// reached this device's tables and none of its forecasts until the daily sweep.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#what-an-arriving-row-announces
 */
final readonly class ProjectForecastOnPeerRowsApplied
{
    private const string SCENARIOS = 'forecast_scenarios';

    // A series or a drift alert moves every line on the page. So does a
    // scenario mutation: it names the scenario it belongs to in a column, and
    // an announcement carries a table and a pk, never a column — so an
    // arriving one cannot say which single scenario it changed.
    private const array REACHING_EVERY_SCENARIO = [
        'drift_alerts',
        'forecast_scenario_mutations',
        'recurring_series',
    ];

    public function __construct(private ForecastReprojection $reprojection) {}

    public function handle(PeerRowsApplied $event): void
    {
        if ($event->touchedAnyOf(self::REACHING_EVERY_SCENARIO)) {
            $this->reprojection->everything($event->userId);

            return;
        }

        if (! $event->touchedAnyOf([self::SCENARIOS])) {
            return;
        }

        // The baseline and the arriving scenarios only, never every scenario
        // the reader owns — the same fan-out the local scenario listener has.
        // A tombstoned scenario names nothing left to project.
        $this->reprojection->baseline($event->userId);

        foreach ($this->scenariosStillHere($event) as $scenarioId) {
            $this->reprojection->scenario($event->userId, $scenarioId);
        }
    }

    /**
     * @return list<int>
     */
    private function scenariosStillHere(PeerRowsApplied $event): array
    {
        $named = array_merge(
            $event->created[self::SCENARIOS] ?? [],
            $event->updated[self::SCENARIOS] ?? [],
        );

        return array_values(array_unique(array_map(intval(...), $named)));
    }
}
