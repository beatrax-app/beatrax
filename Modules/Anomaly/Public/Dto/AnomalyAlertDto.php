<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class AnomalyAlertDto extends Data
{
    /**
     * @param  list<string>  $reasons  canonically-ordered detector keys
     */
    public function __construct(
        public readonly int $anomalyAlertId,
        public readonly int $transactionId,
        public readonly array $reasons,
        public readonly string $displayName,
        public readonly string $direction,
        public readonly string $state,
        public readonly Money $baselineAmount,
        public readonly Money $latestAmount,
        public readonly string $currency,
        public readonly int $sensitivityPercentUsed,
        public readonly ?string $dismissedAs,
        public readonly CarbonImmutable $detectedAt,
        public readonly ?CarbonImmutable $actionedAt,
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}
}
