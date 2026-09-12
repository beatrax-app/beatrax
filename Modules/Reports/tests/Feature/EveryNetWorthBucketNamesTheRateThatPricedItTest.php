<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\Rate;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// The net-worth metric converted every account line at its bucket's own
// historical rate and named none of them: sixty buckets, sixty rate sets, and a
// headline figure standing bare above them. Every figure now names the rates
// that priced IT -- each bucket row its own, and the headline the legs of the
// bucket it is the figure of, which is the last one.
//
// Folding the oldest leg of the whole series onto the headline instead was
// tried and rejected here: it quotes a rate that cannot rebuild the figure
// beside it. On this fixture it prices the headline EUR 6,858.56 low.

// EUR 1 = JPY 159.10 read the other way round, at the column's own eight
// places. forDisplay() would keep 0.00629, which does not rebuild the figure.
const BKT_JULY_RATE = '0.00628536';

// A terminating rate, so the figure the headline prints and the figure its
// quoted rate rebuilds can be compared to the cent with nothing to forgive.
const BKT_SEPTEMBER_RATE = '0.008';

const BKT_HEADLINE_MINOR = 3_400_000;

const BKT_JULY_BUCKET_MINOR = 2_714_142;

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function bktRate(string $date, string $quote, string $rate): void
{
    app(DatabaseManager::class)->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => $quote,
        'rate_date' => $date,
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function bktUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'bkt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function bktAccount(User $user, string $currency, string $name): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'bkt-'.strtolower($currency).'-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BKT'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

function bktBalance(User $user, Account $account, int $minor, string $currency, string $postedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bkt-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'bkt-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'income',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'BKT Vendor',
        'counterparty_normalized' => 'bkt-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'bkt-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function bktReport(User $user): ReportResultDto
{
    return app(ReportAggregator::class)->run($user, new ReportDefinition(
        metric: 'net_worth',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-07-01',
        customTo: '2026-09-30',
    ));
}

// The rate is read off the leg rather than off its rendered string: Fmt applies
// the reader's own decimal separator, and the figure under test is the number.
function bktExactRates(?ConversionDisclosure $disclosure): array
{
    $exact = [];

    foreach ($disclosure?->rates ?? [] as $leg) {
        $exact[$leg->from] = Rate::parse($leg->rate)?->exact();
    }

    return $exact;
}

// A yen holding present from July, priced at 159.10 until 20 September and at
// 125.00 after it: the LAST bucket, which is the one the headline figure is
// taken from, converts at the newer rate.
function bktYenSeries(): User
{
    $user = bktUser();
    bktRate('2026-07-10', 'JPY', '159.10');
    bktRate('2026-09-20', 'JPY', '125.00');

    bktBalance($user, bktAccount($user, 'EUR', 'Home account'), 200_000, 'EUR', '2026-07-05');
    bktBalance($user, bktAccount($user, 'JPY', 'Tokyo account'), 4_000_000, 'JPY', '2026-07-05');

    return $user;
}

it('quotes a headline rate that rebuilds the headline figure', function (): void {
    $result = bktReport(bktYenSeries());

    expect($result->totalMinor)->toBe(BKT_HEADLINE_MINOR)
        ->and($result->conversion?->asOf()?->toDateString())->toBe('2026-09-20')
        ->and(bktExactRates($result->conversion))->toBe(['JPY' => BKT_SEPTEMBER_RATE]);

    // EUR 2,000.00 of euro holdings plus JPY 4,000,000 at the quoted rate. A
    // reader who opens the panel came to check the figure against the rate,
    // and the two have to be the same figure.
    expect(200_000 + (int) round(4_000_000 * (float) BKT_SEPTEMBER_RATE * 100))->toBe(BKT_HEADLINE_MINOR);

    // The rejected rule would have dated it 10 July at 0.00628536, which
    // rebuilds the JULY bucket and misses the headline by EUR 6,858.56.
    expect(200_000 + (int) round(4_000_000 * (float) BKT_JULY_RATE * 100))->toBe(2_714_144)
        ->and(BKT_HEADLINE_MINOR - 2_714_144)->toBe(685_856);
});

// The headline figure is the last bucket's figure, so it is the last bucket's
// legs it has to name -- one disclosure, read off one point, and not a second
// derivation that could come to disagree with the row under it.
it('says exactly what the bucket its figure comes from says', function (): void {
    $result = bktReport(bktYenSeries());

    $lastRow = $result->rows[count($result->rows) - 1];

    expect($lastRow->amountMinor)->toBe($result->totalMinor)
        ->and($lastRow->conversion?->asOf()?->toDateString())->toBe($result->conversion?->asOf()?->toDateString())
        ->and(bktExactRates($lastRow->conversion))->toBe(bktExactRates($result->conversion));
});

it('gives each bucket the rate that priced it', function (): void {
    $result = bktReport(bktYenSeries());

    $byBucket = [];
    foreach ($result->rows as $row) {
        $byBucket[(string) $row->groupKey] = [
            $row->conversion?->asOf()?->toDateString(),
            bktExactRates($row->conversion)['JPY'] ?? null,
        ];
    }

    expect($byBucket)->toBe([
        '2026-07-31' => ['2026-07-10', BKT_JULY_RATE],
        '2026-08-31' => ['2026-07-10', BKT_JULY_RATE],
        '2026-09-30' => ['2026-09-20', BKT_SEPTEMBER_RATE],
    ]);

    // And each row's own rate rebuilds its own figure, which is the whole
    // reason a bucket discloses rather than inheriting the headline's.
    expect($result->rows[0]->amountMinor)->toBe(BKT_JULY_BUCKET_MINOR)
        ->and(200_000 + (int) round(4_000_000 * (float) BKT_JULY_RATE * 100))->toBe(BKT_JULY_BUCKET_MINOR + 2);
});

// A rate rounded for display cannot rebuild the figure beside it: 0.00629
// prices the same holding EUR 116.00 higher than the series did.
it('quotes the rate at enough places to rebuild the figure it converted', function (): void {
    $user = bktYenSeries();
    $result = bktReport($user);
    $html = bktRenderedReport($user);

    expect($html)->toContain(BKT_JULY_RATE)
        ->and($html)->not->toContain('0.00629')
        ->and($result->rows[0]->conversion?->rates[0]->rateForDisplay())->toBe(BKT_JULY_RATE);
});

function bktRenderedReport(User $user): string
{
    test()->actingAs($user);

    return Livewire::test(ReportBuilder::class)
        ->set('metric', 'net_worth')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-07-01')
        ->set('customTo', '2026-09-30')
        ->html();
}

it('renders the rate beside the headline and again on every bucket row', function (): void {
    $html = bktRenderedReport(bktYenSeries());

    expect($html)->toContain('id="fx-report-total"')
        ->and($html)->toContain('id="fx-report-row-0"')
        ->and($html)->toContain('id="fx-report-row-2"')
        ->and(substr_count($html, 'data-fx-rates="1"'))->toBe(4);
});

// A bucket whose balances were all already in the reader's currency converted
// nothing, so it has no rate to be missing -- and must not drag an undated leg
// into the figure above it, which would date the headline by nothing at all.
it('says nothing at all where every bucket was already in the reader currency', function (): void {
    $user = bktUser();
    bktRate('2026-07-10', 'JPY', '159.10');
    bktBalance($user, bktAccount($user, 'EUR', 'Home account'), 200_000, 'EUR', '2026-07-05');

    $result = bktReport($user);

    expect($result->conversion)->toBeNull();
    foreach ($result->rows as $row) {
        expect($row->conversion)->toBeNull();
    }

    expect(bktRenderedReport($user))->not->toContain('data-fx-disclosure');
});

// The same, mixed: an account denominated in euro that takes a yen holding in
// September. July and August converted nothing and disclose nothing, and the
// July rate -- which no bucket in this series was ever priced at -- reaches no
// figure on the page.
it('leaves a passthrough bucket silent while the converted one speaks', function (): void {
    $user = bktUser();
    bktRate('2026-07-10', 'JPY', '159.10');
    bktRate('2026-09-20', 'JPY', '125.00');

    $account = bktAccount($user, 'EUR', 'Home account');
    bktBalance($user, $account, 200_000, 'EUR', '2026-07-05');
    bktBalance($user, $account, 4_000_000, 'JPY', '2026-09-25');

    $result = bktReport($user);

    expect($result->rows[0]->conversion)->toBeNull()
        ->and($result->rows[1]->conversion)->toBeNull()
        ->and($result->rows[2]->conversion?->asOf()?->toDateString())->toBe('2026-09-20')
        ->and($result->conversion?->asOf()?->toDateString())->toBe('2026-09-20')
        ->and(bktExactRates($result->conversion))->toBe(['JPY' => BKT_SEPTEMBER_RATE])
        ->and(bktRenderedReport($user))->not->toContain(BKT_JULY_RATE);
});

// One figure, two legs -- which is the multi-leg conversion the oldest-leg rule
// is actually about. The panel lists both pairs so the reader can rebuild
// either half, and the line above it is dated by the older of the two.
it('dates a figure with two legs by the older of them', function (): void {
    $user = bktUser();
    bktRate('2026-06-02', 'JPY', '159.10');
    bktRate('2026-09-29', 'USD', '1.1359');

    bktBalance($user, bktAccount($user, 'JPY', 'Tokyo account'), 4_000_000, 'JPY', '2026-07-05');
    bktBalance($user, bktAccount($user, 'USD', 'New York account'), 100_000, 'USD', '2026-07-05');

    $result = bktReport($user);

    expect($result->conversion?->asOf()?->toDateString())->toBe('2026-06-02')
        ->and(array_keys(bktExactRates($result->conversion)))->toBe(['JPY', 'USD']);
});

it('leaves a transaction report naming one rate set for the whole table', function (): void {
    $user = bktUser();
    bktRate('2026-07-10', 'JPY', '159.10');
    bktBalance($user, bktAccount($user, 'JPY', 'Tokyo account'), 4_000_000, 'JPY', '2026-07-05');
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('metric', 'income')
        ->set('dimension', 'account')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-07-01')
        ->set('customTo', '2026-09-30')
        ->html();

    // One disclosure, the headline's: a category total is converted at the very
    // rate set the headline already names, and a copy per row would be the same
    // sentence written once per group.
    expect(substr_count($html, 'data-fx-rates='))->toBe(1)
        ->and($html)->toContain(Lang::get('core::fx.converted_to', ['currency' => 'EUR']));
});
