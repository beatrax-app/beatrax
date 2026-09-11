<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Neither migration table is captured as it is written: they reach a peer only
// through the backfill, as whole-row creates. Two devices that each imported
// the same export before pairing therefore send each other a map row and a
// baseline row for entities both already hold.

// The map row was refused by its own UNIQUE and reconciled through the alias
// table. Its baseline had no UNIQUE to be refused by, so the peer's row landed
// beside the local one — and from then on the three-way merge read whichever
// of the two the query returned.

const PEER_BASELINE_MAP_PK = 7777;

const PEER_BASELINE_ROW_PK = 880000001;

function peerBaselineUser(): User
{
    return User::query()->create([
        'username' => 'peer-baseline-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function peerBaselineCategory(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'peer-baseline-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// This device's own import: the map row under an id of its own, and the
// baseline the last import wrote.
function peerBaselineMapRow(DatabaseManager $db, int $userId, int $categoryId): int
{
    return (int) $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $userId,
        'source_product' => 'ynab4',
        'source_entity_type' => 'category',
        'source_external_id' => 'cat:daily/groceries',
        'beatrax_entity_type' => 'category',
        'beatrax_id' => $categoryId,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function peerBaselineLocalBaseline(DatabaseManager $db, int $userId, int $mapId): int
{
    $db->connection()->table('migration_import_baseline')->insert([
        'id' => 990000001,
        'user_id' => $userId,
        'migration_source_map_id' => $mapId,
        'field_name' => 'name',
        'baseline_value' => 'what this device imported',
        'imported_at' => '2026-06-01 00:00:00',
    ]);

    return 990000001;
}

function peerBaselineCreate(string $table, int $pk, string $field, string $value, int $userId, int $hlc): OpLogEntry
{
    $fields = [
        'table' => $table,
        'pk' => $pk,
        'field' => $field,
        'value' => $value,
        'hlcL' => $hlc,
        'hlcC' => 0,
        'deviceId' => 'device-peer-baseline',
        'opType' => OpType::CreateRow,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$fields, 'signature' => '']);

    return new OpLogEntry(...[...$fields, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

/** @return list<OpLogEntry> */
function peerBaselineOps(int $userId, int $categoryId): array
{
    return [
        peerBaselineCreate('migration_source_map', PEER_BASELINE_MAP_PK, 'source_product', '"ynab4"', $userId, 5000),
        peerBaselineCreate('migration_source_map', PEER_BASELINE_MAP_PK, 'source_entity_type', '"category"', $userId, 5001),
        peerBaselineCreate('migration_source_map', PEER_BASELINE_MAP_PK, 'source_external_id', '"cat:daily/groceries"', $userId, 5002),
        peerBaselineCreate('migration_source_map', PEER_BASELINE_MAP_PK, 'beatrax_entity_type', '"category"', $userId, 5003),
        peerBaselineCreate('migration_source_map', PEER_BASELINE_MAP_PK, 'beatrax_id', (string) $categoryId, $userId, 5004),
        peerBaselineCreate('migration_import_baseline', PEER_BASELINE_ROW_PK, 'migration_source_map_id', (string) PEER_BASELINE_MAP_PK, $userId, 5100),
        peerBaselineCreate('migration_import_baseline', PEER_BASELINE_ROW_PK, 'field_name', '"name"', $userId, 5101),
        peerBaselineCreate('migration_import_baseline', PEER_BASELINE_ROW_PK, 'baseline_value', '"what the other device imported"', $userId, 5102),
        peerBaselineCreate('migration_import_baseline', PEER_BASELINE_ROW_PK, 'imported_at', '"2026-06-02 00:00:00"', $userId, 5103),
    ];
}

function peerBaselineAlias(DatabaseManager $db, int $userId, string $table, int $remoteId): ?string
{
    $local = $db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('remote_id', (string) $remoteId)
        ->value('local_id');

    return is_string($local) ? $local : null;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = peerBaselineUser();
    $this->categoryId = peerBaselineCategory($db, (int) $this->user->id);
    $this->mapId = peerBaselineMapRow($db, (int) $this->user->id, $this->categoryId);
    $this->baselineId = peerBaselineLocalBaseline($db, (int) $this->user->id, $this->mapId);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-peer-baseline' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    (new OpLogReplayer($db, $this->deviceKeys))->replay(
        peerBaselineOps((int) $this->user->id, $this->categoryId),
        (int) $this->user->id,
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('leaves one baseline for the field, not two', function (): void {
    $rows = $this->db->connection()->table('migration_import_baseline')
        ->where('user_id', $this->user->id)
        ->where('field_name', 'name')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->migration_source_map_id)->toBe($this->mapId)
        ->and($rows->first()->baseline_value)->toBe('what this device imported');
});

it('remembers the peer id for the baseline it already had', function (): void {
    expect(peerBaselineAlias($this->db, (int) $this->user->id, 'migration_import_baseline', PEER_BASELINE_ROW_PK))
        ->toBe((string) $this->baselineId);
});

// The map row is the leg the baseline's own reconciliation stands on: its fk
// is translated to this device's map id before the insert is tried at all.
it('remembers the peer id for the map row it already had', function (): void {
    expect(peerBaselineAlias($this->db, (int) $this->user->id, 'migration_source_map', PEER_BASELINE_MAP_PK))
        ->toBe((string) $this->mapId)
        ->and($this->db->connection()->table('migration_source_map')->where('user_id', $this->user->id)->count())->toBe(1);
});

// A refusal the alias table answers is not a loss, and must not be recorded as
// one: quarantine here is terminal in practice.
it('quarantines nothing', function (): void {
    $held = $this->db->connection()->table('op_log_quarantine')
        ->where('user_id', $this->user->id)
        ->pluck('reason')
        ->all();

    expect($held)->toBe([], 'rows were refused: '.implode(', ', array_map(strval(...), $held)));
});
