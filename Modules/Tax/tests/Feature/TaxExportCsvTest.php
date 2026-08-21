<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Services\TaxCsvExporter;

function tcefUser(DatabaseManager $db, string $username): User
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
function tcefTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TCEF ASN '.$suffix,
        'slug' => 'tcef-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tcef-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tcef-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tcef-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-06-01',
        'booked_at' => '2025-06-01 00:00:00',
        'value_date' => '2025-06-01',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'tcef-vendor',
        'counterparty_name' => 'TCEF Vendor BV',
        'normalization_version' => 1,
        'description' => 'TCEF test transaction',
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

function tcefCategory(DatabaseManager $db, int $userId, string $name = 'Studiekosten'): int
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
function tcefTag(DatabaseManager $db, int $userId, int $txId, ?int $catId = null, array $overrides = []): void
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

it('CSV header is the exact 16 D-15 columns in documented order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tcefUser($db, 'tcef-header-user');

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = str_getcsv($lines[0]);

    expect($header)->toBe([
        'tax_year', 'booked_date', 'account', 'counterparty',
        'counterparty_iban', 'description', 'deduction_category',
        'note', 'settled_eur_amount', 'original_amount',
        'original_currency', 'transaction_type', 'transaction_id',
        'source_format', 'import_run_id', 'fingerprint',
    ]);
});

it('contains one data row per tagged transaction in the year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tcefUser($db, 'tcef-row-count-user');

    $catId = tcefCategory($db, $user->id, 'Zorgkosten');

    $tx1 = tcefTransaction($db, $user->id, ['booked_at' => '2025-01-15 00:00:00']);
    $tx2 = tcefTransaction($db, $user->id, ['booked_at' => '2025-04-20 00:00:00']);
    $tx3 = tcefTransaction($db, $user->id, ['booked_at' => '2025-09-01 00:00:00']);

    tcefTag($db, $user->id, $tx1, $catId);
    tcefTag($db, $user->id, $tx2, $catId);
    tcefTag($db, $user->id, $tx3, $catId);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    // Header + 3 data rows
    expect(count($lines))->toBe(4);
});

it('settled_eur_amount is a 2-decimal string (not a float)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tcefUser($db, 'tcef-money-str-user');

    $catId = tcefCategory($db, $user->id, 'Overig');

    $tx = tcefTransaction($db, $user->id, [
        'settled_amount_minor' => -9999,
        'booked_at' => '2025-07-01 00:00:00',
    ]);
    tcefTag($db, $user->id, $tx, $catId);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $row = str_getcsv($lines[1]);

    // settled_eur_amount at index 8
    expect($row[8])->toMatch('/^\d+\.\d{2}$/')
        ->and($row[8])->toBe('99.99');
});

it('uses COALESCE tax_year_override: row with override=2025 appears in 2025, not 2026', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tcefUser($db, 'tcef-year-override-user');

    $catId = tcefCategory($db, $user->id, 'Override Cat');

    // Booked in 2026, tagged with an override of 2025.
    $txId = tcefTransaction($db, $user->id, [
        'booked_at' => '2026-02-10 00:00:00',
        'settled_amount_minor' => -7700,
    ]);
    tcefTag($db, $user->id, $txId, $catId, ['tax_year_override' => 2025]);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);

    $csv2025 = $exporter->export($user, 2025);
    $lines2025 = array_values(array_filter(explode("\n", trim($csv2025))));
    expect(count($lines2025))->toBe(2);

    $row2025 = str_getcsv($lines2025[1]);
    expect($row2025[0])->toBe('2025'); // tax_year column

    $csv2026 = $exporter->export($user, 2026);
    $lines2026 = array_values(array_filter(explode("\n", trim($csv2026))));
    expect(count($lines2026))->toBe(1); // header only
});

it('null deduction category renders as empty string in the deduction_category column', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tcefUser($db, 'tcef-no-cat-user');

    $txId = tcefTransaction($db, $user->id, ['booked_at' => '2025-03-15 00:00:00']);
    tcefTag($db, $user->id, $txId, null);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $csv = $exporter->export($user, 2025);

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $row = str_getcsv($lines[1]);

    // deduction_category at index 6
    expect($row[6])->toBe('');
});
