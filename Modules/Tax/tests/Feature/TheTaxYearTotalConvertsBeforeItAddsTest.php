<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Tax\Internal\Http\Livewire\TaxPage;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Public\Services\TaxYearQuery;

// A Revolut import carries a currency per row, so a tax year holds a euro
// receipt beside a dollar one. Both totals took ABS(settled_amount_minor)
// straight across settled_currency and /tax printed the sum under the reader's
// sign. Measured with a EUR100.00 and a USD100.00 deduction at a dollar priced
// 2.0 to the euro: EUR200.00 deductible, against a true EUR150.00.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot ships a rate for every major, and one case here
    // turns on a pair having none at all, so this suite builds its own world.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'tax-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function tmcRate(DatabaseManager $db, string $quote, string $rate): void
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

function tmcTaggedRow(DatabaseManager $db, int $userId, int $minor, string $currency, ?int $categoryId = null, string $type = 'expense'): int
{
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
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rev-fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => '2026-03-01', 'booked_at' => '2026-03-01 00:00:00', 'value_date' => '2026-03-01',
        'amount_minor' => $minor, 'currency' => $currency,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'vendor', 'counterparty_name' => 'Vendor',
        'normalization_version' => 1, 'description' => 'fixture', 'type' => $type,
        'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId, 'transaction_id' => $txId, 'deduction_category_id' => $categoryId,
        'tax_year_override' => null, 'note' => null,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $txId;
}

function tmcCategory(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId, 'name' => 'Office', 'short_name' => 'Office',
        'sort_order' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('converts the dollar receipt instead of adding its cents to the euro one', function (): void {
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Usd->value);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    expect($data->deductionsTotalMinor)->toBe(15_000)
        ->and($data->currency)->toBe(Currency::Eur->value)
        ->and($data->unconvertedCurrencies)->toBe([])
        ->and($data->itemCount)->toBe(2);
});

it('converts a category subtotal too, not only the year total', function (): void {
    $categoryId = tmcCategory($this->db, $this->user->id);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value, $categoryId);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Usd->value, $categoryId);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    expect($data->categories)->toHaveCount(1)
        ->and($data->categories[0]['subtotalMinor'])->toBe(15_000);
});

it('converts the income total the same way', function (): void {
    tmcTaggedRow($this->db, $this->user->id, 10_000, Currency::Eur->value, null, TransactionType::Income->value);
    tmcTaggedRow($this->db, $this->user->id, 10_000, Currency::Usd->value, null, TransactionType::Income->value);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    expect($data->incomeTotalMinor)->toBe(15_000)
        ->and($data->deductionsTotalMinor)->toBe(0);
});

// Never a silent one to one: a receipt whose pair the rate table cannot reach
// is left out of the figure and named.
it('leaves out a receipt it has no rate for and names its currency', function (): void {
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Usd->value);
    tmcTaggedRow($this->db, $this->user->id, -500_000, Currency::Jpy->value);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    expect($data->deductionsTotalMinor)->toBe(15_000)
        ->and($data->unconvertedCurrencies)->toBe([Currency::Jpy->value])
        ->and($data->itemCount)->toBe(3);
});

it('converts the dashboard tax card total as well', function (): void {
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Usd->value);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $summary = app(TaxTagQuery::class)->summaryForUser($this->user->id, 2026);

    expect($summary->totalMinor)->toBe(15_000)
        ->and($summary->currency)->toBe(Currency::Eur->value)
        ->and($summary->count)->toBe(2);
});

it('prints the converted deductions total on /tax rather than the added cents', function (): void {
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value);
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Usd->value);
    tmcRate($this->db, Currency::Usd->value, '2.0');

    $html = Livewire::test(TaxPage::class, ['year' => 2026])->html();

    expect($html)->toContain('€150.00')
        ->and($html)->not->toContain('€200.00');
});

it('says on /tax which currency the year total could not reach', function (): void {
    tmcTaggedRow($this->db, $this->user->id, -10_000, Currency::Eur->value);
    tmcTaggedRow($this->db, $this->user->id, -500_000, Currency::Jpy->value);

    $html = Livewire::test(TaxPage::class, ['year' => 2026])->html();

    expect($html)->toContain(Currency::Jpy->value.' not converted');
});
