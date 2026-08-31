<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// "at least 20" was parsed at a hard two decimals and applied unconverted to
// every currency at once: it became 2 000 minor units against a ¥1 000 charge,
// and simultaneously meant EUR 20, USD 20 and ARS 20.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-04-15 09:00:00');
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function amfUser(string $baseCurrency): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'amf-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => $baseCurrency,
    ]);
}

function amfRate(string $quote, string $rate): void
{
    app(DatabaseManager::class)->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => $quote,
        'rate_date' => '2026-04-01',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ]);
}

function amfCharge(User $user, string $currency, int $minor, string $name): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'amf-'.strtolower($name).'-'.$user->id],
        ['name' => $name, 'kind' => 'bank', 'iban' => 'NL00AMF'.strtoupper(bin2hex(random_bytes(6))), 'default_currency' => $currency],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/amf-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'amf-'.$suffix),
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
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'AMF Vendor',
        'counterparty_normalized' => 'amf-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'amf-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function amfReport(User $user, ?string $amountMin, string $currencyMode = 'base'): object
{
    return app(ReportAggregator::class)->run($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'account',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
        amountMin: $amountMin,
    ));
}

it('reads a yen threshold at the yen scale, where twenty is twenty', function (): void {
    $user = amfUser('JPY');
    amfCharge($user, 'JPY', -1_000, 'JP Wallet');

    $unfiltered = amfReport($user, null);
    $filtered = amfReport($user, '20');

    // The threshold used to become 2 000 minor units, compared against ¥1 000,
    // and the only row in the report disappeared.
    expect($unfiltered->totalMinor)->toBe(1_000)
        ->and($filtered->totalMinor)->toBe(1_000)
        ->and($filtered->rows)->toHaveCount(1);
});

it('means the same amount of money in every currency it is applied to', function (): void {
    $user = amfUser('EUR');
    amfRate('JPY', '160.0');
    amfCharge($user, 'EUR', -5_000, 'Euro Account');
    // ¥2 500 is the case that tells the two rules apart: it clears the raw
    // 2 000 minor units "20" used to become, and is under the EUR 20 the
    // reader actually typed.
    amfCharge($user, 'JPY', -2_500, 'JP Snack');
    amfCharge($user, 'JPY', -400_000, 'JP Savings');

    $filtered = amfReport($user, '20', 'original');
    $labels = array_map(static fn ($row): string => $row->groupLabel, $filtered->rows);

    // EUR 20 is ¥3 200: the ¥2 500 charge is under the bar the reader set and
    // the ¥400 000 one is over it.
    expect($labels)->toContain('Euro Account')
        ->and($labels)->toContain('JP Savings')
        ->and($labels)->not->toContain('JP Snack');
});

it('excludes and names a currency whose bound no rate can state, rather than dropping it quietly', function (): void {
    $user = amfUser('EUR');
    amfCharge($user, 'EUR', -5_000, 'Euro Account');
    amfCharge($user, 'ARS', -57_500, 'Peso Account');

    // 'original' converts no ROW, so the only thing a missing rate can stop
    // here is the bound itself: a silent 1:1 would have listed the peso row
    // under a threshold nobody could state in pesos.
    $filtered = amfReport($user, '20', 'original');
    $labels = array_map(static fn ($row): string => $row->groupLabel, $filtered->rows);

    expect($labels)->toBe(['Euro Account'])
        ->and($filtered->excludedCurrencies)->toBe(['ARS']);
});
