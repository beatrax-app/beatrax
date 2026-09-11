<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// Two devices mint different autoincrements for the same thing, so an id in an
// arriving payload means nothing until it is translated. `PeerRowAliases`
// translates exactly the columns `parentColumns()` names, and that reads the
// live foreign keys -- so a reference with no constraint is never rewritten.

// Nothing fails when it is missed: the column takes the peer's number and names
// whichever local row holds it. `transactions.counterparty_id` did that, and
// the constraint was declined for a reason that had nothing to do with sync.

// The id columns that name no row in a covered table, each with what it holds
// instead. Compared with toBe() in both directions, so an entry that stops
// being needed fails as loudly as a reference that stops being translated.
const IDS_THAT_NAME_NO_COVERED_ROW = [
    'categories.parent_id' => 'a category in this same table, which insertionOrder() writes before its children and which localTwinOf() reconciles by slug',
    'envelope_moves.move_group_id' => 'the uuid the two legs of one move share, minted once and never an id of a row',
    'migration_source_map.beatrax_id' => 'a row in whichever table source_entity_type names, so no single target can be declared for it -- it is the one reference here that is still untranslated',
    'migration_source_map.source_external_id' => "the source product's own identifier for the thing, which names nothing in this database",
    'transactions.pair_transaction_id' => 'the other leg, in this same table; SelfReferenceDeferral owns it and ClearHalfPairsOnMergedRows clears a pair that came apart',
];

/**
 * @return list<string> every `*_id` on a covered table that parentColumns() does not name
 */
function untranslatedIdColumns(ConnectionInterface $connection, CoveredTableOrder $order, MergeRulesRegistry $registry): array
{
    $ignored = ['id', 'user_id', 'device_id', 'origin_user_id'];
    $found = [];

    foreach (array_keys($registry->rules()) as $table) {
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            continue;
        }

        $named = $order->parentColumns($table);

        foreach ($connection->getSchemaBuilder()->getColumnListing($table) as $column) {
            if (! str_ends_with($column, '_id') || in_array($column, $ignored, true) || array_key_exists($column, $named)) {
                continue;
            }

            $found[] = $table.'.'.$column;
        }
    }

    sort($found);

    return $found;
}

it('has a denominator to read a verdict from', function (): void {
    $connection = app('db')->connection();
    $registry = app(MergeRulesRegistry::class);
    $order = app(CoveredTableOrder::class);

    expect(count($registry->rules()))->toBeGreaterThan(20, 'the registry named almost no covered table, so the walk below is over nothing')
        ->and($order->parentColumns('transactions'))
        ->toHaveKeys(['account_id', 'category_id', 'counterparty_id'], 'the walk cannot see the references it is about');

    expect(untranslatedIdColumns($connection, $order, $registry))
        ->not->toContain('transactions.account_id', 'a constrained reference reads as untranslated, so every verdict here is noise');
});

it('translates every id a peer mints, or says what the column holds instead', function (): void {
    $unaccounted = array_values(array_diff(
        untranslatedIdColumns(app('db')->connection(), app(CoveredTableOrder::class), app(MergeRulesRegistry::class)),
        array_keys(IDS_THAT_NAME_NO_COVERED_ROW),
    ));

    expect($unaccounted)->toBe([], implode("\n", [
        'These name a row by an id the peer minted, and nothing rewrites it on',
        'arrival, so the column points at whichever local row holds that number:',
        ...$unaccounted,
        '',
        'Give the column a foreign key, or — where the constraint was declined',
        'for a reason that still stands — declare it in',
        'CoveredTableOrder::UNCONSTRAINED_PARENTS. If it names no row at all,',
        'add it to IDS_THAT_NAME_NO_COVERED_ROW with what it holds instead.',
    ]));
});

it('keeps no entry for a column that is translated now', function (): void {
    $untranslated = untranslatedIdColumns(app('db')->connection(), app(CoveredTableOrder::class), app(MergeRulesRegistry::class));

    $stale = array_values(array_diff(array_keys(IDS_THAT_NAME_NO_COVERED_ROW), $untranslated));

    expect($stale)->toBe([], implode("\n", [
        'These are named as holding no covered row id, but the walk translates',
        'them now, so the entry excuses nothing. Remove it:',
        ...$stale,
    ]));
});

it('writes a declared parent down before the rows that name it', function (): void {
    $order = app(CoveredTableOrder::class);
    $insertion = $order->insertionOrder();

    $late = [];

    foreach (['transactions', 'forecast_scenario_mutations'] as $table) {
        foreach ($order->parentColumns($table) as $column => $parent) {
            $parentAt = array_search($parent, $insertion, true);
            $childAt = array_search($table, $insertion, true);

            if ($parentAt === false || $childAt === false || $parentAt < $childAt) {
                continue;
            }

            $late[] = $table.'.'.$column.' names '.$parent.', which is written after it';
        }
    }

    // Translation reads the alias the parent's own arrival recorded, so a
    // parent written afterwards is a parent whose id could not be rewritten.
    expect($late)->toBe([], implode("\n", [
        'These parents are inserted after the rows naming them, so the alias the',
        'translation needs does not exist yet:',
        ...$late,
    ]));
});
