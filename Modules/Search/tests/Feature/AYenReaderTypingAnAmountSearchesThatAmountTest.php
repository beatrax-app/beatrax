<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// Both the bare-number branch and the `amount:` token gated the typed figure on
// a hand-written `\d{1,2}` fraction — two decimals, whatever the reader's money
// counts to. A yen reader typing "12.50" cleared that gate, failed the parse
// behind it, and the `?? 0` that caught the null searched for an amount of
// zero. A dinar reader typing "12.500" never reached the branch at all.

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Jpy->value);
    $this->reader = $fixture['user'];
    $this->account = $fixture['account'];
});

/**
 * @return array<string, mixed>
 */
function yenSearchRow(int $accountId, string $currency, int $minor, string $what): array
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
        'description' => $what,
    ];
}

it('does not answer a shape yen has no room for with the rows worth nothing', function (): void {
    $zero = $this->searchTestTransaction($this->reader->id, yenSearchRow($this->account->id, Currency::Jpy->value, 0, 'Correction'));
    $this->searchTestTransaction($this->reader->id, yenSearchRow($this->account->id, Currency::Jpy->value, -1250, 'Nintendo'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '12.50', SearchFilters::empty());

    expect(array_map(static fn ($row): int => $row->id, $page->rows))->not->toContain($zero);
});

it('finds the yen charge from the whole number a yen is typed as', function (): void {
    $charge = $this->searchTestTransaction($this->reader->id, yenSearchRow($this->account->id, Currency::Jpy->value, -1250, 'Nintendo'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), '1250', SearchFilters::empty());

    expect(array_map(static fn ($row): int => $row->id, $page->rows))->toContain($charge);
});

it('reads a three-decimal range token at the three decimals that money has', function (): void {
    $this->reader->forceFill(['base_currency' => 'KWD'])->save();
    $inRange = $this->searchTestTransaction($this->reader->id, yenSearchRow($this->account->id, 'KWD', -12750, 'Souq'));
    $outOfRange = $this->searchTestTransaction($this->reader->id, yenSearchRow($this->account->id, 'KWD', -99000, 'Souq'));

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'amount:12.500-13.000 Souq', SearchFilters::empty());

    $ids = array_map(static fn ($row): int => $row->id, $page->rows);
    expect($ids)->toContain($inRange)->and($ids)->not->toContain($outOfRange);
});
