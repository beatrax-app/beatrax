<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Internal\Enums\DismissedAs;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class AnomalyAlertDto extends Data
{
    /**
     * @param  list<AnomalyDetector>  $reasons  canonically ordered
     * @param  Money|null  $baselineAmount  null when no detector produced a
     *                                      comparison baseline for this charge
     */
    public function __construct(
        public readonly int $anomalyAlertId,
        public readonly int $transactionId,
        public readonly array $reasons,
        public readonly string $displayName,
        public readonly string $direction,
        public readonly string $state,
        public readonly ?Money $baselineAmount,
        public readonly Money $latestAmount,
        public readonly string $currency,
        public readonly int $sensitivityPercentUsed,
        public readonly ?DismissedAs $dismissedAs,
        public readonly CarbonImmutable $detectedAt,
        public readonly ?CarbonImmutable $actionedAt,
        public readonly ?CarbonImmutable $snoozedUntil,
    ) {}
}
