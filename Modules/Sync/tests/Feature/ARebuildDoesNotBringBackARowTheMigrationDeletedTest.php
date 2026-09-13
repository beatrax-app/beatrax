<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// A rebuild deletes every row the log carries a create for and replays the log
// over the hole. A row deleted with a plain DELETE left no op behind, so the
// replay put it back and `verifyRestored` never looked: it counts what is
// missing and has no word for what came back.

// So the repair migrations below were undone by the next rebuild, and the
// reader got their contradictory alerts back.
const RBBD_DEVICE = 'device-repair-rebuild';

/**
 * @return array{userId: int, publicKeyHex: string, secretKey: string}
 */
function rbbdInstall(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'repair-rebuild-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    // Confirmed, because the rebuild verifies every entry against the keys of
    // the devices this installation confirmed, and an entry it cannot verify
    // is quarantined rather than replayed.
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => RBBD_DEVICE,
        'name' => 'This device',
        'ed25519_public_key_hex' => bin2hex($publicKey),
        'x25519_public_key_hex' => str_repeat('d', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => '2026-09-01T00:00:00+00:00',
        'confirmed_at' => '2026-09-01T00:00:00+00:00',
        'created_at' => '2026-09-01T00:00:00+00:00',
        'updated_at' => '2026-09-01T00:00:00+00:00',
    ]);

    return [
        'userId' => $userId,
        'publicKeyHex' => bin2hex($publicKey),
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
    ];
}

function rbbdCharge(DatabaseManager $db, int $userId, int $settledMinor): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Repair rebuild',
        'slug' => 'repair-rebuild-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/repair-rebuild.csv',
        'sha256' => hash('sha256', 'repair-rebuild-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-09-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'repair-rebuild-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-09-02',
        'booked_at' => '2026-09-02 10:00:00',
        'value_date' => '2026-09-02',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'unusual merchant',
        'counterparty_name' => 'Unusual Merchant',
        'normalization_version' => 3,
        'description' => 'repair rebuild fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-09-02 00:00:00',
        'updated_at' => '2026-09-02 00:00:00',
    ]);
}

// An alert whose stated amount contradicts the charge it names, announced the
// way the detector announces one: through the writer, one op per column.
/**
 * @param  array{userId: int, publicKeyHex: string, secretKey: string}  $install
 */
function rbbdAnnouncedAlert(DatabaseManager $db, array $install, int $transactionId, int $statedMinor): int
{
    $alertId = DeviceMintedRowId::mint();

    $row = [
        'user_id' => $install['userId'],
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => $statedMinor,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-09-02 10:05:00',
        'created_at' => '2026-09-02 10:05:00',
        'updated_at' => '2026-09-02 10:05:00',
    ];

    $db->connection()->table('anomaly_alerts')->insert(['id' => $alertId] + $row);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => RBBD_DEVICE,
        'userId' => $install['userId'],
        'secretKey' => $install['secretKey'],
        'publicKey' => sodium_hex2bin($install['publicKeyHex']),
    ]);

    $writer->writeCreateRow('anomaly_alerts', $alertId, [
        'transaction_id' => $transactionId,
        'direction' => 'expense',
        'reasons' => ['large'],
        'state' => 'open',
        'latest_amount_minor' => $statedMinor,
        'currency' => 'EUR',
        'detected_at' => '2026-09-02 10:05:00',
    ]);

    return $alertId;
}

function rbbdContradictionMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'Modules/Anomaly/Database/Migrations/2026_09_11_000001_an_alert_that_states_an_amount_the_charge_it_names_does_not_have.php'
    );

    return $migration;
}

it('does not bring back an alert the contradiction repair deleted', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rbbdInstall($db);

    $transactionId = rbbdCharge($db, $install['userId'], -8500);
    $alertId = rbbdAnnouncedAlert($db, $install, $transactionId, -2050);

    rbbdContradictionMigration()->up();

    expect($db->connection()->table('anomaly_alerts')->where('id', $alertId)->exists())
        ->toBeFalse('the migration did not delete the alert, so this proves nothing about the rebuild');

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('anomaly_alerts')->where('user_id', $install['userId'])->count())
        ->toBe(0, 'the rebuild replayed the create and put back the alert the migration deleted');
});

