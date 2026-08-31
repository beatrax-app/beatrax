<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
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

function rceDefinition(string $dimension, string $metric = 'spend', bool $compare = false, string $currencyMode = 'base'): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
        dimension: $dimension,
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: 'table',
        compare: $compare,
    );
}

function rceSpend(DatabaseManager $db, User $user, Account $account, int $minor, string $postedAt, ?int $categoryId = null, string $currency = 'EUR'): void
{
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rce-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'rce-'.$suffix),
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
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'category_id' => $categoryId,
        'counterparty_name' => 'RCE Vendor',
        'counterparty_normalized' => 'rce-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rce-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<list<string|null>>
 */
function rceRows(string $csv): array
{
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    return array_map(static fn (string $line): array => str_getcsv($line), $lines);
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

it('dimension=time_bucket header says Period, because a bucket is not always a month', function (): void {
    $user = rceUser();
    test()->actingAs($user);
    $csv = app(ReportCsvExporter::class)->export($user, rceDefinition('time_bucket'));

    $header = str_getcsv(explode("\n", trim($csv))[0]);
    expect($header)->toBe(['Period', 'Metric', 'Amount', 'Currency']);
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

it('writes the Amount column signed, so the file sums to the total the screen shows', function (): void {
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
    // A `net` row carries its direction in the sign and the file carries
    // nothing else to recover it from: stripping it made the export unsummable
    // and put every row at odds with the table it is documented to match.
    expect($dataRow[2])->toBe('-75.00');
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

it('exports the comparison the reader composed, not the uncompared rows underneath it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);
    $account = rceAccount($user);

    /** @var Category $donations */
    $donations = Category::query()->create([
        'user_id' => null,
        'name' => 'Donations',
        'slug' => 'rce-donations-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    /** @var Category $groceries */
    $groceries = Category::query()->create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'rce-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    // Donations exists only in the PREVIOUS period, so it is a row the screen
    // shows at 0.00 with a full-value delta and ->rows has never heard of.
    rceSpend($db, $user, $account, -7_500, now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(), $donations->id);
    rceSpend($db, $user, $account, -4_000, now()->startOfMonth()->addDays(3)->toDateString(), $groceries->id);

    $definition = rceDefinition('category', 'spend', compare: true);
    $result = app(ReportAggregator::class)->run($user, $definition);
    $rows = rceRows(app(ReportCsvExporter::class)->export($user, $definition));

    $labels = array_map(static fn (array $row): string => (string) $row[0], array_slice($rows, 1));

    expect($rows[0])->toBe(['Category', 'Metric', 'Amount', 'Currency', 'Delta'])
        ->and(count($rows) - 1)->toBe(count($result->comparisonRows ?? []))
        ->and($labels)->toContain('Donations')
        ->and($labels)->toContain('Groceries');

    $donationsRow = $rows[array_search('Donations', $labels, true) + 1];
    expect($donationsRow[2])->toBe('0.00')
        ->and($donationsRow[4])->toBe('-75.00');
});

it('leaves the delta cell empty for a row the other window has no counterpart for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);
    $account = rceAccount($user);

    // A time-bucket series joins by position, and the previous window reaches
    // no further than this one: the table prints an em dash for the miss.
    rceSpend($db, $user, $account, -4_000, now()->startOfMonth()->addDays(3)->toDateString());

    $definition = rceDefinition('time_bucket', 'spend', compare: true);
    $rows = rceRows(app(ReportCsvExporter::class)->export($user, $definition));

    expect($rows[0])->toBe(['Period', 'Metric', 'Amount', 'Currency', 'Delta'])
        ->and($rows[1][4])->toBe('');
});

it('sums to the screen total per currency, which is all a file spanning several of them can do', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rceUser();
    test()->actingAs($user);
    $account = rceAccount($user);

    rceSpend($db, $user, $account, -4_000, now()->startOfMonth()->addDays(3)->toDateString());
    rceSpend($db, $user, $account, -900_000, now()->startOfMonth()->addDays(4)->toDateString(), null, 'JPY');

    $definition = rceDefinition('category', 'spend', currencyMode: 'original');
    $result = app(ReportAggregator::class)->run($user, $definition);
    $rows = array_slice(rceRows(app(ReportCsvExporter::class)->export($user, $definition)), 1);

    $byCurrency = [];
    foreach ($rows as $row) {
        $byCurrency[(string) $row[3]] = ($byCurrency[(string) $row[3]] ?? 0) + (int) round(((float) $row[2]) * (((string) $row[3]) === 'JPY' ? 1 : 100));
    }

    // 'original' converts nothing, so a file spanning four currencies has no
    // single sum to check against one headline figure -- only its own.
    expect($byCurrency[$result->currency])->toBe($result->totalMinor)
        ->and(count($byCurrency))->toBeGreaterThan(1);
});
