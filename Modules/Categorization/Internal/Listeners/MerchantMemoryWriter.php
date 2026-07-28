<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Pipeline\NormalizeStage;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class MerchantMemoryWriter
{
    use CoercesScalars;

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

        // Try-insert-then-update: the UNIQUE constraint on
        // merchant_memories makes a naked INSERT race-safe. The first
        // event for a new triple wins; subsequent events catch the
        // violation and fall through to the atomic UPDATE below.
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
}
