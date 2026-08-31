<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
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

    // Derived from the pair the table's own UNIQUE names, and neither half ever
    // moves: the occurrence one device records for a charge and the one its
    // peer records for the same charge are then one row rather than two.
    public static function idFor(int $seriesId, int $transactionId): int
    {
        return DerivedRowId::for('recurring_series_occurrences', [
            'recurring_series_id' => $seriesId,
            'transaction_id' => $transactionId,
        ]);
    }

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

            $payload[self::idFor($seriesId, $transactionId)] = [
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
        // has already published once.
        $held = $this->heldIds(array_keys($payload));

        $insert = [];
        foreach ($payload as $id => $columns) {
            $insert[] = ['id' => $id] + $columns;
        }

        $this->db->connection()->table('recurring_series_occurrences')->insertOrIgnore($insert);

        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        foreach ($payload as $id => $columns) {
            if (isset($held[$id])) {
                continue;
            }

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
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function heldIds(array $ids): array
    {
        $held = [];

        foreach ($this->db->connection()->table('recurring_series_occurrences')->whereIn('id', $ids)->pluck('id') as $id) {
            $held[self::toInt($id)] = true;
        }

        return $held;
    }
}
