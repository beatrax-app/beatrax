<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\TransactionMutated;

// The one place `pair_transaction_id` is written, so the link and its
// announcement cannot come apart. Both legs used to be written by the query
// builder and told no peer, and a pair spans two accounts: the older leg's
// create op had already travelled carrying a null link, and nothing rewrote it.
final readonly class PairLinkWriter
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Container $container,
    ) {}

    // Both sides in one transaction. Uniqueness rules out a concurrent run for
    // the same user; it does not rule out a crash between two statements, and
    // that half-pair does not heal: pairOrphansForUser finds the partner while
    // counterLegOnAccount's unpairedOnly narrowing hides the leg pointing at it.
    public function link(int $userId, int $legId, int $partnerId): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $userId, $legId, $partnerId, $now): void {
            foreach ([[$legId, $partnerId], [$partnerId, $legId]] as [$rowId, $namedId]) {
                $connection
                    ->table('transactions')
                    ->where('user_id', $userId)
                    ->where('id', $rowId)
                    ->update([
                        'pair_transaction_id' => $namedId,
                        'updated_at' => $now,
                    ]);
            }
        });

        // After the commit above, never inside it: OpLogWriter opens its own
        // transaction, which inside an outer one degrades to a savepoint the
        // outer rollback would discard while the HLC had already ticked.
        $this->announce($userId, $legId, $partnerId);
        $this->announce($userId, $partnerId, $legId);
    }

    // One Set per leg, because each row names a different partner. The peer
    // may not hold the partner yet — a pair spans two imports — and the
    // applier hands such a Set to SelfReferenceDeferral rather than letting
    // the foreign key refuse it.
    private function announce(int $userId, int $rowId, int $namedId): void
    {
        $this->container->make(Dispatcher::class)->dispatch(new TransactionMutated(
            transactionId: $rowId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: ['pair_transaction_id' => $namedId],
        ));
    }
}
