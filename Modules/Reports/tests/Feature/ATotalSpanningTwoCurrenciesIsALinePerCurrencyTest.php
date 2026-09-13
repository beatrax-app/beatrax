<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportCurrencyMode;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// 'original' mode converts nothing, so the report's rows span every currency
// the period held while `totalMinor` names one of them. The table listed
// €1,049.94 and ¥1,000 and footed the column with €1,049.94, and the line that
// says what was left off is drawn under a CHART — the default visualisation is
// the table, which drew none of it.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function tslcUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'tslc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function tslcMovement(User $user, int $minor, string $currency, string $accountName): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'tslc-'.strtolower($accountName).'-'.$user->id],
        ['name' => $accountName, 'kind' => 'bank', 'iban' => 'NL00TSLC'.strtoupper(bin2hex(random_bytes(6))), 'default_currency' => $currency],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tslc-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tslc-'.$suffix),
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
        'posted_at' => '2026-08-10',
        'booked_at' => '2026-08-10 10:00:00',
        'value_date' => '2026-08-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'TSLC Vendor',
        'counterparty_normalized' => 'tslc-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'tslc-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tslcDefinition(string $currencyMode): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'account',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: ReportViz::Table->value,
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
    );
}

it('answers with one total per currency the rows are denominated in', function (): void {
    $user = tslcUser();
    tslcMovement($user, -104_994, 'EUR', 'Euro Account');
    tslcMovement($user, -1_000, 'JPY', 'JP Wallet');

    $result = app(ReportAggregator::class)->run($user, tslcDefinition(ReportCurrencyMode::Original->value));

    // The headline first, because that is the currency `totalMinor` and every
    // delta beside it are denominated in.
    expect($result->totalLines())->toBe(['EUR' => 104_994, 'JPY' => 1_000]);
});

it('keeps one line, not two, where the mode converted everything into one currency', function (): void {
    $user = tslcUser();
    tslcMovement($user, -104_994, 'EUR', 'Euro Account');
    tslcMovement($user, -1_000, 'JPY', 'JP Wallet');

    $result = app(ReportAggregator::class)->run($user, tslcDefinition(ReportCurrencyMode::Base->value));

    expect($result->totalLines())->toBe(['EUR' => $result->totalMinor])
        ->and($result->currency)->toBe('EUR');
});

it('foots the table with every currency its rows carry', function (): void {
    $user = tslcUser();
    tslcMovement($user, -104_994, 'EUR', 'Euro Account');
    tslcMovement($user, -1_000, 'JPY', 'JP Wallet');

    test()->actingAs($user);
    $html = Livewire::test(ReportBuilder::class)
        ->set('dimension', 'account')
        ->set('viz', ReportViz::Table->value)
        ->set('currencyMode', ReportCurrencyMode::Original->value)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->html();

    // Read out of the totals row itself. A bare "1.000" needle matches the
    // ¥1,000 row above it, and a short numeric one matches a wire:key.
    $foot = PatternScan::first('/<tfoot>(.*?)<\/tfoot>/s', $html)[1] ?? '';

    expect($foot)->toContain(Money::ofMinor(104_994, 'EUR')->format())
        ->and($foot)->toContain(Money::ofMinor(1_000, 'JPY')->format());
});
