<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// The applied-filter chip formats the amount bounds through BaseCurrency, one
// of about a hundred figures across the app that read the install default
// instead of the reader's own setting. The fixture is seeded in the reader's
// currency on purpose: seeded in euro it agrees with the wrong answer.

it('labels the amount bounds in the currency the reader chose', function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Usd->value);
    $this->actingAs($fixture['user']);

    $html = Livewire::test(TransactionsList::class)
        ->set('filterAmountMin', '10,00')
        ->set('filterAmountMax', '250,00')
        ->html();

    expect($html)->toContain(Money::ofMinor(1000, Currency::Usd->value)->format())
        ->and($html)->toContain(Money::ofMinor(25000, Currency::Usd->value)->format())
        ->and($html)->not->toContain(Money::ofMinor(1000, Currency::Eur->value)->format());
});

it('keeps labelling the bounds in euro for the reader who chose euro', function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Eur->value);
    $this->actingAs($fixture['user']);

    $html = Livewire::test(TransactionsList::class)
        ->set('filterAmountMin', '10,00')
        ->html();

    expect($html)->toContain(Money::ofMinor(1000, Currency::Eur->value)->format());
});
