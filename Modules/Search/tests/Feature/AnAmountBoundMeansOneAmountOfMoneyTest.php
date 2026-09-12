<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Search\Public\Services\SearchQuery;

// A bound the reader typed is one amount of money, and the report row that
// opens this very list counts it in every currency the ledger settles in. The
// list narrowed to the reader's own currency instead, so a row reading EUR
// 57.00 over one EUR 30.00 and one USD 30.00 charge opened a list showing EUR
// 30.00 — the figure and the rows beneath it could not both be right, and
// nothing on the page said which one to believe.
//
// Narrowing was the first answer to the half of this that is still true:
// ABS(settled_amount_minor) of a JPY row and a bound written in EUR cents are
// bare integers in two denominations, and comparing them is not a comparison.
// Restating the bound per currency keeps that closed and lets the rows through.

/**
 * @return array<string, mixed>
 */
function moneyBoundRow(int $accountId, string $currency, int $minor, string $name): array
{
    return [
        'account_id' => $accountId,
        'type' => 'expense',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => $name,
        'counterparty_normalized' => strtolower($name),
        'description' => $name.' charge',
    ];
}

function moneyBoundRate(string $quote, string $rate): void
{
    app(DatabaseManager::class)->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => $quote,
        'rate_date' => '2026-01-02',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);
}

/**
 * @param  list<SearchRowDto>  $rows
 * @return list<int>
 */
function moneyBoundIds(array $rows): array
{
    $ids = array_map(static fn (SearchRowDto $row): int => $row->id, $rows);
    sort($ids);

    return $ids;
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function moneyBoundExpected(array $ids): array
{
    sort($ids);

    return $ids;
}

// The shipped snapshot is cleared so every figure below is this file's own
// arithmetic rather than whatever the next ECB refresh writes into
// rates-snapshot.json.
beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();

    $fixture = $this->seedFixtureUserAndAccount(Currency::Eur->value);
    $this->reader = $fixture['user'];
    $this->account = $fixture['account'];
});

it('tests a yen charge against the yen a euro lower bound restates to', function (): void {
    moneyBoundRate(Currency::Jpy->value, '160.0');

    // EUR 100.00 is Y16,000. Y13,840 is about EUR 86.50 and stays out — as raw
    // minor units it would clear a bound of 10 000 — while Y30,000 is about
    // EUR 187.50 and is precisely the charge the reader asked to see.
    $under = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Jpy->value, -13_840, 'JR East'));
    $over = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Jpy->value, -30_000, 'Shinkansen'));
    $euro = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Eur->value, -12_500, 'Vesteda'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '', new SearchFilters(amountMin: '100'));

    $ids = moneyBoundIds($page->rows);

    expect($page->totalCount)->toBe(2)
        ->and($ids)->toBe(moneyBoundExpected([$over, $euro]))
        ->and($ids)->not->toContain($under);
});

it('tests a yen charge against the yen a euro upper bound restates to', function (): void {
    moneyBoundRate(Currency::Jpy->value, '160.0');

    // EUR 10.00 is Y1,600. Y580 is about EUR 3.63 and belongs under "at most
    // EUR 10"; Y128,000 is about EUR 800 and does not.
    $under = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Jpy->value, -580, 'Seven Eleven'));
    $over = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Jpy->value, -128_000, 'Yodobashi'));
    $euro = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Eur->value, -450, 'KPN'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '', new SearchFilters(amountMax: '10'));

    $ids = moneyBoundIds($page->rows);

    expect($page->totalCount)->toBe(2)
        ->and($ids)->toBe(moneyBoundExpected([$under, $euro]))
        ->and($ids)->not->toContain($over);
});

it('leaves out and names a currency whose bound no rate can state', function (): void {
    // The same answer the report gives for such a currency: excluded from the
    // figures and named, never admitted at a silent one-to-one.
    $peso = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, 'ARS', -57_500, 'Mercado'));
    $euro = $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Eur->value, -12_500, 'Vesteda'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '', new SearchFilters(amountMin: '100'));

    $ids = moneyBoundIds($page->rows);

    expect($page->totalCount)->toBe(1)
        ->and($ids)->toBe([$euro])
        ->and($ids)->not->toContain($peso)
        ->and($page->unconvertedCurrencies)->toBe(['ARS']);
});

// The bare-number branch is a different question — "show me the transaction
// that IS this figure" — and it deliberately still reads the reader's own
// money, so a yen charge is never offered as a match for a euro amount.
it('leaves a yen charge out of a bare number typed in the box', function (): void {
    moneyBoundRate(Currency::Jpy->value, '160.0');

    $this->searchTestTransaction($this->reader->id, moneyBoundRow($this->account->id, Currency::Jpy->value, -5_000, 'JR East'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '50', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
});
