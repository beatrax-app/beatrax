<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use RuntimeException;

// Erases every row one account owns. The table list is discovered from the
// live schema rather than written down: a hand-kept list is a list that goes
// stale the first time a module adds a table, and the failure mode is silent
// orphaned financial data rather than a broken build.

// Ownership is the user_id column and nothing else. op_log_entries also carries
// origin_user_id, which is provenance on ANOTHER account's replicated entry —
// deleting by it would corrupt a household member's log.
final readonly class UserScopedDataPurge
{
    private const OWNERSHIP_COLUMN = 'user_id';

    // Children of a user-scoped parent that carry no user_id of their own.
    // The FK cascades, but only while foreign keys are enforced, and a purge
    // that quietly depends on a PRAGMA is a purge that quietly fails.
    private const ORPHANED_CHILDREN = [
        'rule_actions' => ['rule_id', 'categorization_rules'],
        'rule_conditions' => ['rule_id', 'categorization_rules'],
    ];

    /** @param list<string> $deviceIds */
    public function __invoke(Connection $connection, int $userId, array $deviceIds): void
    {
        $tables = $this->ownedTables($connection);

        $this->sweep($connection, $tables, $userId);
        $this->sweepRelayMailbox($connection, $deviceIds);
        $this->sweepOrphanedChildren($connection);

        $connection->table('users')->where('id', $userId)->delete();

        $this->assertNothingSurvived($connection, $tables, $userId);
    }

    /** @return list<string> every table carrying a user_id, users itself excluded */
    private function ownedTables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $owned = [];

        foreach ($schema->getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

            if ($name === 'users') {
                continue;
            }

            if (in_array(self::OWNERSHIP_COLUMN, $schema->getColumnListing($name), true)) {
                $owned[] = $name;
            }
        }

        sort($owned);

        return $owned;
    }

    // Retries rather than ordering: with foreign keys enforced, deleting a
    // parent before its child raises, and there is no dependency order to be
    // read off the schema without walking every constraint. A pass that clears
    // nothing is a genuine cycle and stops the purge instead of looping.
    /**
     * @param  list<string>  $tables
     */
    private function sweep(Connection $connection, array $tables, int $userId): void
    {
        $pending = $tables;

        while ($pending !== []) {
            $blocked = [];

            foreach ($pending as $table) {
                try {
                    $connection->table($table)->where(self::OWNERSHIP_COLUMN, $userId)->delete();
                } catch (QueryException) {
                    $blocked[] = $table;
                }
            }

            if (count($blocked) === count($pending)) {
                throw new RuntimeException(
                    'UserScopedDataPurge: could not clear '.implode(', ', $blocked).' for user '.$userId.'.',
                );
            }

            $pending = $blocked;
        }
    }

    // The relay mailbox is addressed by device id, not by account, so it is
    // the one store the schema sweep cannot see. Left behind, it holds sealed
    // envelopes addressed to an identity that no longer exists.
    /** @param list<string> $deviceIds */
    private function sweepRelayMailbox(Connection $connection, array $deviceIds): void
    {
        if ($deviceIds === [] || ! $connection->getSchemaBuilder()->hasTable('relay_mailbox')) {
            return;
        }

        $connection->table('relay_mailbox')
            ->whereIn('recipient_did', $deviceIds)
            ->orWhereIn('sender_did', $deviceIds)
            ->delete();
    }

    private function sweepOrphanedChildren(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        foreach (self::ORPHANED_CHILDREN as $table => [$foreignKey, $parent]) {
            if (! $schema->hasTable($table) || ! $schema->hasTable($parent)) {
                continue;
            }

            $connection->table($table)
                ->whereNotIn($foreignKey, static fn (QueryBuilder $query): QueryBuilder => $query->select('id')->from($parent))
                ->delete();
        }
    }

    // The post-condition, read back off the database. This is what makes the
    // discovery approach safe: a table the sweep could not clear fails the
    // whole transaction rather than leaving a stranger's ledger on the disk.
    /**
     * @param  list<string>  $tables
     */
    private function assertNothingSurvived(Connection $connection, array $tables, int $userId): void
    {
        $survivors = [];

        foreach ($tables as $table) {
            $count = $connection->table($table)->where(self::OWNERSHIP_COLUMN, $userId)->count();

            if ($count > 0) {
                $survivors[] = $table.' ('.$count.')';
            }
        }

        if ($connection->table('users')->where('id', $userId)->exists()) {
            $survivors[] = 'users (1)';
        }

        if ($survivors !== []) {
            throw new RuntimeException(
                'UserScopedDataPurge: data survived the purge of user '.$userId.': '.implode(', ', $survivors).'.',
            );
        }
    }
}
