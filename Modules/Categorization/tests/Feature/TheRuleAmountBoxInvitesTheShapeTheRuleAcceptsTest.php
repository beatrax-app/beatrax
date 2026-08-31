<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;

// A rule's amount condition IS currency-scoped: MapsRuleRows reads and writes
// the threshold at the reader's base, and RuleEngine only tests rows that
// settled in it. The box that collects the figure kept the two-decimal
// placeholder and the decimal keyboard anyway, so a yen reader was invited to
// type a fraction their own rule would refuse.

beforeEach(function (): void {
    $this->reader = User::create([
        'username' => 'rule-amount-box',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($this->reader);
});

it('offers a yen rule the keyboard and the placeholder a yen actually has', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open')
        ->set('conditions.0.field', 'amount')
        ->assertSee('inputmode="numeric"', escape: false)
        ->assertDontSee('inputmode="decimal"', escape: false)
        ->assertSee('placeholder="0"', escape: false);
});

it('still offers the euro reader the decimal keyboard their own rule accepts', function (): void {
    $this->reader->forceFill(['base_currency' => Currency::Eur->value])->save();

    Livewire::test(RuleFormModal::class)
        ->call('open')
        ->set('conditions.0.field', 'amount')
        ->assertSee('inputmode="decimal"', escape: false)
        ->assertSee('placeholder="0.00"', escape: false);
});
