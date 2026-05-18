<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

/**
 * Dispatched immediately after a new forecast_scenarios row is
 * persisted by the `CreateScenario` Public Action. Carries the user
 * id, the newly-minted scenario id, and the scenario name so a
 * downstream listener can fan out per-horizon projection jobs without
 * re-reading the row.
 *
 * Listeners (Forecasting-internal): ProjectForecastOnScenarioChange.
 */
final readonly class ScenarioCreated
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
        public string $name,
    ) {}
}
