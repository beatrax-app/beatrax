<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// `refund` was in no metric and in no disclosure: real money in was never
// mentioned anywhere, and a refunded purchase over-reported spend by the whole
// refund. Separately, 'original' mode reported fees for the headline currency
// alone, so a fee bucket in any other currency vanished from a page whose one
// job is not to let a total omit money silently.

function rafUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'raf-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function rafMovement(User $user, string $type, int $amountMinor, string $currency = 'EUR'): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'raf-'.$user->id],
        ['name' => 'raf account', 'kind' => 'bank', 'iban' => 'NL00RAF'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/raf-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'raf-'.$suffix),
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
        'posted_at' => '2026-08-05',
        'booked_at' => '2026-08-05 10:00:00',
        'value_date' => '2026-08-05',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'RAF Vendor',
        'counterparty_normalized' => 'raf-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'raf-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rafDefinition(string $metric, string $currencyMode = 'base'): ReportDefinition
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
    );
}

it('nets a refund off the spend it reverses instead of over-reporting the purchase', function (): void {
    $user = rafUser();
    rafMovement($user, 'expense', -100_00);
    rafMovement($user, 'refund', 25_00);

    expect(app(ReportAggregator::class)->run($user, rafDefinition('spend'))->totalMinor)->toBe(75_00);
});

it('counts a refund as money arriving in the net figure', function (): void {
    $user = rafUser();
    rafMovement($user, 'expense', -100_00);
    rafMovement($user, 'refund', 25_00);

    expect(app(ReportAggregator::class)->run($user, rafDefinition('net'))->totalMinor)->toBe(-75_00);
});

it('does not fold a refund into income, which would count it a second time', function (): void {
    $user = rafUser();
    rafMovement($user, 'income', 500_00);
    rafMovement($user, 'refund', 25_00);

    expect(app(ReportAggregator::class)->run($user, rafDefinition('income'))->totalMinor)->toBe(500_00);
});

it('says a refund beside the total of the one metric that does not count it', function (): void {
    $user = rafUser();
    rafMovement($user, 'income', 500_00);
    rafMovement($user, 'refund', 25_00);

    $aggregator = app(ReportAggregator::class);

    expect($aggregator->run($user, rafDefinition('income'))->otherMovementsByCurrency)->toBe(['EUR' => 25_00])
        ->and($aggregator->run($user, rafDefinition('spend'))->otherMovementsByCurrency)->toBe([]);
});

it('names refunds in the disclosure line only when the disclosure carries one', function (): void {
    $user = rafUser();
    rafMovement($user, 'income', 500_00);
    rafMovement($user, 'refund', 25_00);
    test()->actingAs($user);

    Livewire::test(ReportBuilder::class)
        ->set('metric', 'income')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->assertSee('Fees, refunds and adjustments (not counted above)')
        ->set('metric', 'net')
        ->assertDontSee('Fees, refunds and adjustments (not counted above)');
});

it('reports a fee in every currency it was charged in, not just the headline one', function (): void {
    $user = rafUser();
    rafMovement($user, 'expense', -100_00, 'EUR');
    rafMovement($user, 'fee', -150, 'EUR');
    rafMovement($user, 'fee', -220, 'USD');

    $result = app(ReportAggregator::class)->run($user, rafDefinition('spend', 'original'));

    expect($result->currency)->toBe('EUR')
        ->and($result->otherMovementsByCurrency)->toBe(['EUR' => 150, 'USD' => 220]);
});

it('puts the non-headline currency fee on the page in its own currency', function (): void {
    App::setLocale('nl');

    $user = rafUser();
    rafMovement($user, 'expense', -100_00, 'EUR');
    rafMovement($user, 'fee', -220, 'USD');
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('currencyMode', 'original')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->html();

    expect($html)->toContain('Kosten en correcties (niet meegeteld)')
        ->and($html)->toContain('2,20');
});
