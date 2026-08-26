<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Spatie\LaravelData\Data;

// Every path resolves the balance as of today, so the DTO carries no date:
// a consumer that had to bridge from an older one is exactly how a
// statement that closed in April came to be drawn as today's position.
/**
 * @see BalanceAnchorResolver
 */
final class BalanceAnchorDto extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $openingBalanceMinor,
        public readonly string $currency,
        public readonly string $source,
    ) {}
}
