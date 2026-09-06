<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

// CarryoverQuery reads assignments, moves, settings, spend and income fresh on
// every call, which is what makes a re-import, a recategorised transaction or a
// split fold correctly instead of drifting. A column holding what the fold works
// out is a second answer to the same question, and the one that goes stale.

/**
 * @return array<string, list<string>> every migrated envelope_* table, its columns sorted
 */
function envelopeStoredColumnsByTable(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $tables = [];

    foreach ($schema->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;

        if ($name === null || ! str_starts_with($name, 'envelope_')) {
            continue;
        }

        $columns = array_map(
            static fn (array $column): string => is_string($column['name']) ? $column['name'] : '',
            $schema->getColumns($name),
        );

        sort($columns);

        $tables[$name] = array_values($columns);
    }

    ksort($tables);

    return $tables;
}

/**
 * @param  array<string, list<string>>  $tables
 * @return list<string> "table.column" for each column naming a figure the fold derives
 */
function envelopeDerivedFigureColumns(array $tables): array
{
    $derived = ['balance', 'available', 'carried', 'carry', 'remaining', 'running', 'spent', 'moved', 'cached', 'total'];

    $offenders = [];

    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            foreach ($derived as $word) {
                if (str_contains($column, $word)) {
                    $offenders[] = $table.'.'.$column;

                    break;
                }
            }
        }
    }

    return $offenders;
}

it('keeps the envelope tables to the inputs the fold folds over', function (): void {
    $tables = envelopeStoredColumnsByTable();

    // Counted first: a probe that resolved no table would report a clean schema,
    // which is the same answer a clean schema gives.
    expect($tables)->not->toBeEmpty('the probe found no envelope table at all, which cannot be right');

    expect($tables)->toBe([
        'envelope_assignments' => [
            'assigned_minor', 'category_id', 'created_at', 'currency', 'id', 'period_start', 'updated_at', 'user_id',
        ],
        'envelope_moves' => [
            'amount_minor', 'category_id', 'counterpart_category_id', 'created_at', 'currency', 'id', 'kind',
            'memo', 'move_group_id', 'period_start', 'updated_at', 'user_id',
        ],
        'envelope_settings' => [
            'category_id', 'created_at', 'id', 'overspend_mode', 'threshold_percent', 'updated_at', 'user_id',
        ],
    ], implode("\n", [
        'An envelope figure is folded from these columns on every read, never kept',
        'beside them. A new column here is only safe if it is another INPUT the',
        'fold reads — an assignment, a move, a setting. If it holds a balance, a',
        'carry, a running total or anything else the fold works out, it will be',
        'wrong the first time a past transaction is edited and nothing will say so.',
        'Add a genuine input to the list above in the same commit that migrates it.',
    ]));
});

it('names no envelope column for a figure the fold works out', function (): void {
    $tables = envelopeStoredColumnsByTable();
    $columns = array_merge(...array_values($tables));

    expect(count($columns))->toBeGreaterThan(
        20,
        'Read '.count($columns).' envelope columns, too few for an empty offender list to mean anything.',
    );

    $offenders = envelopeDerivedFigureColumns($tables);

    expect($offenders)->toBe([], implode("\n", [
        'These columns name a figure CarryoverQuery derives. Storing one makes the',
        'stored copy and the fold two answers to the same question. Offenders:',
        ...$offenders,
    ]));
});

it('sees a stored balance on an envelope table that grows one', function (): void {
    // Without this the scan above passes on a schema it cannot read at all,
    // which is indistinguishable from a schema that stores nothing.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $schema->create('envelope_fold_probe', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('available_minor');
    });

    try {
        $offenders = envelopeDerivedFigureColumns(envelopeStoredColumnsByTable());
    } finally {
        $schema->drop('envelope_fold_probe');
    }

    expect($offenders)->toContain('envelope_fold_probe.available_minor');
});
