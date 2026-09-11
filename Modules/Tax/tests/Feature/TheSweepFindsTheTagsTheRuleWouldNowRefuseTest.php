<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Internal\Services\TaxYearQuery;

uses(RefreshDatabase::class);

// The gate stops new ones; these are the rows tagged before it existed. A
// sweep that reports clean on a clean tree says nothing, so the case that
// matters is the one carrying tags the rule now refuses.

// Its own fixtures rather than the sibling file's: globals are shared across a
// Pest run, so borrowing them makes this file pass only when the other one
// happens to be in the same shard.
function taxSweepUser(DatabaseManager $db): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => 'sweep-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture-password-12chars'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function taxSweepRow(DatabaseManager $db, int $userId, string $type, int $minor, string $paymentType = 'unknown'): int
{
    $hex = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'swp-'.$hex,
        'kind' => 'bank', 'iban' => 'NL00SWP'.strtoupper($hex), 'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/swp-'.$hex.'.csv',
        'sha256' => hash('sha256', 'swp-'.$hex), 'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'swp-tx-'.$hex),
        'posted_at' => '2026-04-10', 'booked_at' => '2026-04-10 12:00:00', 'value_date' => '2026-04-10',
        'amount_minor' => $minor, 'currency' => 'EUR',
        'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'sweep vendor', 'counterparty_name' => 'Sweep Vendor BV',
        'normalization_version' => 1, 'description' => 'Row under test',
        'type' => $type, 'payment_type' => $paymentType, 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->userId = taxSweepUser($db);
});

function taxSweepTagDirectly(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reports what it would remove and changes nothing until it is told to', function (): void {
    $transfer = taxSweepRow($this->db, $this->userId, 'transfer_out', -50000, 'transfer');
    taxSweepTagDirectly($this->db, $this->userId, $transfer);

    $this->artisan('tax:sweep-untaggable')
        ->expectsOutputToContain('1 tag(s) would be removed.')
        ->assertSuccessful();

    expect($this->db->connection()->table('tax_transaction_tags')->count())->toBe(1);
});

it('removes only the tags the rule refuses, and moves no total doing it', function (): void {
    $deductible = taxSweepRow($this->db, $this->userId, 'expense', -12500);
    $transfer = taxSweepRow($this->db, $this->userId, 'transfer_out', -50000, 'transfer');
    $correction = taxSweepRow($this->db, $this->userId, 'adjustment', 500);

    foreach ([$deductible, $transfer, $correction] as $txId) {
        taxSweepTagDirectly($this->db, $this->userId, $txId);
    }

    // The figure does not move across the sweep, because the readers narrow to
    // the same rule: this used to report EUR 630.00 until the command was run,
    // on a device with no terminal to run it from.
    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(12500);

    $this->artisan('tax:sweep-untaggable', ['--apply' => true])->assertSuccessful();

    expect($this->db->connection()->table('tax_transaction_tags')->pluck('transaction_id')->all())->toBe([$deductible])
        ->and(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(12500);
});
