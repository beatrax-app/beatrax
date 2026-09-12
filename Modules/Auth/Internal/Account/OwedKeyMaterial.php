<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Public\Contracts\Clock;

// What a deletion whose rows are already gone still owes the disk. The claim is
// a statement about the past the moment it commits, and the unlink it stands
// for runs afterwards, so the two facts are two columns.
/**
 * @link ../../../../.docs/conventions/a-check-another-writer-can-invalidate.md#a-claim-is-not-a-completion
 */
final readonly class OwedKeyMaterial
{
    private const string TABLE = 'account_key_purge_state';

    public function __construct(
        private UserScopedFilePurge $files,
        private Clock $clock,
    ) {}

    // Called inside the deletion transaction, which holds the write lock from
    // its BEGIN, so the read this resolves on cannot go stale before the write.
    // Keyed rather than appended because a peer can mint an id this device has
    // already deleted once, and one account owes one debt.
    public function claim(Connection $connection, int $accountId): void
    {
        $now = $this->clock->now()->toDateTimeString();

        $connection->table(self::TABLE)->updateOrInsert(
            ['account_id' => $accountId],
            ['claimed_at' => $now, 'completed_at' => null, 'updated_at' => $now],
        );
    }

    // Total by construction: every path answers for itself and a refusal comes
    // back as a name. Nothing here may throw, because both callers run where
    // there is no longer a transaction for a throw to roll back.
    /** @return list<string> the app-relative key material still on disk */
    public function settle(Connection $connection, int $accountId): array
    {
        $survivors = $this->files->keyedToTheAccount($accountId);

        if ($survivors === []) {
            $now = $this->clock->now()->toDateTimeString();

            $connection->table(self::TABLE)
                ->where('account_id', $accountId)
                ->update(['completed_at' => $now, 'updated_at' => $now]);
        }

        return $survivors;
    }

    // An id the schema has handed out again names a live account whose key
    // material is its own, so a debt against it is void rather than due:
    // unlinking there would be this defect pointed the other way.
    /** @return list<int> the accounts whose key material this device still holds */
    public function accountsStillOwed(Connection $connection): array
    {
        $accounts = [];

        $rows = $connection->table(self::TABLE)
            ->whereNull('completed_at')
            ->whereNotIn('account_id', static fn (QueryBuilder $live): QueryBuilder => $live->select('id')->from('users'))
            ->orderBy('account_id')
            ->pluck('account_id');

        foreach ($rows as $accountId) {
            if (is_numeric($accountId)) {
                $accounts[] = (int) $accountId;
            }
        }

        return $accounts;
    }
}
