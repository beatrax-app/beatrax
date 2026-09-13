<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Public\Services\JsonRowReferences;

uses(RefreshDatabase::class);

// The sibling of AnIdThatCrossesIsTranslatedOnArrivalArchTest, over the ids it
// cannot see. That walk reads `*_id` COLUMNS; a JSON value is one text column
// whatever it holds, so an account id inside a saved report's filter is
// invisible to the schema, to a foreign key, and to that guard.

// Every text column on a covered table is a candidate, because a capture puts
// every column of a row on the wire. Each is either declared in
// JsonRowReferences or written down here with what it holds instead.
const TEXT_COLUMNS_THAT_NAME_NO_COVERED_ROW = [
    'anomaly_alerts.reasons' => 'the words the evaluator flagged the row for -- "large", "duplicate", "first_time" -- and not one of them is an id',
    'chain_links.evidence' => 'what the resolver measured: matched amounts, day offsets, tolerances, and original_reference_id, which is the SELLER\'s order number and names nothing in this database',
    'counterparties.metadata' => 'the matched keyword and the merge provenance, and that provenance records the absorbed rows by name and slug rather than by id, precisely because the ids are gone',
    'forecast_scenarios.description' => 'free text the reader writes about a what-if',
    'forecast_scenario_mutations.payload' => 'the mutation itself -- currency, date, amount, direction, note. The series it applies to is target_series_id, a column of its own, declared in UNCONSTRAINED_PARENTS',
    'import_runs.row_issues' => 'one entry per refused row: kind, the row INDEX inside the file, reason and detail',
    'merchant_aliases.merged_from' => 'the OR-Set of absorbed raw descriptions, each a {v, tag} pair whose v is a pattern string',
    'migration_import_baseline.baseline_value' => 'the plaintext snapshot the three-way merge compares against -- the narrative and the payee name of one field, never a row id',
    'system_alerts.message' => 'the sentence the banner shows',
    'system_alerts.metadata' => 'what the alert was raised about: a seed key, a version, a journal mode, a translation key, an exception class',
    'tax_deduction_categories.hint' => 'the reader\'s own note on when the deduction applies',
    'transactions.field_provenance' => 'one key per protected COLUMN, mapped to the word that last wrote it ("manual", "rule"), so the keys are column names and the values are sources',
    'user_preferences.skipped_update_versions' => 'the release versions the reader dismissed an update prompt for',
    'users.community_settings' => 'one boolean per shared-list setting, keyed by the setting name',
];

// Ciphertext by the time anything could rewrite it: the applier re-seals a
// sensitive column while building the payload, and translate() runs after that.
// A sealed column carrying an id would need a different mechanism, not an entry
// in the map, so it is excluded here and would have to be argued at the seal.
/**
 * @return list<string>
 */
function jsonParentSealedColumns(): array
{
    return SensitiveFieldRegistry::columns();
}

// Every text column a peer could ever send, which is every text column of a
// covered table that is not device-local: a capture puts whole rows on the
// wire, so a column left out of the merge rules still travels as a create.
/**
 * @return list<string>
 */
function jsonParentCandidateColumns(ConnectionInterface $connection, MergeRulesRegistry $registry): array
{
    $schema = $connection->getSchemaBuilder();
    $sealed = jsonParentSealedColumns();
    $candidates = [];

    foreach (array_keys($registry->rules()) as $table) {
        if (! $schema->hasTable($table) || in_array($table, OpLogBackfiller::DEVICE_LOCAL_TABLES, true)) {
            continue;
        }

        foreach ($schema->getColumns($table) as $column) {
            $name = $table.'.'.$column['name'];

            if ($column['type_name'] === 'text' && ! in_array($name, $sealed, true)) {
                $candidates[] = $name;
            }
        }
    }

    sort($candidates);

    return $candidates;
}

/**
 * @return list<string>
 */
function jsonParentDeclaredColumns(CoveredTableOrder $order, MergeRulesRegistry $registry): array
{
    $declared = [];

    foreach (array_keys($registry->rules()) as $table) {
        foreach (array_keys($order->jsonParentColumns($table)) as $column) {
            $declared[] = $table.'.'.$column;
        }
    }

    sort($declared);

    return $declared;
}

// The walk has to SEE the columns this is about, and the map has to answer for
// them; a candidate list that already excluded every id-carrying column, or a
// map read as empty, would pass every verdict below without checking anything.
it('has a denominator to read a verdict from', function (): void {
    $candidates = jsonParentCandidateColumns(app('db')->connection(), app(MergeRulesRegistry::class));
    $declared = jsonParentDeclaredColumns(app(CoveredTableOrder::class), app(MergeRulesRegistry::class));

    expect(count($candidates))->toBeGreaterThan(10, 'the walk found almost no text column, so every verdict below is about nothing')
        ->and($candidates)->toContain('saved_reports.definition', 'transactions.auto_category_provenance')
        ->and($declared)->toContain('saved_reports.definition')
        ->and(app(CoveredTableOrder::class)->jsonParentColumns('saved_reports')['definition'] ?? [])
        ->toBe([
            'accounts.*' => 'accounts',
            'categories.*' => 'categories',
            'counterparties.*' => 'counterparties',
        ]);
});

