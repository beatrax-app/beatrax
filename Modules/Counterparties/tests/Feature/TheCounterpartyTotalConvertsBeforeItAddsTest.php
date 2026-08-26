<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;

// A Revolut import carries a currency per row, so one merchant is charged in
// euro one month and dollars the next. Both counterparty roll-ups summed
// amount_minor with no GROUP BY on a currency at all, and printed the result
// under the reader's sign. Measured with a EUR100.00 and a USD100.00 charge to
// one merchant at a dollar priced 2.0 to the euro: EUR200.00 over twelve
// months and EUR16.67 a month, against a true EUR150.00 and EUR12.50.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot ships a rate for every major, and one case here
    // turns on a pair having none at all, so this suite builds its own world.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'cp-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function cpRate(DatabaseManager $db, string $quote, string $rate): void
{
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => $quote,
        'rate_date' => '2026-08-23',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-08-23 00:00:00',
        'updated_at' => '2026-08-23 00:00:00',
    ]);
}

function cpMerchant(DatabaseManager $db, int $userId, string $slug, string $type = 'merchant'): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'slug' => $slug, 'display_name' => ucfirst($slug),
        'merchant_name' => ucfirst($slug), 'type' => $type,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function cpCharge(
    DatabaseManager $db,
    int $userId,
    int $cpId,
    int $minor,
    string $currency,
    string $date = '2026-08-01',
    ?int $categoryId = null,
): void {
    $hex = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Revolut '.$hex, 'slug' => 'rev-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00REV'.strtoupper($hex), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/rev-'.$hex.'.csv',
        'sha256' => hash('sha256', 'rev-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'committed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => $cpId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'rev-fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => $date, 'booked_at' => $date.' 12:00:00', 'value_date' => $date,
        'amount_minor' => $minor, 'currency' => $currency,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'vendor', 'counterparty_name' => 'Vendor',
        'normalization_version' => 1, 'description' => 'fixture',
        'type' => $minor < 0 ? 'expense' : 'income',
        'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('converts the dollar charge instead of adding its cents to the euro one', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $row = app(CounterpartyIndexQuery::class)->forUser($this->user)->first();

    expect($row->total12mMinor)->toBe(-15_000)
        ->and($row->currency)->toBe(Currency::Eur->value)
        ->and($row->total12mFormatted)->toBe('€150.00')
        ->and($row->avgPerMonthFormatted)->toBe('€12.50')
        ->and($row->isPartial())->toBeFalse();
});

it('converts each month of the sparkline, not only the twelve-month total', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value, '2026-08-01');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value, '2026-08-02');
    cpRate($this->db, Currency::Usd->value, '2.0');

    $row = app(CounterpartyIndexQuery::class)->forUser($this->user)->first();

    expect($row->sparkline[11])->toBe(-15_000);
});

it('converts the twelve-month total on the profile too', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $profile = app(CounterpartyProfileQuery::class)->bySlug($this->user, 'acme');

    expect($profile->total12mMinor)->toBe(-15_000)
        ->and($profile->currency)->toBe(Currency::Eur->value)
        ->and($profile->transactionCount)->toBe(2)
        ->and($profile->isPartial())->toBeFalse();
});

// Never a silent one to one: a charge whose pair the rate table cannot reach
// is left out of the figure and named.
it('leaves out a charge it has no rate for and names its currency', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value);
    cpCharge($this->db, $this->user->id, $cpId, -500_000, Currency::Jpy->value);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $row = app(CounterpartyIndexQuery::class)->forUser($this->user)->first();
    $profile = app(CounterpartyProfileQuery::class)->bySlug($this->user, 'acme');

    expect($row->total12mMinor)->toBe(-15_000)
        ->and($row->unconverted)->toBe([Currency::Jpy->value])
        ->and($row->isPartial())->toBeTrue()
        ->and($profile->unconvertedCurrencies)->toBe([Currency::Jpy->value]);
});

// The index ranks by what each counterparty cost the reader, so the race is
// run in the reader's currency: on raw minor units a USD180.00 merchant
// outranked a EUR100.00 one while being the cheaper of the two.
it('ranks the index in the reader’s currency', function (): void {
    $euro = cpMerchant($this->db, $this->user->id, 'euro-shop');
    $dollar = cpMerchant($this->db, $this->user->id, 'dollar-shop');
    cpCharge($this->db, $this->user->id, $euro, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $dollar, -18_000, Currency::Usd->value);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $names = app(CounterpartyIndexQuery::class)->forUser($this->user)
        ->map(static fn ($row): string => $row->slug)->all();

    expect($names)->toBe(['euro-shop', 'dollar-shop']);
});

it('converts a category breakdown row rather than adding across currencies', function (): void {
    $categoryId = $this->db->connection()->table('categories')->insertGetId([
        'user_id' => null, 'name' => 'Groceries', 'slug' => 'cp-groceries', 'kind' => 'expense',
        'display_order' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value, '2026-08-01', $categoryId);
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value, '2026-08-02', $categoryId);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $cp = Counterparty::query()->where('slug', 'acme')->firstOrFail();
    $breakdown = app(CounterpartyProfileQuery::class)->categoryBreakdown($cp);

    expect($breakdown)->toHaveCount(1)
        ->and((int) $breakdown->first()->total_minor)->toBe(-15_000);
});

it('converts a government tax-year row rather than adding across currencies', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'belastingdienst', 'government');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value, '2026-03-01');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value, '2026-04-01');
    cpRate($this->db, Currency::Usd->value, '2.0');

    $cp = Counterparty::query()->where('slug', 'belastingdienst')->firstOrFail();
    $years = app(CounterpartyProfileQuery::class)->taxYearBreakdown($cp);

    expect($years)->toHaveCount(1)
        ->and((int) $years->first()->total_minor)->toBe(-15_000);
});

it('prints the converted total on /counterparties rather than the added cents', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Usd->value);
    cpRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(CounterpartyIndex::class)->html();

    expect($html)->toContain('€150.00')
        ->and($html)->not->toContain('€200.00');
});

it('says on /counterparties which currency a total could not reach', function (): void {
    $cpId = cpMerchant($this->db, $this->user->id, 'acme');
    cpCharge($this->db, $this->user->id, $cpId, -10_000, Currency::Eur->value);
    cpCharge($this->db, $this->user->id, $cpId, -500_000, Currency::Jpy->value);

    $html = Livewire::test(CounterpartyIndex::class)->html();

    expect($html)->toContain(Currency::Jpy->value.' not converted');
});
