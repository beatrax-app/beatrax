<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

/**
 * @phpstan-type AnomalyReason 'large'|'first_time'|'duplicate'
 */
final readonly class AnomalyAlertOpened
{
    /**
     * @param  list<string>  $reasons
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
