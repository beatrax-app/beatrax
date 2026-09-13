<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// `op_log_row_aliases` is peer-scoped: a row says "device D's id R is our id
// L". The rebuild's loss check read only the R column off it, so any peer's
// alias for ITS row 4218 answered for THIS device's deleted row 4218, and an
// alias whose L names nothing still counted as a row that came back.

// `PeerRowAliases::localFor()` — the only other reader — matches on the device
// and returns the local id, which is what makes the two readers disagree.
const AAAO_DEVICE = 'alias-account-device';

/**
 * @return array{userId: int, secretKey: string}
 */
function aaaoInstall(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'alias-account-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => AAAO_DEVICE,
        'name' => 'This device',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => '2026-09-01T00:00:00+00:00',
        'confirmed_at' => '2026-09-01T00:00:00+00:00',
        'created_at' => '2026-09-01T00:00:00+00:00',
        'updated_at' => '2026-09-01T00:00:00+00:00',
    ]);

    return ['userId' => $userId, 'secretKey' => sodium_crypto_sign_secretkey($keypair)];
}

function aaaoCategory(DatabaseManager $db, int $userId, string $slug): int
{
    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => ucfirst($slug),
        'slug' => $slug,
        'kind' => 'expense',
        'created_at' => '2026-09-01 12:00:00',
        'updated_at' => '2026-09-01 12:00:00',
    ]);
}

// A create carrying `name` and neither `slug` nor `kind`, which `_create_required`
// names. The rebuild deletes the row because a create op names it and the replay
// then refuses to rebuild it — so the loss check is the only thing left looking.
function aaaoUnrebuildableCreate(DatabaseManager $db, string $secretKey, int $userId, int $pk): void
{
    $unsigned = new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: 'name',
        value: json_encode('Groceries'),
        hlcL: 1000,
        hlcC: 0,
        deviceId: AAAO_DEVICE,
        opType: OpType::CreateRow,
        signature: '',
        userId: $userId,
    );

    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => AAAO_DEVICE,
        'table_name' => 'categories',
        'pk' => (string) $pk,
        'field' => 'name',
        'op_type' => OpType::CreateRow->value,
        'value' => json_encode('Groceries'),
        'hlc_l' => 1000,
        'hlc_c' => 0,
        'signature' => new DeviceKeySigner()->sign($unsigned->signingPayload(), $secretKey),
        'recorded_at' => '2026-09-01 12:00:00',
    ]);
}

function aaaoAlias(DatabaseManager $db, int $userId, string $deviceId, int $remoteId, int|string $localId): void
{
    $db->connection()->table('op_log_row_aliases')->insert([
        'user_id' => $userId,
        'table_name' => 'categories',
        'device_id' => $deviceId,
        'remote_id' => (string) $remoteId,
        'local_id' => (string) $localId,
        'created_at' => '2026-09-01 12:00:00',
    ]);
}

it('refuses to call a row restored because another device aliased the same number', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = aaaoInstall($db);

    $categoryId = aaaoCategory($db, $install['userId'], 'groceries');
    $twin = aaaoCategory($db, $install['userId'], 'household');
    aaaoUnrebuildableCreate($db, $install['secretKey'], $install['userId'], $categoryId);

    // The phone's own row happens to wear this device's number, and the phone
    // authored no create for it here. It says nothing about the row deleted.
    aaaoAlias($db, $install['userId'], 'some-other-device', $categoryId, $twin);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->expectsOutputToContain('did not restore every row it deleted')
        ->assertFailed();

    expect($db->connection()->table('categories')->where('id', $categoryId)->exists())
        ->toBeTrue('the rebuild committed over a row it never put back');
});

it('refuses to call a row restored by an alias pointing at a row that is not there', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = aaaoInstall($db);

    $categoryId = aaaoCategory($db, $install['userId'], 'groceries');
    aaaoUnrebuildableCreate($db, $install['secretKey'], $install['userId'], $categoryId);

    // The authoring device, so the scope is right — and the local row it names
    // is gone, so the alias answers for nothing that is here.
    aaaoAlias($db, $install['userId'], AAAO_DEVICE, $categoryId, 9_223_372_036_854_775_000);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->expectsOutputToContain('did not restore every row it deleted')
        ->assertFailed();

    expect($db->connection()->table('categories')->where('id', $categoryId)->exists())
        ->toBeTrue('the rebuild committed over a row it never put back');
});

// The other half, and the reason this is a narrowing rather than a removal: an
// alias from the device that authored the create, naming a row that is here, IS
// the row coming back under an id this device minted for it.
it('still calls a row restored when the alias names a live row for the author of its create', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = aaaoInstall($db);

    $categoryId = aaaoCategory($db, $install['userId'], 'groceries');
    $twin = aaaoCategory($db, $install['userId'], 'household');
    aaaoUnrebuildableCreate($db, $install['secretKey'], $install['userId'], $categoryId);

    aaaoAlias($db, $install['userId'], AAAO_DEVICE, $categoryId, $twin);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('categories')->where('id', $twin)->exists())
        ->toBeTrue('the row the alias names was deleted by the rebuild it was meant to account for');
});
