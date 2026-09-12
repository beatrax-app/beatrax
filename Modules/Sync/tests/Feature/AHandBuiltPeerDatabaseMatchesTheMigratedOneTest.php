<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class, CrossDevicePairingHarness::class);

// The cross-device harness hand-builds each device's database, because the two
// ends of a pairing have to be two stores and a migration run answers to one
// default connection. A hand-built schema is a claim about the migrations that
// nothing re-checks, and this one stopped being true: device_registry was three
// columns behind and sync_encryption_state four, for as long as nothing in a
// pairing happened to read one. What found it was a crash — a query selecting a
// column the fixture had never heard of — which is the reading a guard is for.
/**
 * @link ../../../../.docs/features/sync/cross-device-pairing-test-harness.md#the-schema-is-mirrored-and-a-guard-says-so
 */

// Column for column, because a device's own store is what the ceremony writes
// into and a narrower one answers a question production would not.
const HARNESS_TABLES_MIRRORING_PRODUCTION = [
    'pairing_tokens',
    'device_registry',
    'sync_encryption_state',
    'relay_mailbox',
];

// Deliberately partial, each for one question the harness asks. Held to a
// subset rather than a count: a migration adding a column to the ledger is
// nothing to do with these, but a column NAME the real table does not have is a
// fixture answering about a store that does not exist.
const HARNESS_TABLES_ANSWERING_ONE_QUESTION = [
    'transactions' => 'whether this device already holds rows keyed under its own blind-index key',
    'merchants' => 'the same question over the second keyed table',
    'recurring_series' => 'the same question over the third',
    'op_log_entries' => 'whether an entry was signed here or replayed in, which is the other half of that question',
];

/** @return list<string> the column names of $table on $connection, sorted */
function harnessColumnsOf(DatabaseManager $db, string $connection, string $table): array
{
    $names = array_map(
        static fn (object $column): string => (string) $column->name,
        $db->connection($connection)->select('PRAGMA table_info('.$table.')'),
    );

    sort($names);

    return $names;
}

it('builds each device a database the migrations would recognise', function (): void {
    $this->crossDevicePairingSetUp();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $wrong = [];

    foreach (HARNESS_TABLES_MIRRORING_PRODUCTION as $table) {
        // relay_mailbox is the relay's store and the other three are a device's,
        // so each is read off the connection the harness actually builds it on.
        $connection = $table === 'relay_mailbox' ? 'relay' : 'desktop';

        $mirrored = harnessColumnsOf($db, $connection, $table);
        $migrated = harnessColumnsOf($db, $db->getDefaultConnection(), $table);

        expect($migrated)->not->toBeEmpty(sprintf(
            'the migrations built no %s at all, so the comparison below would pass against nothing',
            $table,
        ));

        $missing = array_diff($migrated, $mirrored);
        $invented = array_diff($mirrored, $migrated);

        if ($missing !== [] || $invented !== []) {
            $wrong[] = sprintf(
                '%s — missing: %s; invented: %s',
                $table,
                $missing === [] ? 'none' : implode(', ', $missing),
                $invented === [] ? 'none' : implode(', ', $invented),
            );
        }
    }

    expect($wrong)->toBe([], 'A column a migration added and this fixture did not is a device whose store cannot '
        .'answer a question production can, and it surfaces as a crash in whichever test first reaches a query '
        .'that names it — never as a statement about the fixture. Add the column here: '.implode(' | ', $wrong));
});

it('keeps every narrowed table a subset of the one it stands in for', function (): void {
    $this->crossDevicePairingSetUp();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $wrong = [];

    foreach (HARNESS_TABLES_ANSWERING_ONE_QUESTION as $table => $question) {
        $mirrored = harnessColumnsOf($db, 'desktop', $table);
        $migrated = harnessColumnsOf($db, $db->getDefaultConnection(), $table);

        expect($mirrored)->not->toBeEmpty(sprintf('the harness builds no %s, so this rule reads nothing', $table));

        $invented = array_diff($mirrored, $migrated);

        if ($invented !== []) {
            $wrong[] = sprintf('%s (%s) invents: %s', $table, $question, implode(', ', $invented));
        }
    }

    expect($wrong)->toBe([], 'A narrowed fixture may leave a column out. It may not carry one the real table does '
        .'not have, because then the code under test is being asked about a store nothing ships: '.implode(' | ', $wrong));
});

// Both rules read a column list off a connection, so the reader is driven over
// the two answers it has to tell apart before either verdict is believed.
it('reads a column list off the connection it was asked about', function (): void {
    $this->crossDevicePairingSetUp();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    expect(harnessColumnsOf($db, 'desktop', 'device_registry'))->toContain('self_retired_at')
        ->and(harnessColumnsOf($db, 'desktop', 'transactions'))->not->toContain('booked_at')
        ->and(harnessColumnsOf($db, $db->getDefaultConnection(), 'transactions'))->toContain('booked_at')
        ->and(harnessColumnsOf($db, 'desktop', 'a_table_no_migration_ever_made'))->toBe(
            [],
            'a table that is not there reads as no columns, which is the answer both rules above guard against believing',
        );
});
