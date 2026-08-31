<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// ReportAggregator::amountToMinor() reads the bound at the READER's currency
// scale; the chip naming the same bound parsed it at the repo-wide hundredth.
// The transactions list had the identical split and was fixed first.

it('names the bound at the reader currency scale, not at a hundredth', function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'rac-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('filterAmountMin', '5000')
        ->html();

    expect($html)->toContain(Money::ofMinor(5_000, Currency::Jpy->value)->format())
        ->and($html)->not->toContain(Money::ofMinor(500_000, Currency::Jpy->value)->format());
});
