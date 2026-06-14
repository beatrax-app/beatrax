<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

/*
 * SYNC-01: HLC monotonic guard — boot-time receive() from hlc_clock_state.
 *
 * Proves that after persisting an op with a high hlc_l value, re-instantiating
 * OpLogWriter (simulating an app restart) and ticking produces an hlc_l that
 * is >= the persisted value — even when the wall clock is set to an EARLIER
 * time. This is the Kulkarni-Demirbas monotonic guard: boot-time receive()
 * restores the high-water mark, so the clock never rewinds.
 *
 * RED: OpLogWriter does not exist yet (Wave 1 creates it). Fails with
 * "Class not found" until Plan 11-02 implements OpLogWriter.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('boot-time receive() from hlc_clock_state prevents clock rewind after restart', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'hlc-guard-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    // Seed a category and transaction so we have something to write an op for.
    $catId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'HlcGuardCat',
        'slug' => 'hlc-guard-cat',
        'kind' => 'expense',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN hlc guard',
        'slug' => 'hlc-guard-asn',
        'kind' => 'asn',
        'iban' => 'NL00ASNBHLCGUARD1',
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/hlc-guard.csv',
        'sha256' => hash('sha256', 'hlc-guard-run'),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'hlc-guard-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test merchant',
        'counterparty_name' => 'TEST MERCHANT',
        'normalization_version' => 3,
        'description' => 'hlc guard fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    // Step 1: Set wall clock to a "high" time so the first op gets a large hlc_l.
    // We seed the hlc_clock_state row directly with a high hlc_l to simulate
    // a previous session that ran with hlc_l = 9_999_999_999.
    $highHlcL = 9_999_999_999;
    $db->connection()->table('hlc_clock_state')->updateOrInsert(
        ['id' => 1],
        [
            'user_id' => $userId,
            'device_id' => 'device-hlcguard',
            'last_l' => $highHlcL,
            'last_c' => 0,
            'updated_at' => '2026-06-15 10:00:00',
        ]
    );

    // Step 2: Set wall clock to an EARLIER time to simulate a backwards clock jump.
    // Now() in ms ≈ 1_749_000_000_000 (well below 9_999_999_999).
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');

    // Step 3: Construct a new OpLogWriter (simulating restart) — it reads
    // hlc_clock_state and calls receive($highHlcL, 0) before the first tick.
    // RED: OpLogWriter does not exist yet.
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-hlcguard',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    // Step 4: Write a new op — this triggers tick() which must produce hlc_l >= $highHlcL.
    $writer->writeSet(
        table: 'transactions',
        pk: $txnId,
        field: 'category_id',
        value: $catId,
    );

    // Step 5: Assert the persisted entry's hlc_l is >= the high-water mark.
    $row = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->hlc_l)->toBeGreaterThanOrEqual($highHlcL);
});
