<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Tombstones were applied in whatever order HLC happened to put the tables in,
// and `import_runs` holds ON DELETE NO ACTION children. Delete the parent first
// and SQLite refuses — into an empty catch block, so the parent survived a
// delete both devices had agreed on and neither of them could see it had.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'delete-order-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-a' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function deleteOrderTombstone(DeviceKeySigner $signer, string $sk, int $userId, string $table, int $pk, int $hlcL): OpLogEntry
{
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: '',
        value: null,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::DeleteTombstone,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), $sk));
}

/**
 * @return array{0: int, 1: int} [importRunId, transactionId]
 */
function deleteOrderParentAndChild(DatabaseManager $db, int $userId): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Delete order account',
        'slug' => 'delete-order-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00DELO'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/delete-order-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'delete-order-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'delete-order-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -500,
        'currency' => 'EUR',
        'settled_amount_minor' => -500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'delete order fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$runId, $txnId];
}

it('deletes a parent whose tombstone sorts before its own child tombstone', function (): void {
    [$runId, $txnId] = deleteOrderParentAndChild($this->db, $this->userId);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay([
        deleteOrderTombstone($this->signer, $this->sk, $this->userId, 'import_runs', $runId, 1000),
        deleteOrderTombstone($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 2000),
    ], $this->userId);

    expect($this->db->connection()->table('transactions')->where('id', $txnId)->exists())->toBeFalse()
        ->and($this->db->connection()->table('import_runs')->where('id', $runId)->exists())->toBeFalse();
});

it('deletes a parent whose tombstone sorts after its own child tombstone', function (): void {
    [$runId, $txnId] = deleteOrderParentAndChild($this->db, $this->userId);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay([
        deleteOrderTombstone($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 1000),
        deleteOrderTombstone($this->signer, $this->sk, $this->userId, 'import_runs', $runId, 2000),
    ], $this->userId);

    expect($this->db->connection()->table('transactions')->where('id', $txnId)->exists())->toBeFalse()
        ->and($this->db->connection()->table('import_runs')->where('id', $runId)->exists())->toBeFalse();
});

it('records the tombstone a surviving child refuses instead of swallowing it', function (): void {
    [$runId, $txnId] = deleteOrderParentAndChild($this->db, $this->userId);

    // Only the parent is tombstoned. The child is a row this device holds and
    // no op deletes, so the delete genuinely cannot be applied — and a reader
    // has to be able to see that the two devices now disagree.
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay([
        deleteOrderTombstone($this->signer, $this->sk, $this->userId, 'import_runs', $runId, 1000),
    ], $this->userId);

    $quarantined = $this->db->connection()->table('op_log_quarantine')
        ->where('user_id', $this->userId)
        ->where('table_name', 'import_runs')
        ->where('reason', QuarantineReason::DeleteBlockedByReference->value)
        ->count();

    expect($this->db->connection()->table('transactions')->where('id', $txnId)->exists())->toBeTrue()
        ->and($this->db->connection()->table('import_runs')->where('id', $runId)->exists())->toBeTrue()
        ->and($quarantined)->toBe(1);
});
