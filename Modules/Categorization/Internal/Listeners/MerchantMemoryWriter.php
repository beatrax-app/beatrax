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

final readonly class MerchantMemoryWriter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
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

        $row = $connection
            ->table('transactions')
            ->where('user_id', $event->userId)
            ->where('id', $event->transactionId)
            ->first(['counterparty_normalized', 'counterparty_name']);

        $normalized = self::toString($row->counterparty_normalized ?? null);

        if ($normalized === '' || $normalized === CounterpartyKey::NONE) {
            return null;
        }

        $merchantId = self::toInt($connection
            ->table('merchants')
            ->where('user_id', $event->userId)
            ->where('normalized_name', $normalized)
            ->value('id'));

        if ($merchantId === 0) {
            $merchantId = $this->createMerchant($event, $row, $normalized);
        }

        return $merchantId === 0 ? null : $merchantId;
    }

    // Nothing in production ever wrote this table -- only the demo seeder
    // does, despite the doc naming NormalizeStage as its owner. Never creating
    // one meant merchant_memories could never grow on a real install, so the
    // classifier's documented second layer was dead code.
    private function createMerchant(TransactionCategorized $event, ?\stdClass $row, string $normalized): int
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();
        $counterparty = self::toString($row->counterparty_name ?? null);
        $name = $counterparty === '' ? $normalized : $counterparty;

        // insertOrIgnore against the (user_id, normalized_name) UNIQUE, then
        // re-read: two categorizations of the same merchant in one burst must
        // not race into two rows, and the loser needs the winner's id.
        $inserted = $connection->table('merchants')->insertOrIgnore([
            'user_id' => $event->userId,
            'name' => $name,
            'normalized_name' => $normalized,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $merchantId = self::toInt($connection
            ->table('merchants')
            ->where('user_id', $event->userId)
            ->where('normalized_name', $normalized)
            ->value('id'));

        // merchant_memories carries a NOT NULL FK to this row, so the peer
        // needs the merchant before the memory that points at it.
        if ($merchantId !== 0 && $inserted > 0) {
            $this->events->dispatch(new EntityMutated(
                table: 'merchants',
                pk: $merchantId,
                userId: $event->userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $event->userId,
                    'name' => $name,
                    'normalized_name' => $normalized,
                ],
            ));
        }

        return $merchantId;
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
