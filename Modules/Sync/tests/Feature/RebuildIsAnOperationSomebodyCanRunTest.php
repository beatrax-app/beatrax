<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The database is reproducible by replaying the merged log — `OpLogRebuilder`
// does it, in one transaction, restoring its triggers on any throw, and it was
// well tested. Nothing in the product called it. A rebuild was a property the
// code happened to have rather than an operation anyone could reach, which is
// the difference between "recoverable" and "recovered".
const RBO_DEVICE = 'device-rebuild';

/**
 * @return array{userId: int, deviceId: int, sk: string}
 */
function rboInstall(DatabaseManager $db): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'rebuild-operator',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    // Confirmed, because the replayer verifies every entry against the keys of
    // devices this installation has confirmed; an unconfirmed one is exactly
    // the entry a rebuild must refuse.
    $deviceId = (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => RBO_DEVICE,
        'name' => 'This device',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => '2026-06-14T00:00:00+00:00',
        'confirmed_at' => '2026-06-14T00:00:00+00:00',
        'created_at' => '2026-06-14T00:00:00+00:00',
        'updated_at' => '2026-06-14T00:00:00+00:00',
    ]);

    return ['userId' => $userId, 'deviceId' => $deviceId, 'sk' => sodium_crypto_sign_secretkey($keypair)];
}

function rboRecordOp(
    DatabaseManager $db,
    string $sk,
    int $userId,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
    OpType $opType,
): void {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: RBO_DEVICE,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    $signature = (new DeviceKeySigner)->sign($make('')->signingPayload(), $sk);

    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => RBO_DEVICE,
        'table_name' => 'categories',
        'pk' => (string) $pk,
        'field' => $field,
        'op_type' => $opType->value,
        'value' => $value,
        'hlc_l' => $hlcL,
        'hlc_c' => 0,
        'signature' => $signature,
        'recorded_at' => '2026-06-15 10:00:00',
    ]);
}

it('puts the database back the way the log says it should be', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rboInstall($db);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $install['userId'],
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);

    // A create is one op per field, not one op carrying a payload, and it has
    // to carry every column `_create_required` names or the replayer refuses
    // the row as incomplete — which is the same as the rebuild dropping it.
    $hlc = 1000;
    foreach (['name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense'] as $field => $value) {
        rboRecordOp($db, $install['sk'], $install['userId'], $categoryId, $field,
            json_encode($value), $hlc++, OpType::CreateRow);
    }

    // A row edited by something that recorded no operation — a raw UPDATE, a
    // migration, a restored file. The log is the authority and the row has
    // drifted from it, which is the state a rebuild exists to leave behind.
    $db->connection()->table('categories')->where('id', $categoryId)->update(['name' => 'Drifted by hand']);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('categories')->where('id', $categoryId)->value('name'))
        ->toBe('Groceries', 'the rebuild did not put the row back the way the log records it');
});

// The confirmation is the whole difference between an operation and an
// accident: it deletes every replicated row before it replays.
it('rebuilds nothing when the operator declines', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rboInstall($db);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $install['userId'],
        'name' => 'Left alone',
        'slug' => 'left-alone',
        'kind' => 'expense',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId']])
        ->expectsConfirmation(
            'Rebuild deletes every replicated row for this account and replays the log over it. Continue?',
            'no',
        )
        ->expectsOutput('Nothing was rebuilt.')
        ->assertSuccessful();

    // No log entries exist, so a rebuild that ran would have emptied the table.
    expect($db->connection()->table('categories')->where('id', $categoryId)->value('name'))
        ->toBe('Left alone', 'the rebuild ran anyway, so declining does nothing');
});

it('refuses when there is no account to rebuild', function (): void {
    $this->artisan('sync:rebuild', ['--force' => true])
        ->expectsOutputToContain('No account to rebuild')
        ->assertFailed();
});

// `--user` is typed by a human at a terminal. A non-numeric one is a mistake,
// and rebuilding the owner's account because the argument did not parse is the
// worst available reading of it.
it('refuses a --user that is not an account id rather than falling back to the owner', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    rboInstall($db);

    $this->artisan('sync:rebuild', ['--user' => 'not-a-number', '--force' => true])
        ->expectsOutputToContain('No account to rebuild')
        ->assertFailed();
});

// The other half: no --user at all resolves the owner, which is the single
// account a desktop install has.
it('rebuilds the owner when no account is named', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rboInstall($db);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $install['userId'],
        'name' => 'Owner default',
        'slug' => 'owner-default',
        'kind' => 'expense',
        'created_at' => '2026-08-01 12:00:00',
        'updated_at' => '2026-08-01 12:00:00',
    ]);

    $hlc = 1000;
    foreach (['name' => 'Owner default', 'slug' => 'owner-default', 'kind' => 'expense'] as $field => $value) {
        rboRecordOp($db, $install['sk'], $install['userId'], $categoryId, $field,
            json_encode($value), $hlc++, OpType::CreateRow);
    }

    $this->artisan('sync:rebuild', ['--force' => true])->assertSuccessful();

    expect($db->connection()->table('categories')->where('id', $categoryId)->value('name'))
        ->toBe('Owner default');
});
