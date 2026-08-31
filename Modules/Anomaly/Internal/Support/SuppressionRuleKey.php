<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;

// What makes two suppression rules the same mute. `source_anomaly_alert_id` is
// deliberately not part of it: two alerts in one band produce one rule, so a
// column naming a single alert cannot decide whether the mute is still wanted.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md#suppression
 */
final readonly class SuppressionRuleKey
{
    /**
     * @param  list<AnomalyDetector>  $detectors
     */
    public function __construct(
        public ?int $counterpartyId,
        public string $direction,
        public int $bandLowMinor,
        public int $bandHighMinor,
        public string $currency,
        public array $detectors,
    ) {}

    public function scope(Builder $query, int $userId, AnomalyDetector $detector): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('detector', $detector->value)
            ->where('direction', $this->direction)
            ->where('amount_band_low_minor', $this->bandLowMinor)
            ->where('amount_band_high_minor', $this->bandHighMinor)
            ->where('currency', $this->currency)
            ->when(
                $this->counterpartyId === null,
                fn (Builder $q): Builder => $q->whereNull('counterparty_id'),
                fn (Builder $q): Builder => $q->where('counterparty_id', $this->counterpartyId),
            );
    }
}
