<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The `amount:` token gated the figure on a regex of its own instead of on
// MoneyInput, and the regex knew nothing about the group marks MoneyInput
// accepts -- so a figure the app itself writes for a Dutch reader, 1.234,56,
// matched only as far as "1.23" and the token then filtered at a thousandth of
// what was typed. A fraction the currency has no room for was truncated the
// same way rather than refused.

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Eur->value);
    $this->reader = $fixture['user'];
    $this->account = $fixture['account'];
});

it('reads a grouped figure as the amount it is written as', function (): void {
    // The spelling the app itself writes this figure in for a Dutch reader,
    // which is the whole reason it has to parse back to the same amount.
    app()->setLocale('nl');
    expect(MoneyInput::formatMinor(123456, Currency::Eur->value))->toBe('1.234,56');

    $grouped = $this->searchTestTransaction($this->reader->id, [
        'account_id' => $this->account->id,
        'amount_minor' => -123456,
        'settled_amount_minor' => -123456,
        'counterparty_name' => 'Bakkerij Vermeer',
        'counterparty_normalized' => 'bakkerij vermeer',
        'description' => 'Bakkerij Vermeer',
    ]);
    $truncated = $this->searchTestTransaction($this->reader->id, [
        'account_id' => $this->account->id,
        'amount_minor' => -123,
        'settled_amount_minor' => -123,
        'counterparty_name' => 'Bakkerij Vermeer',
        'counterparty_normalized' => 'bakkerij vermeer',
        'description' => 'Bakkerij Vermeer',
    ]);

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'amount:1.234,56 Bakkerij', SearchFilters::empty());

    $ids = array_map(static fn ($row): int => $row->id, $page->rows);
    expect($ids)->toContain($grouped)->and($ids)->not->toContain($truncated);
});

it('refuses a fraction the euro has no room for instead of cutting it short', function (): void {
    expect(MoneyInput::tryToMinor('12.500', Currency::Eur->value))->toBeNull();

    $cutShort = $this->searchTestTransaction($this->reader->id, [
        'account_id' => $this->account->id,
        'amount_minor' => -1250,
        'settled_amount_minor' => -1250,
        'counterparty_name' => 'Kiosk Centraal',
        'counterparty_normalized' => 'kiosk centraal',
        'description' => 'Kiosk Centraal',
    ]);

    $page = app(SearchQuery::class)->search($this->reader->fresh(), 'amount:12.500 Kiosk', SearchFilters::empty());

    expect(array_map(static fn ($row): int => $row->id, $page->rows))->not->toContain($cutShort);
});
