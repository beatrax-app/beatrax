<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of a single forecast_scenario_mutations row.
 *
 * `kind` is the enum-string discriminator and `payload` is the
 * typed-per-kind subclass the cast hydrates the JSON column into.
 * Consuming code that needs `newAmountMinor` (etc.) must narrow the
 * payload with `instanceof` first — Larastan level 10 strict reports
 * the access otherwise (cross-kind property access guard).
 */
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
