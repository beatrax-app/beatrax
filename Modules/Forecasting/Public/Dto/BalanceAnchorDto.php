<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Spatie\LaravelData\Data;

/**
 * @see BalanceAnchorResolver
 */
final class BalanceAnchorDto extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $openingBalanceMinor,
        public readonly string $currency,
        public readonly CarbonImmutable $asOfDate,
        public readonly string $source,
    ) {}
}
