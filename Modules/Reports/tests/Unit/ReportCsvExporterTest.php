<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Services\ReportCsvExporter;

uses(RefreshDatabase::class);

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
        'kind' => 'bank',
        'iban' => 'NL00RCEX'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function rceDefinition(string $dimension, string $metric = 'spend'): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
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

    expect(count($lines))->toBe(2);
    $dataRow = str_getcsv($lines[1]);
    expect($dataRow[1])->toBe('spend');
    expect($dataRow[2])->toBe('75.00');
    expect($dataRow[3])->toBe('EUR');
});

it('writes the Amount column unsigned, so a negative aggregate keeps its magnitude', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);
    $account = rceAccount($user);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rce-neg-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'rce-neg-'.bin2hex(random_bytes(4))),
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
        'fingerprint' => hash('sha256', 'rce-neg-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('category', 'net'));
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect(count($lines))->toBe(2);
    $dataRow = str_getcsv($lines[1]);
    expect($dataRow[2])->toBe('75.00')
        ->and($dataRow[2])->not->toContain('-');
});

it('escapes formula-leading group labels so the CSV is safe to open in Excel', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);

    // Free text: for dimension=account the account name becomes the group label.
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => '=HYPERLINK("http://evil.example","click")',
        'slug' => 'rce-formula-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RCEX'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rce-formula-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'rce-formula-'.bin2hex(random_bytes(4))),
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
        'counterparty_name' => '@payload',
        'counterparty_normalized' => 'payload',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rce-formula-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('account'));

    // EscapeFormula prefixes the cell with a single quote; without it the label
    // ships as a live =HYPERLINK formula.
    expect($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain('"=HYPERLINK');
});
