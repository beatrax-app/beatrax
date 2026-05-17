<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Pipeline\NormalizeStage;

/**
 * Listens for TransactionCategorized and grows the merchant_memories
 * table so future imports for the same normalised merchant auto-
 * suggest the chosen category.
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
 *     category_id) UNIQUE constraint. The implementation attempts an
 *     `insert` first; when the UNIQUE constraint triggers a
 *     QueryException the listener catches it and falls back to an
 *     atomic `occurrence_count + 1` UPDATE. The insert-then-catch
 *     pattern is race-safe: the DB serialises competing inserts and
 *     exactly one of N concurrent events lands the row; the rest take
 *     the UPDATE branch and increment the counter.
 *
 * Synchronous: the listener fires inside AssignCategory's request
 * cycle. There is no queued posture — memory growth is a tiny indexed
 * write and benefits from staying in the same DB transaction frame as
 * the category assignment.
 *
 * Counter semantics: the first event for a (user, merchant, category)
 * triple lands occurrence_count = 1; every subsequent event adds 1
 * via the atomic UPDATE branch. The counter advances monotonically by
 * exactly the number of distinct events dispatched, regardless of
 * dispatch order under concurrency.
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
        $userId = $event->userId;
        $categoryId = $event->categoryId;

        // Try-insert-then-update: the (user_id, merchant_id, category_id)
        // UNIQUE constraint on merchant_memories makes a naked INSERT
        // race-safe. The first event for a new triple wins the INSERT
        // and lands occurrence_count = 1; the second and subsequent
        // events catch the UNIQUE violation and fall through to the
        // atomic `occurrence_count + 1` UPDATE. This avoids the
        // check-then-insert TOCTOU window a SELECT-first approach has.
        try {
            $connection->table('merchant_memories')->insert([
                'user_id' => $userId,
                'merchant_id' => $merchantId,
                'category_id' => $categoryId,
                'occurrence_count' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }
        }

        $connection
            ->table('merchant_memories')
            ->where('user_id', $userId)
            ->where('merchant_id', $merchantId)
            ->where('category_id', $categoryId)
            ->update([
                'occurrence_count' => $connection->raw('occurrence_count + 1'),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000') {
            return true;
        }
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value');
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
