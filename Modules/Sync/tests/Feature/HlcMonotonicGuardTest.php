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

    // Step 1: seed the hlc_clock_state high-water mark ABOVE the current
    // wall-clock ms (WR-01). The seeded value MUST exceed microtime-now-in-ms
    // (~1.75e12) so that a plain tick() — which uses max(wall_ms, last_l) —
    // can only reach >= seeded if boot-time receive() actually restored the
    // persisted floor. The old test seeded 9_999_999_999 (~1.0e10), which is
    // BELOW wall-clock ms, so the assertion passed even when receive() did
    // nothing. Seeding well above wall-clock is the only configuration that
    // proves the guard raised the floor.
    //
    // Composite-key upsert (CR-01): the singleton id=1 row is gone — seed by
    // (user_id, device_id).
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

    // Step 2: Set wall clock to an EARLIER time to simulate a backwards clock jump.
    // Now() in ms ≈ 1.75e12 — far BELOW the seeded high-water mark above.
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');

    // Step 3: Construct a new OpLogWriter (simulating restart) — it reads
    // hlc_clock_state and calls receive($seededHlcL, $seededHlcC) before the
    // first tick.
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-hlcguard',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    // Step 4: Write a new op — tick() must produce hlc_l >= $seededHlcL.
    // Because wall_ms < $seededHlcL, l cannot advance, so receive()+tick()
    // must keep l at the seeded floor and bump the counter.
    $writer->writeSet(
        table: 'transactions',
        pk: $txnId,
        field: 'category_id',
        value: $catId,
    );

    // Step 5: Assert the persisted entry proves the floor was raised:
    //   - hlc_l >= the seeded (above-wall-clock) high-water mark, AND
    //   - hlc_c advanced past the seeded counter (since l did not move forward,
    //     the counter MUST have incremented — this is what distinguishes a
    //     real receive() from a no-op).
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

/*
 * CR-01 regression: two devices for the SAME user must each persist their own
 * hlc_clock_state row. Under the old `id INTEGER PRIMARY KEY CHECK (id = 1)`
 * singleton schema, the second device's writeEntry() upsert collided on id=1
 * and threw a QueryException inside the op-write transaction — silently
 * failing every op-log write for the second device. The composite PRIMARY KEY
 * (user_id, device_id) makes both rows coexist.
 */
it('two devices for one user each persist an independent hlc_clock_state row (CR-01)', function (): void {
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

        // The second device's write would have thrown under the old singleton
        // schema. No exception must escape here.
        $writer->writeSet(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: $catId,
        );
    }

    // Both devices persisted their own clock-state row AND their own op.
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
