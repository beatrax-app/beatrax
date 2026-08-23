<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Internal\Enums\BackfillPass;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#the-backfill
 */
final readonly class BackfillCompletionMarkers
{
    private const string TABLE = 'ledger_backfill_state';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    // One indexed read against a table holding at most a row per pass per user.
    // It is what stands between an unlock and building the whole codec graph to
    // learn there is nothing left to convert.
    public function isComplete(int $userId, BackfillPass $pass): bool
    {
        return $this->db->connection()
            ->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('backfill', $pass->value)
            ->exists();
    }

    // Stamped whenever a pass RAN to the end, including over a ledger it found
    // nothing to change in. A pass that was skipped for want of a key has not
    // answered anything and must leave the marker unwritten.
    public function markComplete(int $userId, BackfillPass $pass): void
    {
        $this->db->connection()
            ->table(self::TABLE)
            ->updateOrInsert(
                ['user_id' => $userId, 'backfill' => $pass->value],
                ['completed_at' => $this->clock->now()],
            );
    }
}
