<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;

uses(RefreshDatabase::class);

// The three-way merge asks "has the export changed since the last import", and
// only a baseline that IS the last import's can answer. Two devices that each
// imported before pairing both put their own row on the wire, and nothing in
// the schema stopped the second landing beside the first.

const ONE_BASELINE_PER_FIELD_MIGRATION = 'Modules/Migration/Database/Migrations/2026_09_11_000001_one_baseline_row_per_map_row_and_field.php';

const ONE_BASELINE_PER_FIELD_INDEX = 'migration_import_baseline_map_field_unique';

function oneBaselineMigration(): object
{
    /** @var object $migration */
    $migration = require base_path(ONE_BASELINE_PER_FIELD_MIGRATION);

    return $migration;
}

function oneBaselineUser(): User
{
    return User::query()->create([
        'username' => 'one-baseline-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function oneBaselineMapRow(DatabaseManager $db, int $userId, string $externalId): int
{
    return (int) $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $userId,
        'source_product' => 'ynab4',
        'source_entity_type' => 'category',
        'source_external_id' => $externalId,
        'beatrax_entity_type' => 'category',
        'beatrax_id' => 4242,
        'natural_key' => null,
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);
}

function oneBaselineRow(DatabaseManager $db, int $userId, int $mapId, string $field, string $value, string $importedAt, int $id): void
{
    $db->connection()->table('migration_import_baseline')->insert([
        'id' => $id,
        'user_id' => $userId,
        'migration_source_map_id' => $mapId,
        'field_name' => $field,
        'baseline_value' => $value,
        'imported_at' => $importedAt,
    ]);
}

// The state a device already in the field is in: the rows are here and the
// index is not, which is also the only way a test can build a duplicate at all.
function oneBaselineIndexDropped(DatabaseManager $db): void
{
    $db->connection()->statement('drop index if exists '.ONE_BASELINE_PER_FIELD_INDEX);
}

function oneBaselineIndexIsHere(DatabaseManager $db): bool
{
    $index = $db->connection()->selectOne(
        'select name from sqlite_master where type = ? and name = ?',
        ['index', ONE_BASELINE_PER_FIELD_INDEX],
    );

    return $index !== null;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = oneBaselineUser();
    $this->mapId = oneBaselineMapRow($db, (int) $this->user->id, 'groceries');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses a second baseline for one map row and field', function (): void {
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'Groceries', '2026-09-01 00:00:00', 9001);

    expect(fn () => oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'Boodschappen', '2026-09-02 00:00:00', 9002))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('still takes a second field on the same map row', function (): void {
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'Groceries', '2026-09-01 00:00:00', 9003);
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'kind', 'expense', '2026-09-01 00:00:00', 9004);

    expect($this->db->connection()->table('migration_import_baseline')->where('migration_source_map_id', $this->mapId)->count())
        ->toBe(2);
});

it('still takes the same field on another map row', function (): void {
    $other = oneBaselineMapRow($this->db, (int) $this->user->id, 'utilities');

    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'Groceries', '2026-09-01 00:00:00', 9005);
    oneBaselineRow($this->db, (int) $this->user->id, $other, 'name', 'Utilities', '2026-09-01 00:00:00', 9006);

    expect($this->db->connection()->table('migration_import_baseline')->count())->toBe(2);
});

it('keeps the newest of the duplicates a device is already carrying', function (): void {
    oneBaselineIndexDropped($this->db);

    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'what the first import saw', '2026-07-01 00:00:00', 9007);
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'what the last import saw', '2026-08-01 00:00:00', 9008);
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'kind', 'expense', '2026-07-01 00:00:00', 9009);

    oneBaselineMigration()->up();

    $rows = $this->db->connection()->table('migration_import_baseline')
        ->where('migration_source_map_id', $this->mapId)
        ->orderBy('field_name')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('field_name', 'name')->baseline_value)->toBe('what the last import saw')
        ->and($rows->firstWhere('field_name', 'kind')->baseline_value)->toBe('expense')
        ->and(oneBaselineIndexIsHere($this->db))->toBeTrue();
});

// The read is the other half: an index added today does not un-duplicate the
// row a peer sent yesterday, and a first-match with no order picks either one.
it('reads the newest baseline while two are still on disk', function (): void {
    oneBaselineIndexDropped($this->db);

    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'what the first import saw', '2026-07-01 00:00:00', 9010);
    oneBaselineRow($this->db, (int) $this->user->id, $this->mapId, 'name', 'what the last import saw', '2026-08-01 00:00:00', 9011);

    $baseline = app(SourceMapWriter::class)->baselineFor(
        $this->user,
        new SourceMapKey('ynab4', 'category', 'groceries'),
        'name',
    );

    expect($baseline)->toBe('what the last import saw');
});

it('advances the baseline it read, leaving no second row behind', function (): void {
    $writer = app(SourceMapWriter::class);
    $key = new SourceMapKey('ynab4', 'category', 'groceries');

    $writer->record($this->user, $key, 'category', 4242, ['name' => 'Groceries']);
    $writer->record($this->user, $key, 'category', 4242, ['name' => 'Boodschappen']);

    $rows = $this->db->connection()->table('migration_import_baseline')
        ->where('field_name', 'name')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->baseline_value)->toBe('Boodschappen');
});
