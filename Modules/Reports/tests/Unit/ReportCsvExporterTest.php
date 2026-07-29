<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub (999.6-03 Task 2, Req 11).
 *
 * Pins Modules\Reports\Internal\Services\ReportCsvExporter::export(User,
 * ReportDefinition): string — mirrors TaxCsvExporterTest's structure. The
 * header's FIRST (group) column MUST reflect the driving definition's
 * dimension — 'Category' | 'Counterparty' | 'Account' | 'Month' — never a
 * generic 'group' literal (Req 11, PATTERNS.md ReportCsvExporter excerpt).
 * This is the dimension-reflective header contract Plan 07 Task 2 turns
 * GREEN.
 *
 * RED as intended: ReportCsvExporter does not exist yet — every test below
 * fails on `app(ReportCsvExporter::class)` (missing class), not on the
 * (already-existing) ReportDefinition DTO it's built from.
 */

function rceUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'rce-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function rceAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-'.bin2hex(random_bytes(3)),
        'kind' => 'asn',
        'iban' => 'NL00RCEX'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function rceDefinition(string $dimension): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: $dimension,
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
    );
}

it('dimension=category header uses the literal Category label, not a generic group column', function (): void {
    $user = rceUser();
    test()->actingAs($user);
    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('category'));

    $header = str_getcsv(explode("\n", trim($csv))[0]);
    expect($header)->toBe(['Category', 'Metric', 'Amount', 'Currency']);
});

it('dimension=counterparty header uses the literal Counterparty label', function (): void {
    $user = rceUser();
    test()->actingAs($user);
    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('counterparty'));

    $header = str_getcsv(explode("\n", trim($csv))[0]);
    expect($header)->toBe(['Counterparty', 'Metric', 'Amount', 'Currency']);
});

it('dimension=account header uses the literal Account label', function (): void {
    $user = rceUser();
    test()->actingAs($user);
    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('account'));

    $header = str_getcsv(explode("\n", trim($csv))[0]);
    expect($header)->toBe(['Account', 'Metric', 'Amount', 'Currency']);
});

it('dimension=time_bucket header uses the literal Month label, not group or time_bucket', function (): void {
    $user = rceUser();
    test()->actingAs($user);
    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('time_bucket'));

    $header = str_getcsv(explode("\n", trim($csv))[0]);
    expect($header)->toBe(['Month', 'Metric', 'Amount', 'Currency']);
});

it('data rows match the aggregator totals for the same definition', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);
    $account = rceAccount($user);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rce-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'rce-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => now()->toDateString(),
        'booked_at' => now()->toDateTimeString(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -7_500,
        'currency' => 'EUR',
        'settled_amount_minor' => -7_500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RCE Vendor',
        'counterparty_normalized' => 'rce-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rce-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('category'));
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // Header + exactly 1 data row for the single seeded expense.
    expect(count($lines))->toBe(2);
    $dataRow = str_getcsv($lines[1]);
    expect($dataRow[1])->toBe('spend');
    expect($dataRow[2])->toBe('75.00');
    expect($dataRow[3])->toBe('EUR');
});
