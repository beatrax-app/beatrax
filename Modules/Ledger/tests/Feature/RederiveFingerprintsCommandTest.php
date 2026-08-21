<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::create([
        'username' => 'rederive',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN Betaalrekening',
        'slug' => 'asn-betaalrekening',
        'kind' => 'bank',
        'iban' => 'NL43ASNB0000000000',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = $this->makeImportRun($this->user);
});

it('reports no changes on a fresh DB (no v2 rows)', function (): void {
    $this->artisan('beatrax:rederive-fingerprints', ['--confirm' => true])
        ->expectsOutputToContain('0 rows would be re-derived')
        ->assertSuccessful();
})->group('phase-2');

it('reports a dry-run preview without writing when --dry-run is set', function (): void {
    $this->seedTwoNonCollidingV2Rows();

    $this->artisan('beatrax:rederive-fingerprints', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run OK. 2 rows would be re-derived')
        ->assertSuccessful();

    $remainingV2 = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 2)
        ->count();
    expect($remainingV2)->toBe(2);

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(0);
})->group('phase-2');

it('rewrites v2 rows to v3 in a single transaction when no collisions exist', function (): void {
    $this->seedTwoNonCollidingV2Rows();

    $this->artisan('beatrax:rederive-fingerprints', ['--confirm' => true])
        ->expectsOutputToContain('Re-derived 2 rows to v3')
        ->assertSuccessful();

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(2);

    $v2Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 2)
        ->count();
    expect($v2Count)->toBe(0);

    $rows = $this->db->connection()->table('transactions')->orderBy('id')->get();
    foreach ($rows as $row) {
        expect($row->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
    }
    expect($rows[0]->fingerprint)->not->toBe($rows[1]->fingerprint);
})->group('phase-2');

it('aborts with a clear error when a v3 tuple collision exists in v2 data', function (): void {
    $this->seedCollidingV2Rows();

    $this->artisan('beatrax:rederive-fingerprints', ['--confirm' => true])
        ->expectsOutputToContain('Fingerprint v3 migration ABORTED')
        ->expectsOutputToContain('1 collision(s) detected')
        ->assertFailed();

    $v2Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 2)
        ->count();
    expect($v2Count)->toBe(2);

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(0);
})->group('phase-2');

it('skips rows already on v3 (idempotent re-run)', function (): void {
    $this->seedOneV3Row();

    $this->artisan('beatrax:rederive-fingerprints', ['--confirm' => true])
        ->expectsOutputToContain('0 rows would be re-derived')
        ->assertSuccessful();

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(1);
})->group('phase-2');

it('falls back to dry-run output when neither --confirm nor --dry-run is supplied', function (): void {
    $this->seedTwoNonCollidingV2Rows();

    $this->artisan('beatrax:rederive-fingerprints')
        ->expectsOutputToContain('Dry-run OK. 2 rows would be re-derived')
        ->assertSuccessful();

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(0);
})->group('phase-2');

it('re-derives v3 fingerprints over a realistic-scale row set without collisions', function (): void {
    // 229 rows: the size of the three-month ASN CAMT fixture corpus, so this
    // runs at the scale a real re-derive would.
    for ($i = 0; $i < 229; $i++) {
        $day = sprintf('%02d', ($i % 28) + 1);
        $second = sprintf('%02d', $i % 60);
        $this->seedV2Row([
            'posted_at' => "2026-04-{$day}",
            'booked_at' => "2026-04-{$day} 09:14:{$second}",
            'value_date' => "2026-04-{$day}",
            'amount_minor' => -100 - $i,
            'counterparty_normalized' => "merchant {$i}",
            'source_row_index' => $i,
            'source_ref' => "REF-{$i}",
            'fingerprint' => str_pad((string) ($i + 1), 64, '0', STR_PAD_LEFT),
        ]);
    }

    $this->artisan('beatrax:rederive-fingerprints', ['--confirm' => true])
        ->expectsOutputToContain('Re-derived 229 rows to v3')
        ->assertSuccessful();

    $v3Count = $this->db->connection()
        ->table('transactions')
        ->where('normalization_version', 3)
        ->count();
    expect($v3Count)->toBe(229);

    $distinctFingerprints = $this->db->connection()
        ->table('transactions')
        ->distinct()
        ->pluck('fingerprint')
        ->count();
    expect($distinctFingerprints)->toBe(229);
})->group('phase-2');
