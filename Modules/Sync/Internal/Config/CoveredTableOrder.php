<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
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
    // Parent references the schema cannot be asked about. Each was left without
    // a foreign key on purpose and for its own reason -- a counterparty leaving
    // must not take its transaction history with it, and a series belongs to
    // another module -- and that constraint is also what parentColumns() reads.

    // So both ids arrived holding the number the PEER minted, naming whichever
    // local row happens to hold it. Declared here rather than constrained,
    // because the reasons the constraints were declined still stand.
    /**
     * @var array<string, array<string, string>>
     */
    private const array UNCONSTRAINED_PARENTS = [
        'forecast_scenario_mutations' => ['target_series_id' => 'recurring_series'],
        'transactions' => ['counterparty_id' => 'counterparties'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private MergeRulesRegistry $rules,
        private ?LoggerInterface $log = null,
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
        } catch (Throwable $e) {
            // Plain registry order, which lists import_runs before transactions
            // — the ordering this class exists to replace, and the one that made
            // every rebuild delete a parent its children still referenced.
            $this->log?->warning('CoveredTableOrder: the schema would not answer, so the tables fall back to plain registry order.', [
                'exception' => $e::class,
            ]);

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
            foreach (self::UNCONSTRAINED_PARENTS[$table] ?? [] as $column => $target) {
                if (in_array($target, $covered, true)) {
                    $columns[$column] = $target;
                }
            }
        } catch (Throwable $e) {
            // Same posture as insertionOrder(): a schema that cannot be read
            // leaves the caller where it was rather than failing the write.

            // Empty reads as "this table names no parent", so every id in an
            // arriving payload keeps the value the peer minted for it.
            $this->log?->warning('CoveredTableOrder: the schema would not answer, so this table is treated as naming no parent.', [
                'table' => $table,
                'exception' => $e::class,
            ]);

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
        $dependencies = [];

        // Read through parentColumns() rather than the foreign keys directly,
        // so a reference declared there because it carries no constraint is
        // written down before the rows that name it, and not merely translated
        // once they are both here. Self-references and uncovered targets are
        // already excluded there, for the same two reasons.
        foreach ($covered as $table) {
            $dependencies[$table] = array_values(array_unique(array_values($this->parentColumns($table))));
        }

        return $dependencies;
    }
}
