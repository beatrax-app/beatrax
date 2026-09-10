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

// A currency carrying only fees reaches no dimension query, so the one line
// that ever named it was the other-movement disclosure — and an amount bound
// no rate can restate in that currency dropped its whole bucket in silence.
// ¥1.000 of fees left the page the moment the reader typed "10" into the
// amount box, under a total that then read as everything that had left.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function afdUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'afd-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function afdMovement(User $user, string $type, string $currency, int $minor): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'afd-'.strtolower($currency).'-'.$user->id],
        [
            'name' => 'afd '.$currency,
            'kind' => 'bank',
            'iban' => 'NL00AFD'.strtoupper(substr(hash('crc32b', $currency.$user->id), 0, 11)),
            'default_currency' => $currency,
        ],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/afd-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'afd-'.$suffix),
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
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'AFD Vendor',
        'counterparty_normalized' => 'afd-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'afd-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function afdDefinition(?string $amountMin, string $currencyMode = 'base'): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: $currencyMode,
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
        amountMin: $amountMin,
    );
}

// The control: with no bound the page already says the yen is missing, so the
// case below is a filter taking a disclosure away rather than never having had one.
it('names the currency it cannot convert when no amount filter is set', function (): void {
    $user = afdUser();
    afdMovement($user, 'expense', 'EUR', -5_000);
    afdMovement($user, 'fee', 'JPY', -1_000);

    $result = app(ReportAggregator::class)->run($user, afdDefinition(null));

    expect($result->totalMinor)->toBe(5_000)
        ->and($result->excludedCurrencies)->toBe(['JPY']);
});

it('still names it once an amount filter is on', function (string $currencyMode): void {
    $user = afdUser();
    afdMovement($user, 'expense', 'EUR', -5_000);
    afdMovement($user, 'fee', 'JPY', -1_000);

    $result = app(ReportAggregator::class)->run($user, afdDefinition('10', $currencyMode));

    expect($result->excludedCurrencies)->toBe(['JPY'])
        // The bound is answerable in the reader's own currency, so the figure
        // the filter was set for is untouched by the disclosure beside it.
        ->and($result->totalMinor)->toBe(5_000);
})->with(['base', 'original']);

// A bucket that cannot be restated under the reader's bound is not a bucket of
// zero, so it is named rather than reported at a figure the filter never met.
it('reports no figure for the bucket it could not bound', function (): void {
    $user = afdUser();
    afdMovement($user, 'expense', 'EUR', -5_000);
    afdMovement($user, 'fee', 'JPY', -1_000);

    expect(app(ReportAggregator::class)->run($user, afdDefinition('10', 'original'))->otherMovementsByCurrency)
        ->toBe([]);
});

it('says so on the page the reader set the filter on', function (): void {
    $user = afdUser();
    afdMovement($user, 'expense', 'EUR', -5_000);
    afdMovement($user, 'fee', 'JPY', -1_000);
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-04-01')
        ->set('customTo', '2026-04-30')
        ->set('filterAmountMin', '10')
        ->html();

    expect($html)->toContain('JPY not converted');
});
