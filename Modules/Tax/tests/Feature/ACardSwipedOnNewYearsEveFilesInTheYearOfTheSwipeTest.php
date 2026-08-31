<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Services\TaxCsvExporter;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Public\Services\TaxTagQuery;

uses(RefreshDatabase::class);

// An ICS statement carries two days and means both: `posted_at` is the day the
// card was used, `booked_at` the day the issuer booked it, and on a real
// statement they differ on every row. Across a 31 December / 1 January
// boundary they differ by a TAX YEAR.
//
// Every jurisdiction whose deduction corpus ships here (resources/corpus/tax,
// 33 of them, all private-individual returns) puts a cash-basis deduction in
// the year the taxpayer paid. The swipe is the payment: at that instant the
// debt to the merchant is discharged and replaced by one to the issuer. The
// issuer's booking date is its own clearing artefact and is a tax concept in
// none of the 33. `.docs/features/tax/tax-year-resolution.md` carries the
// citations; `tax_year_override` remains the escape hatch for the genuinely
// odd case, which is exactly what it cannot be if the default is also odd.

function nyeUser(DatabaseManager $db): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => 'nye-boundary',
        'password' => bcrypt('fixture-password-12chars'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nyeSwipe(DatabaseManager $db, int $userId, string $postedAt, string $bookedAt): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'NYE ICS '.$suffix,
        'slug' => 'nye-ics-'.$suffix,
        'kind' => 'ics_card',
        'iban' => 'ICS-NYE-'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/nye-'.$suffix.'.pdf',
        'sha256' => hash('sha256', 'nye-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'nye-tx-'.$suffix),
        'posted_at' => $postedAt,
        'booked_at' => $bookedAt,
        'value_date' => $postedAt,
        'amount_minor' => -12500,
        'currency' => 'EUR',
        'settled_amount_minor' => -12500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'nye-vendor',
        'counterparty_name' => 'NYE Vendor BV',
        'normalization_version' => 1,
        'description' => 'NYE deductible purchase',
        'type' => 'expense',
        'source_format' => 'ics-pdf',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nyeTag(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->userId = nyeUser($db);
    $this->txId = nyeSwipe($db, $this->userId, '2026-12-31', '2027-01-01 09:14:00');
    nyeTag($db, $this->userId, $this->txId);
});

it('files a 31 December swipe under the year of the swipe, not the year the issuer booked it', function (): void {
    $data = app(TaxYearQuery::class)->forUser($this->userId, 2026);

    expect($data->itemCount)->toBe(1);
    expect($data->deductionsTotalMinor)->toBe(12500);
});

it('does not file that swipe under the following year', function (): void {
    $data = app(TaxYearQuery::class)->forUser($this->userId, 2027);

    expect($data->itemCount)->toBe(0);
});

it('offers the swipe year in the year switcher and not the booking year', function (): void {
    $years = app(TaxYearQuery::class)->availableYears($this->userId);

    expect($years)->toBe([2026]);
});

it('counts the swipe on the dashboard card under the same year the cockpit does', function (): void {
    $summary = app(TaxTagQuery::class)->summaryForUser($this->userId, 2026);

    expect($summary->count)->toBe(1);
});

it('tells the tag picker the swipe year, so the year-assignment row offers the right one', function (): void {
    expect(app(TaxTagQuery::class)->postedYearFor($this->userId, $this->txId))->toBe(2026);
});

it('exports the swipe under the swipe date, so the CSV agrees with the cockpit it came from', function (): void {
    $user = User::query()->findOrFail($this->userId);
    $csv = app(TaxCsvExporter::class)->export($user, 2026);

    expect($csv)->toContain('2026-12-31');
    expect($csv)->not->toContain('2027-01-01');
});

it('still honours an explicit override against the swipe year', function (): void {
    $this->db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $this->txId)
        ->update(['tax_year_override' => 2027]);

    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->itemCount)->toBe(0);
    expect(app(TaxYearQuery::class)->forUser($this->userId, 2027)->itemCount)->toBe(1);
});
