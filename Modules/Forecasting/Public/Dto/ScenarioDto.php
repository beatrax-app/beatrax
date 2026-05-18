<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single forecast_scenarios row for the
 * scenario picker and the scenario CRUD panel.
 *
 * `mutationCount` is computed at query time by `ScenarioQuery`; it is
 * the number of `forecast_scenario_mutations` rows belonging to this
 * scenario.
 */
final class ScenarioDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $updatedAt,
        public readonly int $mutationCount,
    ) {}
}
