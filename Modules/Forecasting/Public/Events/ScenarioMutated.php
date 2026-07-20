<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Events;

final readonly class ScenarioMutated
{
    public function __construct(
        public int $userId,
        public int $scenarioId,
        // 0 (sentinel) for events originating from a RenameScenario
        // invocation, which sets kind to the literal string 'rename' so
        // downstream consumers can tell the two surfaces apart.
        public int $mutationId,
        public string $kind,
    ) {}
}
