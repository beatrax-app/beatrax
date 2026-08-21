<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\QueryFailure;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Public\Events\EntityMutated;

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

        // Naked INSERT first: the UNIQUE constraint makes it race-safe, so
        // competing events serialise at the DB rather than racing through the
        // check-then-insert window a SELECT-first shape would open.
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
            if (! QueryFailure::isUniqueViolation($e)) {
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

        $memoryId = $connection
            ->table('merchant_memories')
            ->where('user_id', $userId)
            ->where('merchant_id', $merchantId)
            ->where('category_id', $categoryId)
            ->value('id');

        if (! is_int($memoryId) && ! is_string($memoryId)) {
            return;
        }

        // `1` is this device's delta, not the merged column — the peer applies
        // it as an increment. The count is what breaks the tie when a merchant
        // carries more than one remembered category.
        $this->events->dispatch(new EntityMutated(
            table: 'merchant_memories',
            pk: $memoryId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: [
                'occurrence_count' => 1,
                'last_seen_at' => $now,
            ],
            incrementFields: ['occurrence_count'],
        ));
    }

    private function merchantIdFor(TransactionCategorized $event): ?int
    {
        $connection = $this->db->connection();

        $normalized = self::toString($connection
            ->table('transactions')
            ->where('user_id', $event->userId)
            ->where('id', $event->transactionId)
            ->value('counterparty_normalized'));

        if ($normalized === '' || $normalized === CounterpartyKey::NONE) {
            return null;
        }

        $merchantId = self::toInt($connection
            ->table('merchants')
            ->where('user_id', $event->userId)
            ->where('normalized_name', $normalized)
            ->value('id'));

        return $merchantId === 0 ? null : $merchantId;
    }

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
}
