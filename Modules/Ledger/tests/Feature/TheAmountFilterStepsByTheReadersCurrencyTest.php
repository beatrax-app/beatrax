<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// The bound is read at the reader's reporting currency, and both boxes stepped
// by a hundredth whatever that currency was: a yen reader was offered a
// fraction the same screen's parser then refused.

function amountFilterUser(string $currency): User
{
    return User::create([
        'username' => 'amount-filter-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $currency,
    ]);
}

it('steps a yen amount filter by whole yen', function (): void {
    $html = Livewire::actingAs(amountFilterUser(Currency::Jpy->value))
        ->test(TransactionsList::class)
        ->html();

    expect($html)->toContain('step="1"')
        ->and($html)->not->toContain('step="0.01"');
});

it('still steps a euro amount filter by cents', function (): void {
    $html = Livewire::actingAs(amountFilterUser(Currency::Eur->value))
        ->test(TransactionsList::class)
        ->html();

    expect($html)->toContain('step="0.01"');
});
