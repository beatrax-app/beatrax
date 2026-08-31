<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

final readonly class AnomalyAlertOpened
{
    /**
     * @param  list<string>  $reasons  AnomalyDetector values, canonically ordered
     */
    public function __construct(
        public int $userId,
        public int $anomalyAlertId,
        public int $transactionId,
        public string $direction,
        public array $reasons,
        public ?int $baselineAmountMinor,
        public ?int $latestAmountMinor,
        public ?string $currency,
    ) {}
}
