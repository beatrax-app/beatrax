<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Sync\Public\Events\EntityMutated;

/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
final class MerchantMemoryWriter
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function handle(TransactionCategorized $event): void
    {
        $merchantId = $event->categoryId === null ? null : $this->merchantIdFor($event);
        if ($merchantId === null) {
            return;
        }

        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();
        $userId = $event->userId;
        $categoryId = $event->categoryId;

        // Try-insert-then-update: the UNIQUE constraint on
        // merchant_memories makes a naked INSERT race-safe. The first
        // event for a new triple wins; subsequent events catch the
        // violation and fall through to the atomic UPDATE below.
        try {
            $memoryId = $connection->table('merchant_memories')->insertGetId([
                'user_id' => $userId,
                'merchant_id' => $merchantId,
                'category_id' => $categoryId,
                'occurrence_count' => 1,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The merchant goes first: merchant_memories carries a NOT NULL
            // merchant_id, so a memory arriving at a peer that has never seen
            // the merchant fails the foreign key.
            $this->captureMerchant($merchantId, $userId);

            $this->events->dispatch(new EntityMutated(
                table: 'merchant_memories',
                pk: $memoryId,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $userId,
                    'merchant_id' => $merchantId,
                    'category_id' => $categoryId,
                    'occurrence_count' => 1,
                    'last_seen_at' => $now,
                ],
            ));

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

    // The merchant this categorisation should remember against, or null when
    // there is nothing to remember: the transaction is gone, carries no
    // counterparty, or matches no merchant row. value() rather than first() so
    // a missing row and a missing column are one answer.
    private function merchantIdFor(TransactionCategorized $event): ?int
    {
        $connection = $this->db->connection();

        $normalized = self::toString($connection
            ->table('transactions')
            ->where('user_id', $event->userId)
            ->where('id', $event->transactionId)
            ->value('counterparty_normalized'));

        if ($normalized === '' || $normalized === NormalizeStage::NO_COUNTERPARTY) {
            return null;
        }

        $merchantId = self::toInt($connection
            ->table('merchants')
            ->where('user_id', $event->userId)
            ->where('normalized_name', $normalized)
            ->value('id'));

        return $merchantId === 0 ? null : $merchantId;
    }

    // What this device learned about a merchant is the user's own
    // categorising, so it travels. The merchant row itself is emitted with it
    // because the memory's foreign key names it.
    private function captureMerchant(int $merchantId, int $userId): void
    {
        $merchant = $this->db->connection()
            ->table('merchants')
            ->where('id', $merchantId)
            ->where('user_id', $userId)
            ->first(['name', 'normalized_name', 'default_category_id']);

        if ($merchant === null) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'merchants',
            pk: $merchantId,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $userId,
                'name' => $merchant->name,
                'normalized_name' => $merchant->normalized_name,
                'default_category_id' => $merchant->default_category_id,
            ],
        ));
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
