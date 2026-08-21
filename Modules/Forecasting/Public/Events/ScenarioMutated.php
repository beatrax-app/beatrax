<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

final readonly class ScenarioMutated
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
        // 0 from RenameScenario, which pairs it with kind 'rename': there is no
        // mutation row behind that event.
        public int $mutationId,
        public string $kind,
    ) {}
}
