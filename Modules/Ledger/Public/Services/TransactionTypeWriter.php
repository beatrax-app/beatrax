<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Events\TransactionMutated;

// The seam a bulk retype of `transactions.type` goes through, so the rows that
// moved and the ops that say so are one list. Chain resolution used to retype in
// raw SQL — a spelling no capture guard reads — and one device then netted a
// pair of transfer legs out of its spending while its peer never heard.
final readonly class TransactionTypeWriter
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Container $container,
    ) {}

    /**
     * @param  list<int>  $transactionIds
     * @return int rows whose type actually moved — a row already carrying the
     *             target type is neither written nor announced
     */
    public function retype(int $userId, array $transactionIds, TransactionType $type): int
    {
        if ($transactionIds === []) {
            return 0;
        }

        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        /** @var list<int> $moved */
        $moved = [];

        foreach (array_chunk($transactionIds, RowChunk::DEFAULT_SIZE) as $batch) {
            $moved = [...$moved, ...$this->retypeBatch($connection, $userId, $batch, $type, $now)];
        }

        // After the commit, never inside it: OpLogWriter opens a transaction of
        // its own, which nested in an outer one degrades to a savepoint the
        // outer rollback discards while the HLC has already ticked.
        foreach ($moved as $transactionId) {
            $this->container->make(Dispatcher::class)->dispatch(new TransactionMutated(
                transactionId: $transactionId,
                userId: $userId,
                mutationType: 'edit',
                dirtyFields: ['type' => $type->value],
            ));
        }

        return count($moved);
    }

    // The ids are read back under the same predicate the UPDATE carries and in
    // the same transaction, so the announced set is the written set rather than
    // an affected-row count naming no row.
    /**
     * @param  list<int>  $batch
     * @return list<int>
     */
    private function retypeBatch(
        Connection $connection,
        int $userId,
        array $batch,
        TransactionType $type,
        string $now,
    ): array {
        /** @var list<int> $moved */
        $moved = [];

        $connection->transaction(function () use ($connection, $userId, $batch, $type, $now, &$moved): void {
            $moving = $connection->table('transactions')
                ->where('user_id', $userId)
                ->whereIn('id', $batch)
                ->where('type', '!=', $type->value)
                ->pluck('id')
                ->map(static fn (mixed $id): int => self::toInt($id))
                ->all();

            $moved = array_values($moving);

            if ($moved === []) {
                return;
            }

            $connection->table('transactions')
                ->where('user_id', $userId)
                ->whereIn('id', $moved)
                ->update([
                    'type' => $type->value,
                    'updated_at' => $now,
                ]);
        });

        return $moved;
    }
}
