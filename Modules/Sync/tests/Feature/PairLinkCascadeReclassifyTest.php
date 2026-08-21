<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// A tombstone on one side of a paired transfer orphans the partner through the
// ON DELETE SET NULL cascade on pair_transaction_id, so the replayer has to
// reclassify the survivor and append a compensating Set op — the reclassification
// is a change of its own and peers only learn of it through the op log.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{0: int, 1: int}
 */
function pairLinkTxns(DatabaseManager $db, int $userId, string $suffix): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN pair link',
        'slug' => 'pair-link-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pair-link-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'pair-link-run-'.$suffix),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    // Insert unpaired first: each side's pair_transaction_id points at a row
    // that does not exist yet.
    $txnAId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'pair-a-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'own account',
        'counterparty_name' => 'OWN ACCOUNT',
        'normalization_version' => 3,
        'description' => 'pair link fixture A',
        'type' => 'transfer_out',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $txnBId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'pair-b-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:05:00',
        'value_date' => '2026-06-15',
        'amount_minor' => 5000,
        'currency' => 'EUR',
        'settled_amount_minor' => 5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'own account',
        'counterparty_name' => 'OWN ACCOUNT',
        'normalization_version' => 3,
        'description' => 'pair link fixture B',
        'type' => 'transfer_in',
        'source_format' => 'asn-csv',
        'source_row_index' => 2,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $db->connection()->table('transactions')
        ->where('id', $txnAId)
        ->update(['pair_transaction_id' => $txnBId]);
    $db->connection()->table('transactions')
        ->where('id', $txnBId)
        ->update(['pair_transaction_id' => $txnAId]);

    return [$txnAId, $txnBId];
}

it('tombstone on transfer_out (A) reclassifies partner transfer_in (B) to income with compensating op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'pair-cascade-u1',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    [$txnAId, $txnBId] = pairLinkTxns($db, $userId, 'PCA');

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);
    $pkHex = bin2hex($pk);
    $signer = new DeviceKeySigner;

    $stub = new OpLogEntry(
        table: 'transactions',
        pk: $txnAId,
        field: '__tombstone__',
        value: null,
        hlcL: 2000,
        hlcC: 0,
        deviceId: 'device-pair',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: $userId,
    );
    $sig = $signer->sign($stub->signingPayload(), $sk);
    $tombEntry = new OpLogEntry(
        table: 'transactions',
        pk: $txnAId,
        field: '__tombstone__',
        value: null,
        hlcL: 2000,
        hlcC: 0,
        deviceId: 'device-pair',
        opType: OpType::DeleteTombstone,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, ['device-pair' => $pkHex]);
    $replayer->replay([$tombEntry], $userId);

    expect(
        $db->connection()->table('transactions')->where('id', $txnAId)->exists()
    )->toBeFalse();

    $rowB = $db->connection()->table('transactions')->where('id', $txnBId)->first();
    expect($rowB)->not->toBeNull();
    expect($rowB->pair_transaction_id)->toBeNull();

    expect($rowB->type)->toBe('income');

    $compensatingOp = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('pk', (string) $txnBId)
        ->where('field', 'type')
        ->where('op_type', 'set')
        ->first();

    expect($compensatingOp)->not->toBeNull();
    expect($compensatingOp->value)->toBe(json_encode('income'));
});

it('tombstone on transfer_in (B) reclassifies partner transfer_out (A) to expense with compensating op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'pair-cascade-u2',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    [$txnAId, $txnBId] = pairLinkTxns($db, $userId, 'PCB');

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);
    $pkHex = bin2hex($pk);
    $signer = new DeviceKeySigner;

    $stub = new OpLogEntry(
        table: 'transactions',
        pk: $txnBId,
        field: '__tombstone__',
        value: null,
        hlcL: 2000,
        hlcC: 0,
        deviceId: 'device-pair',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: $userId,
    );
    $sig = $signer->sign($stub->signingPayload(), $sk);
    $tombEntry = new OpLogEntry(
        table: 'transactions',
        pk: $txnBId,
        field: '__tombstone__',
        value: null,
        hlcL: 2000,
        hlcC: 0,
        deviceId: 'device-pair',
        opType: OpType::DeleteTombstone,
        signature: $sig,
        userId: $userId,
    );

    $replayer = new OpLogReplayer($db, ['device-pair' => $pkHex]);
    $replayer->replay([$tombEntry], $userId);

    expect(
        $db->connection()->table('transactions')->where('id', $txnBId)->exists()
    )->toBeFalse();

    $rowA = $db->connection()->table('transactions')->where('id', $txnAId)->first();
    expect($rowA)->not->toBeNull();
    expect($rowA->pair_transaction_id)->toBeNull();

    expect($rowA->type)->toBe('expense');

    $compensatingOp = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transactions')
        ->where('pk', (string) $txnAId)
        ->where('field', 'type')
        ->where('op_type', 'set')
        ->first();

    expect($compensatingOp)->not->toBeNull();
    expect($compensatingOp->value)->toBe(json_encode('expense'));
});
