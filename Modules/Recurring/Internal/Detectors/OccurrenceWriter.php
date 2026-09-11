<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Sync\Public\Events\EntityMutated;
use stdClass;

// Records the transactions a detection was built from. Both detectors wrote
// this identically, and insertOrIgnore is what makes a re-detection of the
// same cluster idempotent rather than duplicating every occurrence row.
final readonly class OccurrenceWriter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
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
            $transactionId = self::toInt($row->id);

            $payload[$transactionId] = [
                'user_id' => $userId,
                'recurring_series_id' => $seriesId,
                'transaction_id' => $transactionId,
                'observed_at' => self::toString($row->posted_at),
                'observed_amount_minor' => self::toInt($row->amount_minor),
                'observed_currency' => $currency,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Asked BEFORE the write: insertOrIgnore reports nothing per row, and a
        // create op for a row this device already held republishes a create it
        // has already published once. Keyed on the charge rather than on the id,
        // which is now minted and so says nothing about what the row is.
        $held = $this->heldTransactionIds($seriesId, array_keys($payload));

        // Minted, not derived: `transaction_id` is counted by each device for
        // itself, so folding it named one charge here and another there.
        // `rec_occ_uniq` is what makes two devices one row.
        $fresh = [];
        foreach ($payload as $transactionId => $columns) {
            if (isset($held[$transactionId])) {
                continue;
            }

            $fresh[DeviceMintedRowId::mint()] = $columns;
        }

        if ($fresh === []) {
            return;
        }

        $insert = [];
        foreach ($fresh as $id => $columns) {
            $insert[] = ['id' => $id] + $columns;
        }

        $this->db->connection()->table('recurring_series_occurrences')->insertOrIgnore($insert);

        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        foreach ($fresh as $id => $columns) {
            $this->events->dispatch(new EntityMutated(
                table: 'recurring_series_occurrences',
                pk: $id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: $columns,
            ));
        }
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, true>
     */
    private function heldTransactionIds(int $seriesId, array $transactionIds): array
    {
        $held = [];

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('recurring_series_id', $seriesId)
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id');

        foreach ($rows as $transactionId) {
            $held[self::toInt($transactionId)] = true;
        }

        return $held;
    }
}
