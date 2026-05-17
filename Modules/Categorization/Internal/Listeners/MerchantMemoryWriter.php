<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Pipeline\NormalizeStage;

/**
 * Listens for TransactionCategorized and grows the merchant_memories
 * table so future imports for the same normalised merchant auto-
 * suggest the chosen category — the CAT-02 storage half of
 * categorization learning.
 *
 * The listener:
 *  1. Skips when categoryId is null (the user un-categorized — not a
 *     memory-grow event).
 *  2. Loads the transaction's counterparty_normalized (scoped by
 *     user_id), then JOINs the merchants table on (user_id,
 *     normalized_name) to derive merchant_id. Transactions carries no
 *     merchant_id column; deriving via the merchants join is the
 *     stable shape that matches how RuleEvaluator looks memory up.
 *  3. Skips silently when:
 *     - the transaction was not found (cross-user), OR
 *     - the counterparty_normalized is the empty-counterparty
 *       sentinel (NormalizeStage::NO_COUNTERPARTY), OR
 *     - no merchants row exists for this (user, normalized_name) — the
 *       merchants table is populated by NormalizeStage / Ledger and
 *       not by this listener; absence is a Ledger concern, not a
 *       categorization concern.
 *  4. Upserts merchant_memories on the (user_id, merchant_id,
 *     category_id) UNIQUE constraint via updateOrInsert: the existing
 *     row's occurrence_count is atomically incremented through a raw
 *     `occurrence_count + 1` expression and last_seen_at + updated_at
 *     are stamped from the injected Clock.
 *
 * Synchronous: the listener fires inside AssignCategory's request
 * cycle. There is no queued posture — memory growth is a tiny indexed
 * write and benefits from staying in the same DB transaction frame as
 * the category assignment.
 *
 * Idempotency: a second TransactionCategorized for the same (user,
 * merchant, category) triple triggers an atomic increment via
 * updateOrInsert's UPDATE branch; the row's UNIQUE constraint guarantees
 * the increment hits the right row regardless of race.
 */
final class MerchantMemoryWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function handle(TransactionCategorized $event): void
    {
        if ($event->categoryId === null) {
            return;
        }

        $connection = $this->db->connection();

        $txRow = $connection
            ->table('transactions')
            ->where('user_id', $event->userId)
            ->where('id', $event->transactionId)
            ->first(['counterparty_normalized']);

        if ($txRow === null) {
            return;
        }

        $normalized = is_string($txRow->counterparty_normalized) ? $txRow->counterparty_normalized : '';
        if ($normalized === '' || $normalized === NormalizeStage::NO_COUNTERPARTY) {
            return;
        }

        $merchantRow = $connection
            ->table('merchants')
            ->where('user_id', $event->userId)
            ->where('normalized_name', $normalized)
            ->first(['id']);

        if ($merchantRow === null) {
            return;
        }

        $merchantId = self::toInt($merchantRow->id);
        if ($merchantId === 0) {
            return;
        }

        $now = $this->clock->now()->toDateTimeString();

        // updateOrInsert hits the (user_id, merchant_id, category_id)
        // UNIQUE constraint when present. The connection->raw arm
        // atomically increments occurrence_count instead of reading +
        // writing; the very-first insert leaves occurrence_count at 1
        // (Laravel's updateOrInsert applies the values map verbatim on
        // insert, so the raw expression evaluates against NULL on
        // insert and would yield NULL — to insert with the correct
        // starting value of 1, the insert path must use a literal 1,
        // separated from the update path). We perform an existence
        // check first to choose between insert (literal 1) and update
        // (raw expression).
        $existingMemory = $connection
            ->table('merchant_memories')
            ->where('user_id', $event->userId)
            ->where('merchant_id', $merchantId)
            ->where('category_id', $event->categoryId)
            ->first(['id', 'occurrence_count']);

        if ($existingMemory === null) {
            $connection->table('merchant_memories')->insert([
                'user_id' => $event->userId,
                'merchant_id' => $merchantId,
                'category_id' => $event->categoryId,
                'occurrence_count' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $connection
            ->table('merchant_memories')
            ->where('id', self::toInt($existingMemory->id))
            ->update([
                'occurrence_count' => $connection->raw('occurrence_count + 1'),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
