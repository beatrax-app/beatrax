<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// The staging guard beside this one names five ledger tables by hand, so a
// sixth is outside it the day it is created — a staged goal, pot or split
// would land in the live ledger with every assertion still green. This one
// counts every table there is, before and after, and reads the difference.

beforeEach(function (): void {
    $this->stagingSnapshotUser = User::create([
        'username' => 'staging-snapshot-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->stagingSnapshotDb = app(DatabaseManager::class);
});

/**
 * @return array<string, int>
 */
function stagingSnapshotRowCounts(DatabaseManager $db): array
{
    $counts = [];

    foreach (Schema::getTables() as $table) {
        $name = (string) $table['name'];
        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }

        $counts[$name] = $db->connection()->table($name)->count();
    }

    ksort($counts);

    return $counts;
}

/**
 * @param  array<string, int>  $before
 * @param  array<string, int>  $after
 * @return list<string>
 */
function stagingSnapshotGrewTables(array $before, array $after): array
{
    $grew = [];

    foreach ($after as $name => $count) {
        if ($count !== ($before[$name] ?? 0)) {
            $grew[] = $name;
        }
    }

    return $grew;
}

it('writes only the run and its own staging tables when it parses an export', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->stagingSnapshotDb;

    $before = stagingSnapshotRowCounts($db);

    // A walk over an empty schema would report that nothing grew, which is the
    // same answer a correct parse gives.
    expect(count($before))->toBeGreaterThan(30);

    app(StartMigrationRun::class)->__invoke(
        $this->stagingSnapshotUser,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    $after = stagingSnapshotRowCounts($db);
    $grew = stagingSnapshotGrewTables($before, $after);

    // The parse has to have written something, or a staging writer that did
    // nothing at all would pass this as the quietest run there is.
    expect($grew)->not->toBeEmpty();

    $trespassed = array_values(array_filter(
        $grew,
        static fn (string $name): bool => $name !== 'migration_runs' && ! str_starts_with($name, 'migration_staging_'),
    ));

    expect($trespassed)->toBe(
        [],
        'Parsing and staging may touch migration_runs and the migration_staging_* tables and '
        .'nothing else: the reader has not agreed to anything yet, and a row written before the '
        ."preview is a row a discard cannot take back. Tables that grew:\n  "
        .implode("\n  ", $trespassed),
    );
});

it('leaves every table alone when the export is rejected', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->stagingSnapshotDb;

    $extracted = MigrationFixturePaths::extractZip(MigrationFixturePaths::corruptZip());
    $before = stagingSnapshotRowCounts($db);
    expect(count($before))->toBeGreaterThan(30);

    $thrown = null;
    try {
        app(StartMigrationRun::class)->__invoke(
            $this->stagingSnapshotUser,
            'ynab4',
            $extracted,
            'not-a-real-export.zip',
        );
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    $grew = stagingSnapshotGrewTables($before, stagingSnapshotRowCounts($db));

    expect($grew)->toBe(
        [],
        'A parse that could not read its own file has to leave the database exactly as it found '
        ."it, including the run row it opened to write staging against. Tables that grew:\n  "
        .implode("\n  ", $grew),
    );
});
