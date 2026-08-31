<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// Report metrics cover expense and income alone, so fees and adjustments fell
// out silently: the demo month read EUR 2.459,11 against EUR 2.468,11, exactly a
// -1,50 fee and a -7,50 adjustment. The exclusion stays; the page now says so.

function feesUser(): User
{
    return User::query()->create([
        'username' => 'fees-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function feesSeed(User $user, string $type, int $amountMinor, int $rowIndex): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'fees-'.$user->id],
        ['name' => 'fees account', 'kind' => 'bank', 'iban' => 'NL00FEES'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $run = ImportRun::query()->firstOrCreate(
        ['user_id' => $user->id, 'sha256' => str_pad((string) $user->id, 64, 'f', STR_PAD_LEFT)],
        ['source_format' => 'asn-csv', 'raw_file_path' => '/tmp/fees.csv', 'uploaded_at' => CarbonImmutable::parse('2026-08-01 00:00:00'), 'status' => 'previewed'],
    );

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => '2026-08-05',
        'booked_at' => '2026-08-05 12:00:00',
        'value_date' => '2026-08-05',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Fees fixture',
        'counterparty_normalized' => 'fees fixture',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('fees'.$user->id.$rowIndex, 64, 'e', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-08-05 12:00:00',
        'updated_at' => '2026-08-05 12:00:00',
    ]);
}

function feesDefinition(): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
    );
}

it('reports the fees and adjustments the total leaves out', function (): void {
    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);
    feesSeed($user, 'fee', -150, 1);
    feesSeed($user, 'adjustment', -750, 2);

    $result = app(ReportAggregator::class)->run($user, feesDefinition());

    // The total is unchanged — the exclusion was deliberate and stays.
    expect($result->totalMinor)->toBe(200000)
        ->and($result->otherMovementsByCurrency)->toBe(['EUR' => 900]);
});

it('reports nothing when there is nothing excluded', function (): void {
    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);

    expect(app(ReportAggregator::class)->run($user, feesDefinition())->otherMovementsByCurrency)->toBe([]);
});

it('honours the report filters', function (): void {
    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);
    feesSeed($user, 'fee', -150, 1);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
        // Nothing this user owns, so the fee is outside the filter too.
        accounts: [999999],
    );

    expect(app(ReportAggregator::class)->run($user, $definition)->otherMovementsByCurrency)->toBe([]);
});

it('says so on the page, in the interface language', function (): void {
    App::setLocale('nl');

    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);
    feesSeed($user, 'fee', -150, 1);
    feesSeed($user, 'adjustment', -750, 2);

    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('metric', 'spend')
        ->set('dimension', 'category')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-01')
        ->set('customTo', '2026-08-31')
        ->set('viz', 'table')
        ->html();

    expect($html)->toContain('Kosten en correcties (niet meegeteld)')
        ->and($html)->toContain('9,00');
});

it('reads a fee the same way as the total it sits beside', function (string $metric, int $expected): void {
    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);
    feesSeed($user, 'income', 500000, 1);
    feesSeed($user, 'fee', -150, 2);
    feesSeed($user, 'adjustment', -750, 3);

    $definition = new ReportDefinition(
        metric: $metric,
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
    );

    // A spend report reads positive, so its fee does too; income and net read
    // signed, so the same 9.00 of fees reads as money leaving.
    expect(app(ReportAggregator::class)->run($user, $definition)->otherMovementsByCurrency)->toBe(['EUR' => $expected]);
})->with([
    ['spend', 900],
    ['income', -900],
    ['net', -900],
]);

it('carries the fees through the original-currency path too', function (): void {
    $user = feesUser();
    feesSeed($user, 'expense', -200000, 0);
    feesSeed($user, 'fee', -150, 1);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'original',
        viz: 'table',
        customFrom: '2026-08-01',
        customTo: '2026-08-31',
    );

    expect(app(ReportAggregator::class)->run($user, $definition)->otherMovementsByCurrency)->toBe(['EUR' => 150]);
});
