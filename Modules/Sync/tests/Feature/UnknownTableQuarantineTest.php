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

// replay() enforced no table allow-list, and strategyFor() defaults any unknown
// table to lww, so a compromised-but-signature-valid peer could aim a set or a
// tombstone at any table name it put on the wire — device_registry's
// ed25519_public_key_hex being a complete trust-store takeover.

function utqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-05 10:00:00');

    $this->user = utqUser('utq-user');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    // A CONFIRMED, legitimately-paired device — modelling the "compromised
    // but confirmed peer" threat: the signature is genuinely valid.
    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['utq-device' => $this->pkHex];

    // Seed a TRUSTED device_registry row — the attack target. Its
    // ed25519_public_key_hex is the key we must prove survives untouched.
    $this->trustedKey = str_repeat('a', 64);
    $this->victimDeviceRowId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $this->user->id,
        'device_id' => 'trusted-victim-device',
        'name' => 'Trusted victim device',
        'ed25519_public_key_hex' => $this->trustedKey,
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('quarantines a SET op targeting an unregistered table (device_registry) with reason unknown_table and never writes the trust store', function (): void {
    $db = $this->db;
    $userId = (int) $this->user->id;
    $attackerKeyHex = bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    $stub = new OpLogEntry(
        table: 'device_registry',
        pk: $this->victimDeviceRowId,
        field: 'ed25519_public_key_hex',
        value: json_encode($attackerKeyHex, JSON_THROW_ON_ERROR),
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
    $hostileEntry = new OpLogEntry(
        table: 'device_registry',
        pk: $this->victimDeviceRowId,
        field: 'ed25519_public_key_hex',
        value: json_encode($attackerKeyHex, JSON_THROW_ON_ERROR),
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::Set,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay([$hostileEntry], $userId);

    $keyAfter = $db->connection()
        ->table('device_registry')
        ->where('id', $this->victimDeviceRowId)
        ->value('ed25519_public_key_hex');
    expect($keyAfter)->toBe($this->trustedKey);

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'device_registry')
        ->where('reason', 'unknown_table')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);

    // The hostile op must not become durable either: anything left behind in
    // op_log_entries would come back on the next full rebuild.
    $persisted = $db->connection()
        ->table('op_log_entries')
        ->where('table_name', 'device_registry')
        ->count();
    expect($persisted)->toBe(0);
});

it('quarantines a DELETE tombstone targeting an unregistered table (device_registry) with reason unknown_table and never deletes the row', function (): void {
    $db = $this->db;
    $userId = (int) $this->user->id;

    $stub = new OpLogEntry(
        table: 'device_registry',
        pk: $this->victimDeviceRowId,
        field: '__tombstone__',
        value: null,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: $userId,
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
    $hostileTomb = new OpLogEntry(
        table: 'device_registry',
        pk: $this->victimDeviceRowId,
        field: '__tombstone__',
        value: null,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::DeleteTombstone,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay([$hostileTomb], $userId);

    expect(
        $db->connection()->table('device_registry')->where('id', $this->victimDeviceRowId)->exists()
    )->toBeTrue();

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'device_registry')
        ->where('reason', 'unknown_table')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});

it('quarantines a DELETE tombstone targeting the authoritative op-log table itself (op_log_entries) with reason unknown_table', function (): void {
    $db = $this->db;
    $userId = (int) $this->user->id;

    // Seed an existing durable op_log_entries row to prove it survives.
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => 'utq-device',
        'table_name' => 'device_registry',
        'pk' => (string) $this->victimDeviceRowId,
        'field' => 'name',
        'op_type' => 'set',
        'value' => json_encode('seed', JSON_THROW_ON_ERROR),
        'signature' => '',
        'hlc_l' => 1,
        'hlc_c' => 0,
        'recorded_at' => '2026-07-01 00:00:00',
    ]);

    $stub = new OpLogEntry(
        table: 'op_log_entries',
        pk: 1,
        field: '__tombstone__',
        value: null,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: $userId,
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
    $hostileTomb = new OpLogEntry(
        table: 'op_log_entries',
        pk: 1,
        field: '__tombstone__',
        value: null,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::DeleteTombstone,
        signature: $sig,
        userId: $userId,
    );

    $countBefore = $db->connection()->table('op_log_entries')->count();

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay([$hostileTomb], $userId);

    expect($db->connection()->table('op_log_entries')->count())->toBe($countBefore);

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'op_log_entries')
        ->where('reason', 'unknown_table')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});

it('still replays a SET op normally for a registered table (categories) — the allow-list gate does not regress legitimate merges', function (): void {
    $db = $this->db;
    $userId = (int) $this->user->id;

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Utq category',
        'slug' => 'utq-category',
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $stub = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: json_encode('Renamed category', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
    $entry = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: json_encode('Renamed category', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'utq-device',
        opType: OpType::Set,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay([$entry], $userId);

    expect(
        $db->connection()->table('categories')->where('id', $categoryId)->value('name')
    )->toBe('Renamed category');

    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('table_name', 'categories')
        ->where('reason', 'unknown_table')
        ->count();
    expect($quarantined)->toBe(0);
});
