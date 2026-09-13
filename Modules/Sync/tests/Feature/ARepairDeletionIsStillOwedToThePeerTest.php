<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureDrain;
use Modules\Sync\Internal\OpLog\DeferredOpKind;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The tombstone a migration writes is authored by `system-cascade`, and a peer
// carries ops only for an author it holds a key for — so that entry answers
// this device's own rebuild and reaches nobody else. The peer is owed the
// delete as a coordinate, which the first unlocked request signs and sends.
const ROTP_DEVICE = 'repair-owed-device';

/**
 * @return array{userId: int, secretKey: string, publicKey: string}
 */
function rotpDevice(DatabaseManager $db): array
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'repair-owed-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    return [
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ];
}

function rotpContradictingAlert(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Repair owed',
        'slug' => 'repair-owed-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/repair-owed.csv',
        'sha256' => hash('sha256', 'repair-owed-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-09-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $transactionId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'repair-owed-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-09-02',
        'booked_at' => '2026-09-02 10:00:00',
        'value_date' => '2026-09-02',
        'amount_minor' => -8500,
        'currency' => 'EUR',
        'settled_amount_minor' => -8500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'unusual merchant',
        'counterparty_name' => 'Unusual Merchant',
        'normalization_version' => 3,
        'description' => 'repair owed fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-09-02 00:00:00',
        'updated_at' => '2026-09-02 00:00:00',
    ]);

    $alertId = DeviceMintedRowId::mint();

    // -2050 against a charge of -8500: the contradiction the repair deletes on.
    $db->connection()->table('anomaly_alerts')->insert([
        'id' => $alertId,
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['duplicate']),
        'baseline_amount_minor' => null,
        'latest_amount_minor' => -2050,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-09-02 10:05:00',
        'created_at' => '2026-09-02 10:05:00',
        'updated_at' => '2026-09-02 10:05:00',
    ]);

    return $alertId;
}

function rotpRepairMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path(
        'Modules/Anomaly/Database/Migrations/2026_09_11_000001_an_alert_that_states_an_amount_the_charge_it_names_does_not_have.php'
    );

    return $migration;
}

it('owes the peer the delete the repair made here', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = rotpDevice($db);
    $alertId = rotpContradictingAlert($db, $device['userId']);

    rotpRepairMigration()->up();

    expect($db->connection()->table('deferred_op_captures')
        ->where('user_id', $device['userId'])
        ->where('table_name', 'anomaly_alerts')
        ->where('pk', (string) $alertId)
        ->where('field', OpLogWriter::TOMBSTONE_FIELD)
        ->where('op_kind', DeferredOpKind::Delete->value)
        ->count())
        ->toBe(1, 'the repair deleted the row and told the peer nothing');
});

it('signs that owed delete on the first request that holds a key', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = rotpDevice($db);
    $alertId = rotpContradictingAlert($db, $device['userId']);

    rotpRepairMigration()->up();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => ROTP_DEVICE,
        'userId' => $device['userId'],
        'secretKey' => $device['secretKey'],
        'publicKey' => $device['publicKey'],
    ]);
    app()->instance(OpLogWriter::class, $writer);

    expect(app(DeferredOpCaptureDrain::class)->drain($device['userId']))
        ->toBe(1, 'nothing was owed, so nothing was drained');

    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $device['userId'])
        ->where('table_name', 'anomaly_alerts')
        ->where('pk', (string) $alertId)
        ->where('device_id', ROTP_DEVICE)
        ->where('op_type', OpType::DeleteTombstone->value)
        ->first();

    expect($row)->not->toBeNull('the drained delete never became an op under this device id');

    $entry = new OpLogEntry(
        table: 'anomaly_alerts',
        pk: $alertId,
        field: (string) $row->field,
        value: null,
        hlcL: (int) $row->hlc_l,
        hlcC: (int) $row->hlc_c,
        deviceId: ROTP_DEVICE,
        opType: OpType::DeleteTombstone,
        signature: (string) $row->signature,
        userId: $device['userId'],
    );

    // A signature the peer can verify is the whole difference between a delete
    // that travels and one quarantined on arrival as a forgery.
    expect(new DeviceKeySigner()->verifyAny($entry->signatureCandidates(), $entry->signature, $device['publicKey']))
        ->toBeTrue('the drained tombstone would be refused by the peer that received it');
});

it('leaves the local answer under an author no peer carries', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = rotpDevice($db);
    $alertId = rotpContradictingAlert($db, $device['userId']);

    rotpRepairMigration()->up();

    $system = $db->connection()->table('op_log_entries')
        ->where('user_id', $device['userId'])
        ->where('table_name', 'anomaly_alerts')
        ->where('pk', (string) $alertId)
        ->where('device_id', OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID)
        ->get();

    expect($system)->toHaveCount(1, 'the repair left no local tombstone, so a rebuild has nothing to read')
        ->and((string) $system[0]->op_type)->toBe(OpType::DeleteTombstone->value)
        ->and((string) $system[0]->field)->toBe(OpLogWriter::TOMBSTONE_FIELD)
        ->and($system[0]->value)->toBeNull()
        ->and($system[0]->gdk_epoch)->toBeNull();

    // Re-running an append-only migration must leave one tombstone per row and
    // not one per run: the entry is keyed without its stamp for that reason.
    rotpRepairMigration()->up();

    expect($db->connection()->table('op_log_entries')
        ->where('user_id', $device['userId'])
        ->where('table_name', 'anomaly_alerts')
        ->where('pk', (string) $alertId)
        ->where('op_type', OpType::DeleteTombstone->value)
        ->count())
        ->toBe(1, 'a second run of the repair wrote a second tombstone for the same row');
});
