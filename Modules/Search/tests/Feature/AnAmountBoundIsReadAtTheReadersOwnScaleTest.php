<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// "20" typed into the amount filter is twenty of the reader's own money. Parsed
// at a hard two decimals it became 2 000 minor units, so a yen reader filtering
// "at least 20" lost every charge under ¥2 000 — and the report that opens this
// very list reads the same figure at the yen scale, so the two disagreed.

/**
 * @return array<string, mixed>
 */
function scaleProbeRow(int $accountId, string $currency, int $minor): array
{
    return [
        'account_id' => $accountId,
        'type' => 'expense',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'Scale Probe',
        'counterparty_normalized' => 'scale probe',
        'description' => 'Scale probe charge',
    ];
}

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Jpy->value);
    $this->reader = $fixture['user'];
    $this->readerAccount = $fixture['account'];
});

it('reads a yen bound at the yen scale, where twenty is twenty', function (): void {
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -500));
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -3000));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMin: '20'),
    );

    expect($page->totalCount)->toBe(2);
});

it('reads a yen upper bound at the yen scale too', function (): void {
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -500));
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -3000));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMax: '600'),
    );

    expect($page->totalCount)->toBe(1);
});

// Both bounds at once is the shape that carried the reader's-currency guard
// twice. One predicate or two, the band it selects has to be the same one.
it('reads a yen band from both bounds at the yen scale', function (): void {
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -500));
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -1200));
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -3000));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMin: '600', amountMax: '2000'),
    );

    expect($page->totalCount)->toBe(1);
});

// A bare number in the text box is the same figure in the same money.
it('finds a yen charge typed as a bare number', function (): void {
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Jpy->value, -1200));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '1200', SearchFilters::empty());

    expect($page->totalCount)->toBe(1);
});

// The euro reader is the case that was always green: two decimals is right for
// them, so the fix must not be a swap of which scale is hardcoded.
it('still reads a euro bound at two decimals', function (): void {
    $this->reader->forceFill(['base_currency' => Currency::Eur->value])->save();

    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Eur->value, -500));
    $this->searchTestTransaction($this->reader->id, scaleProbeRow($this->readerAccount->id, Currency::Eur->value, -3000));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMin: '20'),
    );

    expect($page->totalCount)->toBe(1);
});
