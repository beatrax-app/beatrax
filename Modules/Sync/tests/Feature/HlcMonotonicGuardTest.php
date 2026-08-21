<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

// A restart re-reads the persisted high-water mark before the first tick, so
// the logical clock cannot rewind behind a wall clock that has jumped
// backwards. Without that floor the total order breaks and later ops sort
// before earlier ones.

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
        'kind' => 'bank',
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

    // The seeded mark must sit ABOVE wall-clock milliseconds. tick() takes the
    // max of the two, so a mark below wall clock makes the assertion pass even
    // when the boot-time restore does nothing at all — which is exactly how an
    // earlier version of this test passed against a broken guard.
    $seededHlcL = (int) (microtime(true) * 1000) + 1_000_000_000;
    $seededHlcC = 7;
    $db->connection()->table('hlc_clock_state')->updateOrInsert(
        ['user_id' => $userId, 'device_id' => 'device-hlcguard'],
        [
            'last_l' => $seededHlcL,
            'last_c' => $seededHlcC,
            'updated_at' => '2026-06-15 10:00:00',
        ]
    );

    // A backwards clock jump, landing far below the seeded mark.
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');

    // A fresh writer stands in for a restart: it restores the persisted mark
    // before its first tick.
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-hlcguard',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    $writer->writeSet(
        table: 'transactions',
        pk: $txnId,
        field: 'category_id',
        value: $catId,
    );

    // The counter is what distinguishes a real restore from a no-op: wall time
    // cannot advance the logical part, so the counter has to move instead.
    $row = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->hlc_l)->toBeGreaterThanOrEqual($seededHlcL);
    expect((int) $row->hlc_l)->toBe($seededHlcL); // l pinned to the floor (wall clock is behind)
    expect((int) $row->hlc_c)->toBeGreaterThan($seededHlcC); // counter advanced past the seed
});

// Two devices of one user each keep their own clock row. The schema used to
// pin it to a single row, so the second device's upsert collided inside the
// op-write transaction and every one of its op-log writes failed silently.
it('two devices for one user each persist an independent hlc_clock_state row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = $db->connection()->table('users')->insertGetId([
        'username' => 'hlc-multidevice-u',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    CarbonImmutable::setTestNow('2026-06-15 10:00:00');

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN multidevice',
        'slug' => 'hlc-md-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNBHLCMD0001',
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/hlc-md.csv',
        'sha256' => hash('sha256', 'hlc-md-run'),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $catId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'HlcMdCat',
        'slug' => 'hlc-md-cat',
        'kind' => 'expense',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'hlc-md-'.bin2hex(random_bytes(8))),
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
        'description' => 'hlc md fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    foreach (['device-aaa', 'device-bbb'] as $deviceId) {
        $keypair = sodium_crypto_sign_keypair();
        $writer = app(OpLogWriter::class, [
            'deviceId' => $deviceId,
            'userId' => $userId,
            'secretKey' => sodium_crypto_sign_secretkey($keypair),
            'publicKey' => sodium_crypto_sign_publickey($keypair),
        ]);

        // This is the write that used to throw. Nothing may escape here.
        $writer->writeSet(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: $catId,
        );
    }

    $clockRows = $db->connection()->table('hlc_clock_state')
        ->where('user_id', $userId)
        ->pluck('device_id')
        ->all();
    expect($clockRows)->toContain('device-aaa');
    expect($clockRows)->toContain('device-bbb');

    $deviceIdsWithOps = $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->distinct()
        ->pluck('device_id')
        ->all();
    expect($deviceIdsWithOps)->toContain('device-aaa');
    expect($deviceIdsWithOps)->toContain('device-bbb');
});
