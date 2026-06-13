<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Events;

/**
 * Dispatched after an anomaly_alerts row is inserted with state='open'.
 *
 * Carries the alert primary key, the originating transaction id, the
 * direction, the list of detector reasons the charge tripped (large /
 * first_time / duplicate), and the large-vs-typical baseline trio +
 * currency so any downstream listener (badge refresh, forecasting feed)
 * can react without re-reading the row.
 *
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
