<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Spatie\LaravelData\Data;

/**
 * @see ScenarioQuery
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
