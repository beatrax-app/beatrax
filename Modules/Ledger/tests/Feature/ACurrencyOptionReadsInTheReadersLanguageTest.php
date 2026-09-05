<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use Modules\Ledger\Public\Support\CurrencyDisplayName;

uses(RefreshDatabase::class);

// `currencies.name` is seeded in English and both pickers rendered that column
// straight as their option labels, so a Dutch reader adding an account was
// offered "Pound Sterling". The code is the row's own primary key and the one
// part of it no translation touches.

function curOptUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

afterEach(function (): void {
    app()->setLocale('en');
});

it('offers the account picker its currencies in the reader language', function (): void {
    $user = curOptUser('cur-opt-editor');
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'cur-opt-asn',
        'kind' => 'bank',
        'iban' => 'CUROPT-ASN',
        'default_currency' => 'EUR',
        'starting_balance_minor' => 0,
    ]);

    app()->setLocale('nl');

    /** @var array<string, string> $options */
    $options = Livewire::actingAs($user)
        ->test(AccountCurrencyEditor::class, [
            'accountId' => $account->id,
            'accountName' => $account->name,
            'currency' => $account->default_currency,
        ])
        ->viewData('currencyOptions');

    expect($options['GBP'])->toBe('Britse pond')
        ->and($options['USD'])->toBe('Amerikaanse dollar')
        ->and($options['JPY'])->toBe('Japanse yen');
});

it('offers the seeded English wording to an English reader', function (): void {
    app()->setLocale('en');

    expect(CurrencyDisplayName::forCode('GBP', 'Pound Sterling'))->toBe('Pound Sterling');
});

// A code the table carries that no locale has a line for still reads as words:
// the seeded name is the fallback, never the key.
it('falls back to the stored name for a code no locale names', function (): void {
    DB::table('currencies')->insert(['code' => 'CHF', 'name' => 'Swiss Franc', 'minor_unit' => 2]);

    app()->setLocale('nl');

    expect(CurrencyDisplayName::forCode('CHF', 'Swiss Franc'))->toBe('Swiss Franc');
});
