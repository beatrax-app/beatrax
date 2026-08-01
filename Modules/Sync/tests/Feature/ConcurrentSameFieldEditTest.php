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

/*
 * D-05: Concurrent same-field edit conflict scenario.
 *
 * Two devices (device-a, device-b) each apply a SET op on the SAME
 * transactions.category_id field with an identical HLC timestamp [1000, 0].
 * The tie-break is device_id: strcmp('device-a', 'device-b') < 0, so
 * 'device-b' wins (highest HLC order via device-id).
 *
 * Expected: category_id === C_b after replay; exactly one row.
 * RED: The OpLogReplayer skeleton (Wave 1) does not yet apply LWW logic,
 * so the assertion fails until Wave 2 implements the merge.
 */

function concurrentUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seeds account → import_run → category → transaction and returns
 * [transactionId, categoryA_id, categoryB_id].
 *
 * @return array{0: int, 1: int, 2: int}
 */
function concurrentTxn(DatabaseManager $db, int $userId, string $suffix): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN concurrent test',
        'slug' => 'sync-conc-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sync-conc-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sync-conc-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Cat A '.$suffix,
        'slug' => 'cat-a-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catB = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Cat B '.$suffix,
        'slug' => 'cat-b-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'sync-conc-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'sync concurrent fixture',
        'type' => 'expense',  // REQUIRED — transactions_type_check_insert trigger
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $catA, $catB];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    $this->user = concurrentUser('sync-conc-a');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = 'a';
    [$this->txnId, $this->catA, $this->catB] = concurrentTxn($db, (int) $this->user->id, $suffix);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('resolves concurrent same-field category_id edit via HLC + device-id tie-break (device-b wins)', function (): void {
    // Both devices edit category_id at identical HLC [l=1000, c=0].
    // device-b > device-a lexicographically → device-b wins.
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    $signer = new DeviceKeySigner;

    $entryA = new OpLogEntry(
        table: 'transactions',
        pk: $this->txnId,
        field: 'category_id',
        value: (string) $this->catA,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: '',  // signed below
        userId: (int) $this->user->id,
    );

    $entryB = new OpLogEntry(
        table: 'transactions',
        pk: $this->txnId,
        field: 'category_id',
        value: (string) $this->catB,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::Set,
        signature: '',  // signed below
        userId: (int) $this->user->id,
    );

    // Re-create with signatures (OpLogEntry is readonly, reconstruct with sig).
    $sigA = $signer->sign($entryA->signingPayload(), $sk);
    $sigB = $signer->sign($entryB->signingPayload(), $sk);

    $entryA = new OpLogEntry(
        table: 'transactions',
        pk: $this->txnId,
        field: 'category_id',
        value: (string) $this->catA,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: $sigA,
        userId: (int) $this->user->id,
    );

    $entryB = new OpLogEntry(
        table: 'transactions',
        pk: $this->txnId,
        field: 'category_id',
        value: (string) $this->catB,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::Set,
        signature: $sigB,
        userId: (int) $this->user->id,
    );

    $replayer = new OpLogReplayer(
        app(DatabaseManager::class),
        ['device-a' => bin2hex($pk), 'device-b' => bin2hex($pk)],
    );

    $replayer->replay([$entryA, $entryB], (int) $this->user->id);

    // Assert: device-b wins the tie; exactly one row.
    $row = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->where('user_id', $this->user->id)
        ->first();

    expect($row)->not->toBeNull();

    // RED: category_id === C_b — fails until Wave 2 implements LWW.
    expect((int) $row->category_id)->toBe($this->catB);

    // Exactly one transaction row for this user.
    $count = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('user_id', $this->user->id)
        ->count();
    expect($count)->toBe(1);

    // Assert: production path persists both ops to op_log_entries (SYNC-01).
    // RED: fails until Plan 11-02 wires op_log_entries writes in the production replayer.
    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});
