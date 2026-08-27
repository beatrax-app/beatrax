<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// The "vs. previous period" figure was re-derived by adding up every displayed
// row's previousAmountMinor. In 'original' mode that is one row PER CURRENCY, so
// USD cents went straight into a EUR sum; for net worth it added a balance up
// once per bucket, which is the exact arithmetic buildNetWorthResult() documents
// itself as avoiding for the current total.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function hdrUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'hdr-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function hdrMovement(User $user, string $type, int $amountMinor, string $postedAt, string $currency = 'EUR'): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'hdr-'.$user->id],
        ['name' => 'hdr account', 'kind' => 'bank', 'iban' => 'NL00HDR'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/hdr-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'hdr-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'HDR Vendor',
        'counterparty_normalized' => 'hdr-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'hdr-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function hdrDefinition(string $metric, string $currencyMode = 'base'): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
        compare: true,
    );
}

it('carries the previous window own total rather than leaving the page to add the rows up', function (): void {
    $user = hdrUser();
    hdrMovement($user, 'expense', -100_00, '2026-08-05');
    hdrMovement($user, 'expense', -60_00, '2026-07-05');

    $result = app(ReportAggregator::class)->run($user, hdrDefinition('spend'));

    expect($result->totalMinor)->toBe(100_00)
        ->and($result->previousTotalMinor)->toBe(60_00)
        ->and($result->previousCurrency)->toBe('EUR');
});

// 200000 USD cents used to be added straight into a EUR sum, which the delta was
// then computed against.
it('never adds a foreign currency row into the headline currency delta', function (): void {
    $user = hdrUser();
    hdrMovement($user, 'expense', -100_00, '2026-08-05', 'EUR');
    hdrMovement($user, 'expense', -60_00, '2026-07-05', 'EUR');
    hdrMovement($user, 'expense', -2000_00, '2026-07-06', 'USD');

    $result = app(ReportAggregator::class)->run($user, hdrDefinition('spend', 'original'));

    // Summing the displayed rows' previousAmountMinor gave 60,00 EUR + 2000,00
    // USD = 206000 and called it euros. Each window keeps its own currency.
    expect($result->currency)->toBe('EUR')
        ->and($result->totalMinor)->toBe(100_00)
        ->and($result->previousCurrency)->toBe('USD')
        ->and($result->previousTotalMinor)->toBe(2000_00);
});

it('says nothing rather than a number when the two windows headline different currencies', function (): void {
    $user = hdrUser();
    hdrMovement($user, 'expense', -100_00, '2026-08-05', 'EUR');
    hdrMovement($user, 'expense', -2000_00, '2026-07-06', 'USD');
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('currencyMode', 'original')
        ->set('compare', true)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->html();

    // A EUR total minus a USD total is not a figure, so no figure is printed.
    expect($html)->toContain('vs. previous period')
        ->and($html)->not->toContain('+&euro;&nbsp;1.900,00');
});

it('compares a net worth balance against the previous window balance, not against zero', function (): void {
    $user = hdrUser();
    hdrMovement($user, 'income', 100_00, '2026-07-05');
    hdrMovement($user, 'income', 40_00, '2026-08-05');

    $result = app(ReportAggregator::class)->run($user, hdrDefinition('net_worth'));

    // Net worth is a balance: August closes at 140,00 and July at 100,00.
    expect($result->totalMinor)->toBe(140_00)
        ->and($result->previousTotalMinor)->toBe(100_00);
});
