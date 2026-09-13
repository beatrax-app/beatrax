<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

// Orders the covered tables so parents are written before the rows that
// reference them, read off the live foreign keys wherever they answer and off
// the declarations below where a constraint was declined -- never off a
// hand-kept list of all of them.

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

    // The same references, one level further out of reach: the ids are keys
    // inside a JSON value, so the column is one opaque blob to a foreign key
    // and to every schema read. A saved report's account filter decides which
    // money the figure counts, so the peer's number is a different report.
    /**
     * @var array<string, array<string, array<string, string>>> table => column => (path => covered table)
     *
     * @link ../../../../.docs/features/sync/op-log-merge-rules.md#references-that-live-inside-a-json-value
     */
    private const array JSON_PARENTS = [
        'saved_reports' => [
            'definition' => [
                'accounts.*' => 'accounts',
                'categories.*' => 'categories',
                'counterparties.*' => 'counterparties',
            ],
        ],
        // `rule_id` is deliberately absent: categorization_rules is device-local,
        // so no create for one ever arrives, no alias can exist, and declaring
        // it would read as coverage where none is possible.
        'transactions' => [
            'auto_category_provenance' => [
                'category_id' => 'categories',
                'memory_id' => 'merchant_memories',
            ],
            'enriched_from' => ['*.import_run_id' => 'import_runs'],
        ],
        'user_preferences' => [
            'calendar_entries_accounts' => ['*' => 'accounts'],
            'calendar_balance_accounts' => ['*' => 'accounts'],
        ],
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
        try {
            return $this->parentColumnsOrThrow($table);
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
    }

    // The JSON columns of $table, each mapped to the paths inside it that name
    // a covered row. A path is dot-separated; `*` stands for every element of
    // a list, so `accounts.*` is the account filter and `*.import_run_id` the
    // run named by each entry of a provenance list.
    /**
     * @return array<string, array<string, string>> column => (path => the covered table it names)
     */
    public function jsonParentColumns(string $table): array
    {
        $covered = array_keys($this->rules->rules());
        $columns = [];

        foreach (self::JSON_PARENTS[$table] ?? [] as $column => $paths) {
            $declared = array_filter($paths, static fn (string $target): bool => in_array($target, $covered, true));

            if ($declared !== []) {
                $columns[$column] = $declared;
            }
        }

        return $columns;
    }

    // The same question, raising rather than answering nothing. dependencies()
    // asks it this way so a schema that will not answer reaches insertionOrder()
    // and is reported there as the fallback it really is, instead of thirty-nine
    // tables each quietly reporting that they name no parent.
    /**
     * @return array<string, string> column => the covered table it names
     *
     * @throws Throwable when the schema will not answer
     */
    private function parentColumnsOrThrow(string $table): array
    {
        $covered = array_keys($this->rules->rules());
        $columns = [];
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

        return $columns;
    }

    /**
     * @param  list<string>  $covered
     * @return array<string, list<string>>
     */
    private function dependencies(array $covered): array
    {
        $dependencies = [];

        // Read through parentColumns() rather than the foreign keys directly, so
        // a reference declared there because it carries no constraint is written
        // down before the rows naming it, not merely translated once both are
        // here. Self-references and uncovered targets are excluded there.

        // A JSON parent joins them for the same reason: the alias a rewrite
        // reads is recorded when the parent's own create lands, so a parent
        // written afterwards is one whose id could not be rewritten.
        foreach ($covered as $table) {
            $dependencies[$table] = array_values(array_unique([
                ...array_values($this->parentColumnsOrThrow($table)),
                ...$this->jsonParentTargets($table),
            ]));
        }

        return $dependencies;
    }

    // Self-references are dropped for the reason parentColumnsOrThrow() drops
    // them: no order satisfies a table that is its own parent.
    /**
     * @return list<string>
     */
    private function jsonParentTargets(string $table): array
    {
        $targets = [];

        foreach ($this->jsonParentColumns($table) as $paths) {
            foreach ($paths as $target) {
                if ($target !== $table) {
                    $targets[] = $target;
                }
            }
        }

        return $targets;
    }
}
