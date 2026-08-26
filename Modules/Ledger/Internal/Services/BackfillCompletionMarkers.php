<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Internal\Enums\BackfillPass;
use Modules\Ledger\Internal\Support\SweptRowSummary;
use stdClass;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#knowing-there-is-nothing-to-do-cheaply
 */
final readonly class BackfillCompletionMarkers
{
    use CoercesScalars;

    private const string TABLE = 'ledger_backfill_state';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // The row set a completed pass answered for, or null when it has never
    // completed for this user. One indexed read against a table holding at
    // most a row per pass per user, and it is what stands between an unlock
    // and building the whole codec graph.
    public function completedSummary(int $userId, BackfillPass $pass): ?SweptRowSummary
    {
        $row = $this->db->connection()
            ->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('backfill', $pass->value)
            ->first(['swept_rows', 'swept_id_sum']);

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        return new SweptRowSummary(
            self::toInt($row->swept_rows),
            self::toInt($row->swept_id_sum),
        );
    }

    // Stamped whenever a pass RAN to the end, including over a ledger it found
    // nothing to change in. A pass that was skipped for want of a key has not
    // answered anything and must leave the marker as it was.
    public function markComplete(int $userId, BackfillPass $pass, SweptRowSummary $swept): void
    {
        $this->db->connection()
            ->table(self::TABLE)
            ->updateOrInsert(
                ['user_id' => $userId, 'backfill' => $pass->value],
                [
                    'completed_at' => $this->clock->now(),
                    'swept_rows' => $swept->rows,
                    'swept_id_sum' => $swept->idSum,
                ],
            );
    }
}
