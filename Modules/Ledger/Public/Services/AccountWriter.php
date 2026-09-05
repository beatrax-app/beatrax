<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\EntityMutated;

// The seam every column-level write to `accounts` goes through, so the write
// and its announcement cannot come apart. The row travels as a whole-row create
// the moment a transaction names it and never again — and the currency, the
// buffer and both balance anchors are every one of them set after that create.
/**
 * @link ../../../../.docs/features/sync/architecture.md#a-captured-table-can-still-have-an-uncaptured-column
 */
final readonly class AccountWriter
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $columns  The columns to write, which are also the columns announced.
     * @return int rows written: 0 when the account is absent or not this user's.
     */
    public function write(int $userId, int $accountId, array $columns): int
    {
        if ($columns === []) {
            return 0;
        }

        $written = $this->db->connection()
            ->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->update([...$columns, 'updated_at' => $this->clock->now()->toDateTimeString()]);

        if ($written === 0) {
            return 0;
        }

        // Resolved per dispatch, never held: a singleton reaching this class
        // through its constructor would capture a dispatcher for its whole
        // life, and Event::fake() replaces the binding rather than that object.
        $this->container->make(Dispatcher::class)->dispatch(new EntityMutated(
            table: 'accounts',
            pk: $accountId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: $columns,
        ));

        return $written;
    }
}
