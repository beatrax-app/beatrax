<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Services\TaxCsvExporter;

uses(RefreshDatabase::class);

function tceUser(DatabaseManager $db, string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tceTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TCE ASN '.$suffix,
        'slug' => 'tce-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tce-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tce-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tce-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-03-01',
        'booked_at' => '2025-03-01 00:00:00',
        'value_date' => '2025-03-01',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'tce-vendor',
        'counterparty_name' => 'TCE Vendor BV',
        'normalization_version' => 1,
        'description' => 'TCE test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

function tceCategory(DatabaseManager $db, int $userId, string $name = 'Zorgkosten'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tceTag(DatabaseManager $db, int $userId, int $txId, ?int $catId = null, array $overrides = []): void
{
    $db->connection()->table('tax_transaction_tags')->insert(array_merge([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => $catId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('first row of CSV is the exact 17-column header', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-header-user');

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = explode("\n", trim($csv));
    $header = str_getcsv($lines[0]);

    expect($header)->toBe([
        'tax_year', 'booked_date', 'account', 'counterparty',
        'counterparty_iban', 'description', 'deduction_category',
        'note', 'settled_amount', 'settled_currency', 'original_amount',
        'original_currency', 'transaction_type', 'transaction_id',
        'source_format', 'import_run_id', 'fingerprint',
    ]);
});

it('empty year yields a header-only CSV with no data rows', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-empty-year-user');

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_filter(explode("\n", trim($csv)));
    // Only the header row — no data rows.
    expect(count($lines))->toBe(1);
});

it('one data row per tagged transaction; settled_amount is a 2-decimal string', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-money-format-user');

    $catId = tceCategory($db, $user->id, 'Zorgkosten');

    $tx1 = tceTransaction($db, $user->id, ['settled_amount_minor' => -124000, 'booked_at' => '2025-03-01 00:00:00']);
    $tx2 = tceTransaction($db, $user->id, ['settled_amount_minor' => -50, 'booked_at' => '2025-04-01 00:00:00']);

    tceTag($db, $user->id, $tx1, $catId);
    tceTag($db, $user->id, $tx2, $catId);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    // Header + 2 data rows
    expect(count($lines))->toBe(3);

    $row1 = str_getcsv($lines[1]);
    $row2 = str_getcsv($lines[2]);

    // settled_amount is column index 8
    expect($row1[8])->toMatch('/^\d+\.\d{2}$/');
    expect($row2[8])->toMatch('/^\d+\.\d{2}$/');

    expect($row1[8])->toBe('1240.00');
    expect($row2[8])->toBe('0.50');
});

it('includes audit-extra columns: original_amount, original_currency, transaction_id, source_format, import_run_id, fingerprint', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-audit-cols-user');

    $catId = tceCategory($db, $user->id, 'Studiekosten');

    $fingerprintVal = hash('sha256', 'tce-audit-fp-'.bin2hex(random_bytes(4)));
    $txId = tceTransaction($db, $user->id, [
        'booked_at' => '2025-05-15 00:00:00',
        'amount_minor' => -8800,
        'currency' => 'USD',
        'settled_amount_minor' => -8000,
        'fingerprint' => $fingerprintVal,
        'source_format' => 'paypal-csv',
    ]);
    tceTag($db, $user->id, $txId, $catId, ['note' => 'course fee']);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $dataRow = str_getcsv($lines[1]);

    // Columns by index:
    // 9=settled_currency, 10=original_amount, 11=original_currency,
    // 13=transaction_id, 14=source_format, 15=import_run_id, 16=fingerprint

    // The settled figure carries its own code: a Revolut row settles in
    // whatever the file names, so a currency in the header would be a guess.
    expect($dataRow[9])->toBe('EUR');

    // original_amount is formatted from amount_minor: -8800 becomes "88.00".
    expect($dataRow[10])->toMatch('/^\d+\.\d{2}$/');
    expect($dataRow[10])->toBe('88.00');

    expect($dataRow[11])->toBe('USD');

    expect($dataRow[13])->toBe((string) $txId);

    expect($dataRow[14])->toBe('paypal-csv');

    expect($dataRow[16])->toBe($fingerprintVal);

    // note is column 7
    expect($dataRow[7])->toBe('course fee');
});

it('a transaction with tax_year_override appears in the override year, not the booked year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-override-year-user');

    $catId = tceCategory($db, $user->id, 'Overig');

    // Booked in 2026, overridden to 2025.
    $txId = tceTransaction($db, $user->id, [
        'booked_at' => '2026-01-10 00:00:00',
        'settled_amount_minor' => -5000,
    ]);
    tceTag($db, $user->id, $txId, $catId, ['tax_year_override' => 2025]);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);

    $csv2025 = $exporter->export($user, 2025);
    $lines2025 = array_values(array_filter(explode("\n", trim($csv2025))));
    expect(count($lines2025))->toBe(2); // header + 1 data row

    // The data row's tax_year (col 0) must be the override year
    $dataRow2025 = str_getcsv($lines2025[1]);
    expect($dataRow2025[0])->toBe('2025');

    $csv2026 = $exporter->export($user, 2026);
    $lines2026 = array_values(array_filter(explode("\n", trim($csv2026))));
    expect(count($lines2026))->toBe(1); // header only
});

it('escapes formula-leading cells so the CSV is safe to open in Excel', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tceUser($db, 'tce-formula-user');
    $catId = tceCategory($db, $user->id, 'Formula Cat');

    $txId = tceTransaction($db, $user->id, [
        'booked_at' => '2025-04-01 00:00:00',
        'description' => '=HYPERLINK("http://evil.example","click")',
        'counterparty_name' => '@payload',
    ]);
    tceTag($db, $user->id, $txId, $catId, ['note' => '=2+5+cmd|/c calc']);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    // league/csv EscapeFormula prefixes risky cells with a single quote.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("'=2+5+cmd")
        ->and($csv)->not->toContain(',"=HYPERLINK');
});
