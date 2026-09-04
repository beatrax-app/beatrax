<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// Two devices used while apart both take the next autoincrement id, and a
// create_row naming a pk the peer already holds is discarded. That cost real
// money once: neither envelope move crossed, quarantine stayed 0, and each
// reader saw only their own. A table is safe when it mints, or is never sent.

/**
 * @return array<string, string> table => where its id comes from
 */
function mintsItsOwnRowIds(): array
{
    return [
        'anomaly_suppression_rules' => "DerivedRowId::for('anomaly_suppression_rules')",
        'chain_links' => "DerivedRowId::for('chain_links')",
        'envelope_moves' => "DerivedRowId::for('envelope_moves')",
        'system_alerts' => "DerivedRowId::for('system_alerts')",
        'transaction_splits' => "DerivedRowId::for('transaction_splits')",
        'forecast_scenario_mutations' => 'DeviceMintedRowId::mint() in AddScenarioMutation',
        'goals' => 'DeviceMintedRowId::mint() in GoalWriter',
        'migration_import_baseline' => 'DeviceMintedRowId::mint() in SourceMapWriter',
        'pot_movements' => 'DeviceMintedRowId::mint() in PotWriter',
        'saved_reports' => 'DeviceMintedRowId::mint() in SaveReport',
    ];
}

// Declared in the registry so an op for one could be merged, but no writer
// dispatches a mutation naming them, and the rules screen tells the reader so:
// "Rules stay on this device. They are not shared with your other devices."
/**
 * @return array<string, string> table => why nothing captures it
 */
function neverLeavesTheDeviceThatWroteIt(): array
{
    return [
        'categorization_rules' => 'rules.device_local_note',
        'rule_conditions' => 'a child of categorization_rules',
        'rule_actions' => 'a child of categorization_rules',
    ];
}

/**
 * @return list<string>
 */
function travellingTablesTakingIdsFromTheSequence(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $tables = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        $ddl = $connection->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

        if ($ddl === null || ! str_contains(strtolower((string) $ddl->sql), 'autoincrement')) {
            continue;
        }

        $unique = array_filter(
            $connection->select('pragma index_list('.$table.')'),
            static fn (object $index): bool => (int) $index->unique === 1 && $index->origin !== 'pk',
        );

        if ($unique === []) {
            $tables[] = $table;
        }
    }

    sort($tables);

    return $tables;
}

it('accounts for every travelling table whose pk is only the next number', function (): void {
    $accountedFor = [...array_keys(mintsItsOwnRowIds()), ...array_keys(neverLeavesTheDeviceThatWroteIt())];
    sort($accountedFor);

    expect(travellingTablesTakingIdsFromTheSequence())->toBe($accountedFor);
});

it('never captures a table that has no id of its own to send', function (): void {
    $captured = [];

    foreach (neverLeavesTheDeviceThatWroteIt() as $table => $why) {
        $sites = shell_exec(
            'grep -rl '.escapeshellarg("table: '".$table."'").' --include=*.php --exclude-dir=tests '
            .escapeshellarg(base_path('Modules')).' 2>/dev/null'
        );

        // Only a WRITER counts. A replayer test constructs ops for these
        // tables to exercise the receiving side, which is not a device
        // sending its own rows and does not mint anything.
        if (is_string($sites) && trim($sites) !== '') {
            $captured[] = $table.' ('.$why.') is captured by: '.trim($sites);
        }
    }

    expect($captured)->toBe([], implode("\n", [
        'These tables are sent to peers but take their pk from the autoincrement,',
        'so two devices used while apart mint the same id for different rows and',
        'each create is silently discarded by the other. Give the table a minted',
        'or derived id BEFORE giving it a capture site.',
        ...$captured,
    ]));
});
