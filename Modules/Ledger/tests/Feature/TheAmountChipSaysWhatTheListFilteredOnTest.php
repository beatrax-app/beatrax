<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// SearchQuery parses the bound at the READER's currency scale; the chip that
// names the same bound parsed it at the repo-wide hundredth. A yen reader
// asking for rows over Y5,000 got them, under a chip claiming the list was
// bounded at Y500,000.

it('names the bound the list was actually filtered on, at the reader currency scale', function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Jpy->value);
    $this->actingAs($fixture['user']);

    $run = $this->makeImportRun($fixture['user']);
    $this->makeTransaction($fixture['user'], $fixture['account'], $run, [
        'amount_minor' => -6_000,
        'currency' => Currency::Jpy->value,
        'settled_amount_minor' => -6_000,
        'settled_currency' => Currency::Jpy->value,
        'counterparty_name' => 'Yen Kiosk',
        'counterparty_normalized' => 'yen kiosk',
    ]);

    $component = Livewire::test(TransactionsList::class)
        ->set('filterAmountMin', '5000');

    $html = $component->html();

    expect($html)->toContain('Yen Kiosk')
        ->and($html)->toContain(Money::ofMinor(5_000, Currency::Jpy->value)->format())
        ->and($html)->not->toContain(Money::ofMinor(500_000, Currency::Jpy->value)->format());
});
