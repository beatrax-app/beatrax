<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use RuntimeException;

// The table list is discovered from the live schema, not written down: a
// hand-kept list goes stale the first time a module adds a table, and it fails
// as silent orphaned financial data rather than a broken build.

// Ownership is the user_id column and nothing else. op_log_entries also carries
// origin_user_id, which is provenance on ANOTHER account's replicated entry --
// deleting by it would corrupt a household member's log.
final readonly class UserScopedDataPurge
{
    private const OWNERSHIP_COLUMN = 'user_id';

    // Children with no user_id of their own. The FK cascades, but only while
    // foreign keys are enforced, and a purge resting on a PRAGMA fails quietly.
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

    // Retries rather than ordering: no dependency order can be read off the
    // schema without walking every constraint. A pass that clears nothing is
    // a genuine cycle and stops the purge instead of looping.
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

    // Addressed by device id, not account, so the schema sweep cannot see it
    // and it would keep envelopes for an identity that no longer exists.
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

    // Read back off the database: this is what makes discovery safe, since a
    // table the sweep missed fails the transaction instead of surviving.
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