// Why the tombstone cannot simply be written under this device's own id: the
// signature is the admission gate, the key that makes one lives behind the
// app-lock, and a migration has no session to open it with. An entry signed
// with nothing is quarantined by the very rebuild it was meant to answer.
it('quarantines a tombstone written under this device id that nothing signed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rbbdInstall($db);

    $transactionId = rbbdCharge($db, $install['userId'], -8500);
    $alertId = rbbdAnnouncedAlert($db, $install, $transactionId, -2050);

    $db->connection()->table('anomaly_alerts')->where('id', $alertId)->delete();

    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $install['userId'],
        'device_id' => RBBD_DEVICE,
        'table_name' => 'anomaly_alerts',
        'pk' => (string) $alertId,
        'field' => OpLogWriter::TOMBSTONE_FIELD,
        'op_type' => OpType::DeleteTombstone->value,
        'value' => null,
        'gdk_epoch' => null,
        'hlc_l' => (int) (microtime(true) * 1000) + 60_000,
        'hlc_c' => 0,
        'signature' => '',
        'recorded_at' => '2026-09-12 09:00:00',
    ]);

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('op_log_quarantine')
        ->where('user_id', $install['userId'])
        ->where('reason', 'forged_signature')
        ->count())
        ->toBeGreaterThan(0, 'an unsigned tombstone under a confirmed device id passed the signature gate')
        ->and($db->connection()->table('anomaly_alerts')->where('id', $alertId)->exists())
        ->toBeTrue('the unsigned tombstone was applied, so it is not the reason the system author exists');
});

// The other repair, and the same defect: a rebuild has to leave both deletions
// where they are, and a support class shared by two migrations is only shared
// if both of them carry it.
function rbbdInternalMoveMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'Modules/Anomaly/Database/Migrations/2026_08_30_000001_drop_anomaly_alerts_raised_on_internal_moves.php'
    );

    return $migration;
}

it('does not bring back an alert the internal-move repair deleted', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rbbdInstall($db);

    $transactionId = rbbdCharge($db, $install['userId'], -8500);
    $db->connection()->table('transactions')->where('id', $transactionId)->update(['type' => 'transfer_out']);

    $alertId = rbbdAnnouncedAlert($db, $install, $transactionId, -8500);

    rbbdInternalMoveMigration()->up();

    expect($db->connection()->table('anomaly_alerts')->where('id', $alertId)->exists())
        ->toBeFalse('the migration did not delete the alert, so this proves nothing about the rebuild');

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('anomaly_alerts')->where('user_id', $install['userId'])->count())
        ->toBe(0, 'the rebuild replayed the create and put back the alert the migration deleted');
});

// The tombstone the migrations leave is this device's own, and a peer's create
// for the same row must not resurrect it either — which is the case the live
// database is in: seven of the nine orphaned alert ids carry a create from
// both devices.
it('refuses a peer create for a row the repair tombstoned', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $install = rbbdInstall($db);

    $transactionId = rbbdCharge($db, $install['userId'], -8500);
    $alertId = rbbdAnnouncedAlert($db, $install, $transactionId, -2050);

    $peerKeypair = sodium_crypto_sign_keypair();
    $peerSecret = sodium_crypto_sign_secretkey($peerKeypair);
    $peerPublic = sodium_crypto_sign_publickey($peerKeypair);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $install['userId'],
        'device_id' => 'device-peer-repair',
        'name' => 'The phone',
        'ed25519_public_key_hex' => bin2hex($peerPublic),
        'x25519_public_key_hex' => str_repeat('e', 64),
        'safety_number_words' => 'six five four three two one',
        'is_self' => 0,
        'paired_at' => '2026-09-01T00:00:00+00:00',
        'confirmed_at' => '2026-09-01T00:00:00+00:00',
        'created_at' => '2026-09-01T00:00:00+00:00',
        'updated_at' => '2026-09-01T00:00:00+00:00',
    ]);

    /** @var OpLogWriter $peerWriter */
    $peerWriter = app(OpLogWriter::class, [
        'deviceId' => 'device-peer-repair',
        'userId' => $install['userId'],
        'secretKey' => $peerSecret,
        'publicKey' => $peerPublic,
    ]);

    // The peer's own create for the same pk, raised before the repair ran and
    // still on the wire afterwards — which is the shape the live database is
    // in, and the reason the repair's own comment about the peer's copy being
    // correct there does not hold.
    $peerWriter->writeCreateRow('anomaly_alerts', $alertId, [
        'transaction_id' => $transactionId,
        'direction' => 'expense',
        'reasons' => ['large'],
        'state' => 'open',
        'latest_amount_minor' => -2050,
        'currency' => 'EUR',
        'detected_at' => '2026-09-02 10:05:00',
    ]);

    rbbdContradictionMigration()->up();

    $this->artisan('sync:rebuild', ['--user' => (string) $install['userId'], '--force' => true])
        ->assertSuccessful();

    expect($db->connection()->table('anomaly_alerts')->where('user_id', $install['userId'])->count())
        ->toBe(0, "the peer's create outlived the tombstone the repair wrote");
});
