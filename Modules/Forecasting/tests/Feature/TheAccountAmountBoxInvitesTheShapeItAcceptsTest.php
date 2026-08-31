<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\AccountBufferEditor;
use Modules\Forecasting\Public\Http\Livewire\OpeningBalanceEditor;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// Both editors are locked to the account's own denomination and parse at it,
// and both asked the phone for a decimal key regardless. The opening-balance
// example went further: one hard-written "1.250,00" served all 26 locales, so
// an English reader was shown a Dutch figure and a yen account was shown one
// its own parser refuses.

/**
 * @return array{0: User, 1: Account}
 */
function accountShapeFixture(string $currency): array
{
    $user = User::create([
        'username' => 'account-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Tokyo',
        'slug' => 'account-shape-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'JP'.bin2hex(random_bytes(6)),
        'default_currency' => $currency,
    ]);

    return [$user, $account];
}

it('asks the phone for a whole-number keyboard on a yen buffer', function (): void {
    [$user, $account] = accountShapeFixture(Currency::Jpy->value);

    $html = Livewire::actingAs($user)->test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => null,
        'currency' => Currency::Jpy->value,
        'accountName' => 'Tokyo',
    ])->html();

    expect($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('still asks for a decimal keyboard on a euro buffer', function (): void {
    [$user, $account] = accountShapeFixture(Currency::Eur->value);

    $html = Livewire::actingAs($user)->test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => null,
        'currency' => Currency::Eur->value,
        'accountName' => 'Amsterdam',
    ])->html();

    expect($html)->toContain('inputmode="decimal"');
});

function openingBalanceHtml(User $user, Account $account, string $currency): string
{
    return Livewire::actingAs($user)->test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => $currency,
        'accountName' => 'Tokyo',
        'accountKind' => AccountKind::Bank->value,
    ])->html();
}

it('offers a yen opening balance an example a yen account can hold', function (): void {
    [$user, $account] = accountShapeFixture(Currency::Jpy->value);

    $html = openingBalanceHtml($user, $account, Currency::Jpy->value);

    expect($html)->toContain('125,000')
        ->and($html)->not->toContain('1.250,00')
        ->and($html)->toContain('inputmode="numeric"')
        ->and($html)->not->toContain('inputmode="decimal"');
});

it('writes the euro example the way the reader writes a number', function (): void {
    [$user, $account] = accountShapeFixture(Currency::Eur->value);

    app()->setLocale('en');
    expect(openingBalanceHtml($user, $account, Currency::Eur->value))
        ->toContain('1,250.00')
        ->not->toContain('1.250,00');

    app()->setLocale('nl');
    expect(openingBalanceHtml($user, $account, Currency::Eur->value))
        ->toContain('1.250,00')
        ->not->toContain('1,250.00');
});
