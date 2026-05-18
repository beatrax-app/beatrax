<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

/**
 * Dispatched immediately after a forecast_scenarios row is removed by
 * the `DeleteScenario` Public Action. The cascade-on-delete FK from
 * the Wave 1 schema wipes the scenario's mutations, runs, and
 * shortfall-window rows atomically — listeners therefore only need to
 * refresh the baseline projection (the per-scenario projection rows
 * are already gone).
 */
final readonly class ScenarioDeleted
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
    ) {}
}
