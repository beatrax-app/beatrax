<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

use Modules\Forecasting\Public\Actions\DeleteScenario;

/**
 * @see DeleteScenario
 */
final readonly class ScenarioDeleted
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
    ) {}
}
