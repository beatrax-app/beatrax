<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Support\DonutPalette;

uses(RefreshDatabase::class);

// A chart axis carries ONE currency and a ring ONE direction. Neither was true:
// four currencies were plotted on a euro scale (¥1,000 drawn at 1,000 beside a
// real €1,049.94 bar), and abs() put an Income/Refunds slice in the ring while
// the table below printed the same row as -€34.99 in red.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-15 09:00:00');
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

function acdUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'acd-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function acdCategory(string $name): Category
{
    /** @var Category */
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'acd-'.strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function acdMovement(User $user, string $type, int $minor, string $currency, ?int $categoryId = null, string $accountName = 'Main'): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'acd-'.strtolower($accountName).'-'.$user->id],
        ['name' => $accountName, 'kind' => 'bank', 'iban' => 'NL00ACD'.strtoupper(bin2hex(random_bytes(6))), 'default_currency' => $currency],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/acd-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'acd-'.$suffix),
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
        'posted_at' => '2026-08-10',
        'booked_at' => '2026-08-10 10:00:00',
        'value_date' => '2026-08-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'category_id' => $categoryId,
        'counterparty_name' => 'ACD Vendor',
        'counterparty_normalized' => 'acd-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'acd-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function acdChartOptions(string $html): array
{
    $matches = PatternScan::first('/data-options="([^"]*)"/', $html);
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(html_entity_decode($matches[1] ?? '{}', ENT_QUOTES), true) ?? [];

    return $decoded;
}

function acdRender(User $user, string $viz, string $currencyMode = 'original'): string
{
    test()->actingAs($user);

    return Livewire::test(ReportBuilder::class)
        ->set('dimension', 'account')
        ->set('viz', $viz)
        ->set('currencyMode', $currencyMode)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->html();
}

it('draws one currency on the axis and says which currencies it left off', function (): void {
    $user = acdUser();
    acdMovement($user, 'expense', -104_994, 'EUR', null, 'Euro Account');
    acdMovement($user, 'expense', -1_000, 'JPY', null, 'JP Wallet');
    acdMovement($user, 'expense', -230_000, 'ARS', null, 'Peso Account');

    $html = acdRender($user, ReportViz::Bar->value);
    $options = acdChartOptions($html);

    /** @var list<float> $data */
    $data = $options['series'][0]['data'] ?? [];

    // ¥1,000 used to be plotted at 1000 on a EUR axis beside a 1049.94 EUR bar,
    // and three ARS bars at 2300 with no rate behind them at all.
    expect($options['beatraxCurrency'] ?? null)->toBe('EUR')
        ->and($data)->toBe([1049.94])
        ->and($html)->toContain('data-chart-omission="currencies"')
        ->and($html)->toContain('ARS, JPY');
});

it('headlines the currency the money is worth the most in, not the one with the biggest number', function (): void {
    $user = acdUser();
    acdMovement($user, 'expense', -104_994, 'EUR', null, 'Euro Account');
    acdMovement($user, 'expense', -230_000, 'ARS', null, 'Peso Account');

    $result = app(ReportAggregator::class)->run($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'account',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'original',
        viz: 'bar',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
    ));

    // ARS 2,300.00 is 230,000 minor units and EUR 1,049.94 is 104,994, so the
    // raw comparison headlined a currency with no rate at all.
    expect($result->currency)->toBe('EUR')
        ->and($result->totalMinor)->toBe(104_994);
});

it('keeps a credit out of the ring and says what it left out', function (): void {
    $user = acdUser();
    $groceries = acdCategory('Groceries');
    $refunds = acdCategory('Income Refunds');

    acdMovement($user, 'expense', -242_412, 'EUR', $groceries->id);
    acdMovement($user, 'refund', 3_499, 'EUR', $refunds->id);

    test()->actingAs($user);
    $html = Livewire::test(ReportBuilder::class)
        ->set('viz', ReportViz::Donut->value)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->html();

    $options = acdChartOptions($html);
    /** @var list<float> $series */
    $series = $options['series'] ?? [];

    // abs() drew the refund as a slice of the spending it had just been
    // subtracted from: the ring came to 2,459.11 over a headline of 2,389.13.
    // Now it is the 2,424.12 that went out, with the 34.99 named beside it.
    expect($options['labels'] ?? [])->toBe(['Groceries'])
        ->and(array_sum($series))->toBe(2424.12)
        ->and($html)->toContain('data-chart-omission="undrawn"');
});

it('gives every slice of a long ring its own colour', function (): void {
    $colors = DonutPalette::forSlices(15);

    expect($colors)->toHaveCount(15)
        ->and(array_unique($colors))->toHaveCount(15);
});
