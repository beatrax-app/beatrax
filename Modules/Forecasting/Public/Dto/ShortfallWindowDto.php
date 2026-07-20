<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ShortfallWindowDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $accountId,
        public readonly ?int $scenarioId,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $lowestBalanceMinor,
        public readonly string $currency,
        // Captures the buffer effective at detection time so a later
        // buffer edit does not rewrite the historical shortfall narrative.
        public readonly int $bufferUsedMinor,
    ) {}
}
