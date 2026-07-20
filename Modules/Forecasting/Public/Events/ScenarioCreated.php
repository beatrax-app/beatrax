<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Forecasting\Public\Actions\CreateScenario;

/**
 * @see ProjectForecastOnScenarioChange
 * @see CreateScenario
 */
final readonly class ScenarioCreated
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
        public string $name,
    ) {}
}
