<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// The label followed the cash account's currency and the box under it did not:
// a yen field spelled its placeholder "0.00" and asked the phone for a decimal
// key, then refused the "1500.00" that shape invites.

function cashShapeUser(string $currency): User
{
    $user = User::query()->create([
        'username' => 'cash-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => $currency,
    ]);

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Cash',
        'slug' => 'cash-shape-'.bin2hex(random_bytes(4)),
        'kind' => 'cash',
        'iban' => 'CASH'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
        'default_currency' => $currency,
    ]);

    return $user;
}

it('writes the zero of the currency at the currency scale', function (): void {
    expect(MoneyInput::formatAbsMinor(0, 'JPY'))->toBe('0')
        ->and(MoneyInput::formatAbsMinor(0, 'EUR'))->toBe('0.00')
        ->and(MoneyInput::decimalPlaces('JPY'))->toBe(0)
        ->and(MoneyInput::decimalPlaces('EUR'))->toBe(2);
});

it('invites a whole number on a yen cash account', function (): void {
    $html = Livewire::actingAs(cashShapeUser('JPY'))->test(CashBookPage::class)->html();

    expect($html)->toContain('placeholder="0"')
        ->and($html)->not->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('still invites two decimals on a euro cash account', function (): void {
    $html = Livewire::actingAs(cashShapeUser('EUR'))->test(CashBookPage::class)->html();

    expect($html)->toContain('placeholder="0.00"')
        ->and($html)->toContain('inputmode="decimal"');
});

it('spells the euro zero the way the reader writes it', function (): void {
    app()->setLocale('nl');

    $html = Livewire::actingAs(cashShapeUser('EUR'))->test(CashBookPage::class)->html();

    expect($html)->toContain('placeholder="0,00"');
});
