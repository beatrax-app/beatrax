<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Read-side projection of one forecast_shortfall_windows row.
 *
 * `lowestBalanceMinor` is the signed bottom of the window (negative
 * = overdraft). `bufferUsedMinor` captures the buffer effective at
 * detection time so a later buffer edit does not rewrite the
 * historical shortfall narrative.
 */
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
        public readonly int $bufferUsedMinor,
    ) {}
}