it('classifies every column a peer could send a JSON value in', function (): void {
    $unclassified = array_values(array_diff(
        jsonParentCandidateColumns(app('db')->connection(), app(MergeRulesRegistry::class)),
        jsonParentDeclaredColumns(app(CoveredTableOrder::class), app(MergeRulesRegistry::class)),
        array_keys(TEXT_COLUMNS_THAT_NAME_NO_COVERED_ROW),
    ));

    expect($unclassified)->toBe([], implode("\n", [
        'These columns travel whole and nothing rewrites what is inside them, so',
        'an id one of them carries names whichever local row happens to hold that',
        'number on the peer:',
        ...$unclassified,
        '',
        'Declare the paths in JsonRowReferences, or -- if the value',
        'names no row in this database -- add the column to',
        'TEXT_COLUMNS_THAT_NAME_NO_COVERED_ROW here with what it holds instead.',
    ]));
});

it('keeps no entry for a column that is declared now or gone', function (): void {
    $candidates = jsonParentCandidateColumns(app('db')->connection(), app(MergeRulesRegistry::class));
    $declared = jsonParentDeclaredColumns(app(CoveredTableOrder::class), app(MergeRulesRegistry::class));

    $stale = array_values(array_filter(
        array_keys(TEXT_COLUMNS_THAT_NAME_NO_COVERED_ROW),
        static fn (string $column): bool => in_array($column, $declared, true) || ! in_array($column, $candidates, true),
    ));

    expect($stale)->toBe([], implode("\n", [
        'These are written down as carrying no covered row id, but the column is',
        'either declared in JsonRowReferences now or no longer exists, so the entry',
        'excuses nothing. Remove it:',
        ...$stale,
    ]));
});

// jsonParentColumns() drops a path whose target is not covered, so a typo there
// reads as a column with one fewer path rather than as a mistake. This is the
// arm that says so. The table a site SITS on is checked where the capture is,
// by ACaptureNamesATableTheRegistryCarriesArchTest.
it('points every declared path at a table the merge registry carries', function (): void {
    $covered = array_keys(app(MergeRulesRegistry::class)->rules());
    $declared = jsonParentDeclaredColumns(app(CoveredTableOrder::class), app(MergeRulesRegistry::class));
    $references = new JsonRowReferences;
    $uncovered = [];

    foreach ($covered as $table) {
        foreach ($references->columnsFor($table) as $column => $paths) {
            foreach ($paths as $target) {
                if (! in_array($target, $covered, true)) {
                    $uncovered[] = $table.'.'.$column.' -> '.$target;
                }
            }
        }
    }

    expect($declared)->not->toBe([], 'the declaration was not read, so agreeing with the registry means nothing')
        ->and($uncovered)->toBe([], implode("\n", [
            'These paths name a table the merge registry does not carry, so a repoint that',
            'rewrites one dispatches a capture nothing will ever announce:',
            ...$uncovered,
        ]));
});

it('points every declared path at a table a re-home can record an alias for', function (): void {
    $connection = app('db')->connection();
    $order = app(CoveredTableOrder::class);
    $covered = array_keys(app(MergeRulesRegistry::class)->rules());
    $unusable = [];

    foreach ($covered as $table) {
        foreach ($order->jsonParentColumns($table) as $column => $paths) {
            foreach ($paths as $path => $target) {
                // A target with no usable unique index is never re-homed, so no
                // alias can exist and the declaration would read as coverage.
                $indexes = $connection->select('SELECT name FROM pragma_index_list(?) WHERE "unique" = 1 AND partial = 0', [$target]);

                if ($indexes === []) {
                    $unusable[] = $table.'.'.$column.' '.$path.' -> '.$target;
                }
            }
        }
    }

    expect($unusable)->toBe([], implode("\n", [
        'These paths name a table that declares no usable natural key, so an',
        'arriving row colliding on its id is quarantined rather than re-homed,',
        'no alias is ever recorded, and the rewrite can never fire:',
        ...$unusable,
    ]));
});

it('writes a JSON parent down before the rows that name it', function (): void {
    $order = app(CoveredTableOrder::class);
    $insertion = $order->insertionOrder();
    $late = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        foreach ($order->jsonParentColumns($table) as $column => $paths) {
            foreach ($paths as $target) {
                $parentAt = array_search($target, $insertion, true);
                $childAt = array_search($table, $insertion, true);

                if ($parentAt !== false && $childAt !== false && $parentAt > $childAt) {
                    $late[] = $table.'.'.$column.' names '.$target.', which is written after it';
                }
            }
        }
    }

    // The rewrite reads the alias the parent's own arrival recorded, so a
    // parent written afterwards is a parent whose id could not be rewritten.
    expect($late)->toBe([], implode("\n", [
        'These parents are inserted after the rows naming them inside a JSON',
        'value, so the alias the rewrite needs does not exist yet:',
        ...$late,
    ]));
});
