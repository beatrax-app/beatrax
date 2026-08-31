<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Support;

use Modules\Core\Public\Support\DerivedRowId;

// Not the table's UNIQUE: that names `cluster_key`, which encodes the cadence
// band and which SeriesRefresher rewrites in place. The counterparty key is
// what the detector's own cadence-flip fallback matches on, and both detectors
// group by exactly (counterparty key, currency).
/**
 * @link ../../../../.docs/features/sync/architecture.md#capture-for-the-last-five-detector-driven-tables
 */
final class DerivedSeriesId
{
    public static function for(int $userId, string $direction, string $counterpartyKey, string $currency): int
    {
        return DerivedRowId::for('recurring_series', [
            'user_id' => $userId,
            'direction' => $direction,
            'cluster_counterparty_key' => $counterpartyKey,
            'latest_currency' => $currency,
        ]);
    }
}
