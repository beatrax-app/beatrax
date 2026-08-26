<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The in/out totals under a search used to be summed with `settled_currency =
// 'EUR'` written into the SQL. Every row a reader on another base currency owns
// was counted as zero, and the strip read €0.00 over a list that plainly was
// not empty. Nothing caught it because every fixture in the suite was in euro,
// so the literal and the correct value were the same string.

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount();
    $this->reader = $fixture['user'];
    $this->readerAccount = $fixture['account'];
});

/**
 * @return array<string, mixed>
 */
function searchTotalsRow(int $accountId, string $currency, int $minor, string $what): array
{
    return [
        'account_id' => $accountId,
        'type' => $minor < 0 ? 'expense' : 'income',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => $what,
        'counterparty_normalized' => strtolower($what),
        'description' => $what.' totals probe',
    ];
}

// The euro reader is the case that was always green. Kept so the fix cannot be
// mistaken for having simply swapped which currency is hardcoded.
it('counts the euro reader the way it always did', function (): void {
    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Eur->value, -4990, 'Probe'));
    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Eur->value, 12000, 'Probe'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'Probe', SearchFilters::empty());

    expect($page->totalOutMinor)->toBe(-4990)
        ->and($page->totalInMinor)->toBe(12000);
});

// The bug, stated as a test: this reader's rows are the GBP ones, and the euro
// row belongs to the same account but not to their base currency.
it('counts the rows in the base currency the reader actually chose', function (): void {
    $this->reader->forceFill(['base_currency' => Currency::Gbp->value])->save();

    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Gbp->value, -1000, 'Probe'));
    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Gbp->value, 2500, 'Probe'));
    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Eur->value, -700, 'Probe'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'Probe', SearchFilters::empty());

    // All three rows are listed and all three counted: the euro row converts at
    // the bundled EUR/GBP rate of 0.83895, so EUR 7.00 joins the total as
    // GBP 5.87 rather than being dropped from a figure the rows contradict.
    expect($page->totalCount)->toBe(3)
        ->and($page->totalOutMinor)->toBe(-1587)
        ->and($page->totalInMinor)->toBe(2500);
});

// The reader's column wins over the app default, and the app default is only
// consulted when they have chosen nothing. These are two different questions,
// and the sweep that introduced BaseCurrency answered them at different sites.
it('falls back to the configured base only when the reader has chosen none', function (): void {
    // Nulled deliberately: User's $attributes seeds base_currency with the euro,
    // so a fresh reader never reaches the fallback. Only a row predating that
    // column does, which is the state this branch exists for.
    $this->reader->forceFill(['base_currency' => null])->save();
    config()->set('currency.base', Currency::Gbp->value);

    expect($this->reader->fresh()->base_currency)->toBeNull()
        ->and(app(BaseCurrency::class)->code())->toBe(Currency::Gbp->value);

    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Gbp->value, -333, 'Probe'));
    $this->searchTestTransaction($this->reader->id, searchTotalsRow($this->readerAccount->id, Currency::Eur->value, -999, 'Probe'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'Probe', SearchFilters::empty());

    // GBP 3.33 plus the euro row converted at 0.83895 — EUR 9.99 is GBP 8.38.
    expect($page->totalOutMinor)->toBe(-1171);
});

// Nothing in the tree may resolve the base currency by reading the config
// directly: config('currency.base') has no env wiring, so a site reading it
// instead of the user's column is inert and silently keeps the euro.
it('resolves the configured base through the service rather than the raw key', function (): void {
    config()->set('currency.base', '');

    expect(app(BaseCurrency::class)->code())->toBe(Currency::Eur->value);
});
