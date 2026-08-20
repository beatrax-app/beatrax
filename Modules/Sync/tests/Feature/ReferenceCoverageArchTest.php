<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\RowOwnership;

uses(RefreshDatabase::class);

/*
 * ReferenceCoverageArchTest — the drift guard for cross-user references.
 *
 * RowOwnership used to carry a hand-written list of the columns that name
 * another row. Nothing checked it against the schema, so it named eleven
 * tables while the schema had twenty-three, and pots.account_id — NOT NULL,
 * joined for display beside the account's name and IBAN — sat outside it.
 *
 * The list is derived from the live foreign keys now, which fixes the tables
 * that HAVE one. This test covers the rest: a column that names a user-scoped
 * row WITHOUT a foreign key is invisible to derivation, and the only thing
 * standing between it and a cross-user write is someone remembering. Every
 * such column must be classified, and a new one fails here rather than
 * quietly becoming the next pots.account_id.
 */

// Correlation and foreign-system identifiers: they end in _id and reference
// nothing in this database. Each is varchar, which is the tell — a row id in
// this schema is an integer — and that is asserted below rather than trusted.
const ACKNOWLEDGED_NON_REFERENCES = [
    'envelope_moves.move_group_id',
    'migration_source_map.source_external_id',
    'notification_preferences.device_id',
];

it('classifies every id-shaped column on a covered table', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $schema = $db->connection()->getSchemaBuilder();
    $ownership = new RowOwnership($db);
    $unclassified = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        if (! $schema->hasTable($table)) {
            continue;
        }

        $derived = $ownership->ownedReferences($table);
        $polymorphic = $ownership->polymorphicReferences($table);

        foreach ($schema->getColumns($table) as $column) {
            $name = $column['name'];

            if ($name === 'user_id' || ! str_ends_with($name, '_id')) {
                continue;
            }

            if (isset($derived[$name]) || isset($polymorphic[$name])) {
                continue;
            }

            if (in_array($table.'.'.$name, ACKNOWLEDGED_NON_REFERENCES, true)) {
                // A row id here is an integer, so a varchar is the evidence
                // that this names something outside the database rather than
                // a row in it. An integer would need a real justification.
                expect($column['type_name'])->toBe('varchar', $table.'.'.$name.' is acknowledged as a non-reference but is an integer');

                continue;
            }

            $unclassified[] = $table.'.'.$name;
        }
    }

    expect($unclassified)->toBe([], implode("\n  ", array_merge(
        ['These columns name a row and nothing checks who owns it.',
            'Add a foreign key (which RowOwnership derives automatically), or list the column in',
            'RowOwnership::UNENFORCED_REFERENCES / POLYMORPHIC_REFERENCES, or, if it references nothing',
            'in this database, add it to ACKNOWLEDGED_NON_REFERENCES here:'],
        $unclassified,
    )));
});

it('keeps every unenforced reference pointing at a table that exists and is user-scoped', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $schema = $db->connection()->getSchemaBuilder();
    $ownership = new RowOwnership($db);

    // A reference listed by hand is only worth listing if its target is real
    // and owner-scoped; a typo would otherwise read as coverage.
    foreach (['transactions' => 'counterparty_id', 'forecast_scenario_mutations' => 'target_series_id'] as $table => $column) {
        $target = $ownership->ownedReferences($table)[$column] ?? null;

        expect($target)->not->toBeNull($table.'.'.$column.' is no longer covered')
            ->and($schema->hasTable((string) $target))->toBeTrue()
            ->and($ownership->hasUserIdColumn((string) $target))->toBeTrue();
    }
});
