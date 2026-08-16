<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

/*
 * The merge rules are a contract against the schema, and nothing enforced it.
 * A covered table or column that does not exist surfaces only at sync time,
 * as a SQL error deep inside a catch-up that then aborts and takes the rest
 * of the peer's history with it.
 */

it('covers only tables that exist, with columns that exist', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $problems = [];

    foreach ((new MergeRulesRegistry)->rules() as $table => $rule) {
        if (! $schema->hasTable($table)) {
            $problems[] = "{$table}: table does not exist";

            continue;
        }

        $columns = $schema->getColumnListing($table);

        foreach (array_keys($rule) as $field) {
            // Keys beginning with an underscore are directives, not columns.
            if (str_starts_with((string) $field, '_')) {
                continue;
            }

            if (! in_array((string) $field, $columns, true)) {
                $problems[] = "{$table}.{$field}: mergeable field is not a column";
            }
        }

        $required = $rule['_create_required'] ?? [];

        foreach (is_array($required) ? $required : [] as $column) {
            if (! in_array((string) $column, $columns, true)) {
                $problems[] = "{$table}.{$column}: _create_required names a missing column";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('can attribute every covered table to a user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    // Mirrors OpLogEntryApplier::PARENT_SCOPE — a covered table with neither a
    // user_id column nor a known parent cannot be bounded to an owner, so the
    // applier refuses to write it at all. Keep the two in step.
    $parentScoped = ['rule_conditions', 'rule_actions'];

    $unattributable = [];

    foreach (array_keys((new MergeRulesRegistry)->rules()) as $table) {
        if (! $schema->hasTable($table)) {
            continue;
        }

        if (in_array($table, $parentScoped, true)) {
            continue;
        }

        if (! $schema->hasColumn($table, 'user_id')) {
            $unattributable[] = $table;
        }
    }

    expect($unattributable)->toBe([], 'Covered but unattributable: '.implode(', ', $unattributable));
});

it('covers every table a covered table points at', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();
    $schema = $connection->getSchemaBuilder();

    // Present identically on every install, so a reference to one resolves on
    // the peer without being synced: `users` is the account itself, and the
    // shared category tree is seeded by the same deterministic seeder.
    $alwaysPresent = ['users', 'categories'];

    $covered = array_keys((new MergeRulesRegistry)->rules());
    $dangling = [];

    foreach ($covered as $table) {
        if (! $schema->hasTable($table)) {
            continue;
        }

        foreach ($connection->select("PRAGMA foreign_key_list({$table})") as $fk) {
            $target = is_string($fk->table) ? $fk->table : '';

            if ($target === '' || $target === $table) {
                continue;
            }

            if (in_array($target, $alwaysPresent, true) || in_array($target, $covered, true)) {
                continue;
            }

            $dangling[] = "{$table}.{$fk->from} -> {$target}";
        }
    }

    // A reference to an uncovered table means the peer receives a row whose
    // parent never arrives: SQLite rejects the insert and the whole catch-up
    // aborts with it.
    expect($dangling)->toBe([], "Referenced but never synced:\n  ".implode("\n  ", $dangling));
});

it('orders covered tables parents before children', function (): void {
    /** @var CoveredTableOrder $order */
    $order = app(CoveredTableOrder::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $insertion = $order->insertionOrder();
    $position = array_flip($insertion);
    $violations = [];

    foreach ($insertion as $table) {
        if (! $schema->hasTable($table)) {
            continue;
        }

        foreach ($schema->getForeignKeys($table) as $foreignKey) {
            $parent = $foreignKey['foreign_table'];

            if ($parent === $table || ! isset($position[$parent])) {
                continue;
            }

            // A child written before its parent is rejected outright, and the
            // rejection aborts the entire catch-up around it.
            if ($position[$parent] > $position[$table]) {
                $violations[] = "{$table} is written before its parent {$parent}";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});
