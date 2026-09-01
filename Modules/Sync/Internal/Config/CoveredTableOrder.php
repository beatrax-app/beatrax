<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

use Illuminate\Database\DatabaseManager;
use Throwable;

// Orders the covered tables so parents are written before the rows that
// reference them, derived from the live foreign keys rather than a
// hand-maintained list.

// The rule groups are organised for readers, not for referential integrity:
// transactions sit in the first group and the accounts they reference in the
// third, so a peer received a transaction before the account existed and
// SQLite rejected the insert, aborting the catch-up.
final readonly class CoveredTableOrder
{
    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
    ) {}

    // Parents first. Falls back to plain registry order when the schema
    // cannot be introspected, which is no worse than the previous behaviour.
    /**
     * @return list<string>
     */
    public function insertionOrder(): array
    {
        $covered = array_keys($this->rules->rules());

        try {
            $dependencies = $this->dependencies($covered);
        } catch (Throwable) {
            return $covered;
        }

        $ordered = [];
        $placed = [];

        // Bounded: each pass places at least one table unless a cycle remains,
        // and a remaining cycle is appended in registry order rather than
        // looping forever. A counted repeat rather than a walk over $covered —
        // the values are not what is being iterated, only the count.
        $passes = count($covered);

        for ($pass = 0; $pass < $passes; $pass++) {
            $this->placeTablesWhoseParentsAreDown($covered, $dependencies, $ordered, $placed);
        }

        foreach ($covered as $table) {
            if (! isset($placed[$table])) {
                $ordered[] = $table;
            }
        }

        return $ordered;
    }

    // One settling pass: appends every table not yet placed whose parents all
    // are. Split out of insertionOrder() so the loop that repeats it reads as
    // the bound it is, rather than as a third level of nesting.
    /**
     * @param  list<string>  $covered
     * @param  array<string, list<string>>  $dependencies
     * @param  list<string>  $ordered
     * @param  array<string, bool>  $placed
     */
    private function placeTablesWhoseParentsAreDown(array $covered, array $dependencies, array &$ordered, array &$placed): void
    {
        foreach ($covered as $table) {
            if (isset($placed[$table])) {
                continue;
            }

            foreach ($dependencies[$table] as $parent) {
                if (! isset($placed[$parent])) {
                    continue 2;
                }
            }

            $ordered[] = $table;
            $placed[$table] = true;
        }
    }

    // Children first — the exact reverse, so a scoped DELETE never strands a
    // foreign key.
    /**
     * @return list<string>
     */
    public function deletionOrder(): array
    {
        return array_reverse($this->insertionOrder());
    }

    // Which column of $table points at which covered parent, for a caller that
    // must collect the parents of the rows it is about to emit rather than
    // order tables it already has. Self-references are excluded for the reason
    // insertionOrder() excludes them: no order satisfies a pair.
    /**
     * @return array<string, string> local column => parent table
     */
    public function parentColumns(string $table): array
    {
        $covered = array_keys($this->rules->rules());
        $columns = [];

        try {
            $schema = $this->db->connection()->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                return [];
            }

            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                $target = $foreignKey['foreign_table'];
                $column = $foreignKey['columns'][0] ?? null;

                if (is_string($column) && $target !== $table && in_array($target, $covered, true)) {
                    $columns[$column] = $target;
                }
            }
        } catch (Throwable) {
            // Same posture as insertionOrder(): a schema that cannot be read
            // leaves the caller where it was rather than failing the write.
            return [];
        }

        return $columns;
    }

    /**
     * @param  list<string>  $covered
     * @return array<string, list<string>>
     */
    private function dependencies(array $covered): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();
        $dependencies = [];

        foreach ($covered as $table) {
            $parents = [];

            if ($schema->hasTable($table)) {
                foreach ($schema->getForeignKeys($table) as $foreignKey) {
                    $target = $foreignKey['foreign_table'];

                    // Self-references order themselves within one table, and
                    // an uncovered target is either always present or already
                    // reported by the merge-rules schema contract.
                    if ($target !== $table && in_array($target, $covered, true)) {
                        $parents[] = $target;
                    }
                }
            }

            $dependencies[$table] = array_values(array_unique($parents));
        }

        return $dependencies;
    }
}
