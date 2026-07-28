<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

// Records the transactions a detection was built from. Both detectors wrote
// this identically, and insertOrIgnore is what makes a re-detection of the
// same cluster idempotent rather than duplicating every occurrence row.
/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final readonly class OccurrenceWriter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    /**
     * @param  list<stdClass>  $rows
     */
    public function write(int $userId, int $seriesId, array $rows, string $currency): void
    {
        if ($rows === []) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'user_id' => $userId,
                'recurring_series_id' => $seriesId,
                'transaction_id' => self::toInt($row->id),
                'observed_at' => self::toString($row->posted_at),
                'observed_amount_minor' => self::toInt($row->amount_minor),
                'observed_currency' => $currency,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->db->connection()->table('recurring_series_occurrences')->insertOrIgnore($payload);
    }
}
