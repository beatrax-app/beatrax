<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Dto;

use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class AnomalySuppressionRuleDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $counterpartyId,
        public readonly string $displayName,
        public readonly string $detector,
        public readonly string $direction,
        public readonly Money $bandLow,
        public readonly Money $bandHigh,
        public readonly string $currency,
    ) {}
}
