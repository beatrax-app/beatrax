<?php

declare(strict_types=1);

use Tests\Contracts\Support\AlteredColumnPredicates;

// ARetiredRowQueryAsksWhetherTheColumnIsThere holds one column of one table,
// because the sweep that wrote it swept `self_retired_at` and stopped. The
// defect it describes is not about that column: SQLite reads the double-quoted
// name of an absent column as a string LITERAL, so `whereNull` is silently
// false for every row and `whereNotNull` silently true, and the clause answers
// rather than raising. This is the same rule, keyed on nothing.
// @link ../../.docs/conventions/a-predicate-on-a-column-that-is-not-there.md

// The subject is derived, never listed: every column a migration added to a
// table that ALREADY existed. That line is the honest one. A column declared
// in the original `create` cannot be absent while its table is there, and an
// absent table raises; a column added by an ALTER is absent on every copy the
// migration has not reached yet, which the page above calls an ordinary state.
//
// It also costs nothing to keep: the set only ever grows by what a new ALTER
// adds, and the fix a reader owes is one token.

// Naming the table turns the silence into `no such column: t.c` -- measured
// against sqlite3 directly, including inside the WHERE clause of an UPDATE and
// a DELETE, where the qualified form is accepted and raises just the same.
const ALTERED_COLUMN_MITIGATIONS = 'Name the table -- whereNull(\'t.c\') raises where whereNull(\'c\') answers -- or ask with SchemaShape::missingColumns() and refuse.';

// A scan that matched nothing reports what a clean tree reports. Measured at 40
// tables, 126 columns and 79 predicates across 47 files; each floor sits below
// that -- far enough under to survive an ordinary edit, far enough over zero to
// fail a walk that stopped reading.
const ALTERED_COLUMN_TABLE_FLOOR = 30;

const ALTERED_COLUMN_FLOOR = 100;

const ALTERED_COLUMN_PREDICATE_FLOOR = 55;

// Handed to the scanner as source text rather than written into the tree: a
// guard that plants a file under the root it walks reports its own plant
// everywhere else, and the plant outlives whoever left it.
const ALTERED_COLUMN_PLANTED = <<<'PHP'
<?php
function plantedReads($connection)
{
    $bare = $connection->table('device_registry')->whereNull('epochs_delivered_at')->pluck('id');
    $named = $connection->table('device_registry')->whereNull('device_registry.epochs_delivered_at')->pluck('id');
    $declared = $connection->table('device_registry')->whereNotNull('device_id')->pluck('id');
    $held = $connection->table('device_registry')->where('user_id', 1);
    $held->whereNull('epochs_delivered_at');

    return [$bare, $named, $declared, $held];
}
PHP;

/**
 * @return list<string>
 */
function alteredColumnSilentSites(): array
{
    $silent = [];

    foreach (AlteredColumnPredicates::all() as $site) {
        if ($site['qualified'] || $site['asked']) {
            continue;
        }

        $silent[] = sprintf(
            '%s::%s -- %s(\'%s\') on %s',
            $site['path'],
            $site['function'],
            $site['method'],
            $site['column'],
            $site['table'],
        );
    }

    sort($silent);

    return $silent;
}

it('reads the columns a migration added to a table that was already there', function (): void {
    $columns = AlteredColumnPredicates::columns();
    $total = array_sum(array_map(count(...), $columns));

    expect(count($columns))->toBeGreaterThanOrEqual(
        ALTERED_COLUMN_TABLE_FLOOR,
        'The migration walk found almost no altered tables, which a broken read and a clean tree both look like.',
    );
    expect($total)->toBeGreaterThanOrEqual(ALTERED_COLUMN_FLOOR, 'The same, for the columns those tables gained.');

    // The two halves of the derivation, asserted against a table whose history
    // has one of each: `epochs_delivered_at` arrived by ALTER and is subject to
    // this rule, `device_id` was declared by the create and is not.
    expect(array_keys($columns['device_registry'] ?? []))
        ->toContain('epochs_delivered_at')
        ->toContain('self_retired_at')
        ->not->toContain('device_id');
});

it('reports a predicate that does not name its table and clears one that does', function (): void {
    $planted = AlteredColumnPredicates::in('planted.php', ALTERED_COLUMN_PLANTED, AlteredColumnPredicates::columns());

    expect(array_map(static fn (array $site): string => $site['column'], $planted))
        ->toBe(
            ['epochs_delivered_at', 'epochs_delivered_at', 'epochs_delivered_at'],
            'The walk missed one of the three predicates it was handed, so a tree full of them would report clean '
            .'for the same reason. `device_id` is the fourth and belongs to the create, so it is right to skip.',
        );

    // The third is the shape a chain read left to right cannot see: a builder
    // assigned to a variable and narrowed on a later line, which names its
    // table nowhere. It went unqualified in a shipped file under the first
    // walk written here.
    expect(array_map(static fn (array $site): bool => $site['qualified'], $planted))
        ->toBe([false, true, false], 'The scanner cannot tell a named table from an unnamed one, so its verdict says nothing.');
});

it('walks the predicates it is about to judge', function (): void {
    expect(count(AlteredColumnPredicates::all()))->toBeGreaterThanOrEqual(
        ALTERED_COLUMN_PREDICATE_FLOOR,
        'The walk found almost no predicates over an altered column, which a broken scan and a clean tree both '
        .'look like.',
    );
});

it('names the table on every predicate over a column a migration added', function (): void {
    expect(alteredColumnSilentSites())->toBe([], sprintf(
        'These narrow on a column a migration added to a table that already existed, and name neither the table '
        .'nor the schema. Where the column has not landed here, SQLite reads the quoted name as a string literal: '
        .'the clause is silently true or silently false rather than an error, and an empty set is not '
        ."distinguishable from a real one.\n  %s\n%s",
        implode("\n  ", alteredColumnSilentSites()),
        ALTERED_COLUMN_MITIGATIONS,
    ));
});
