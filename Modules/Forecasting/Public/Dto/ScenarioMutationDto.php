<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Spatie\LaravelData\Data;

final class ScenarioMutationDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $forecastScenarioId,
        public readonly string $kind,
        public readonly ?int $targetSeriesId,
        public readonly ScenarioMutationPayload $payload,
        public readonly CarbonImmutable $createdAt,
    ) {}
}
