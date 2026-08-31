<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

/**
 * @return array<string, mixed>
 */
function boundProbeRow(int $accountId, string $currency, int $minor, string $name): array
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

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Eur->value);
    $this->reader = $fixture['user'];
    $this->account = $fixture['account'];
});

it('leaves a yen charge out of a euro lower bound', function (): void {
    // ¥13,840 is about €87. As raw minor units it clears a €100.00 bound.
    $this->searchTestTransaction($this->reader->id, boundProbeRow($this->account->id, Currency::Jpy->value, -13840, 'JR East'));
    $this->searchTestTransaction($this->reader->id, boundProbeRow($this->account->id, Currency::Eur->value, -12500, 'Vesteda'));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMin: '100'),
    );

    expect($page->totalCount)->toBe(1);
});

it('leaves a yen charge out of a euro upper bound', function (): void {
    // 580 minor yen against a ceiling of 1000 euro cents is not a comparison,
    // whichever way it happens to come out: the same rule that keeps ¥128,000
    // out of "at most €900" keeps this one out of "at most €10".
    $this->searchTestTransaction($this->reader->id, boundProbeRow($this->account->id, Currency::Jpy->value, -580, 'Seven Eleven'));
    $this->searchTestTransaction($this->reader->id, boundProbeRow($this->account->id, Currency::Eur->value, -450, 'KPN'));

    $page = app(SearchQuery::class)->search(
        $this->reader->fresh(),
        '',
        new SearchFilters(amountMax: '10'),
    );

    expect($page->totalCount)->toBe(1);
});

it('leaves a yen charge out of a bare number typed in the box', function (): void {
    $this->searchTestTransaction($this->reader->id, boundProbeRow($this->account->id, Currency::Jpy->value, -5000, 'JR East'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '50', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
});
