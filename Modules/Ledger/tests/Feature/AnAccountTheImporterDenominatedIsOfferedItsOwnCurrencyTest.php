<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Internal\Actions\SetAccountCurrency;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;

uses(RefreshDatabase::class);

// An importer stamps the statement's own denomination on the account it mints,
// and a data migration relabels an account the importer had denominated wrongly
// from the currency its rows settled in. Both write codes the `currencies` table
// never held, and the editor drew its options from that table alone: with no
// option matching, nothing was selected, the select showed whatever came first,
// and saving that relabelled a correct account to a currency it was never in.

function importedCurrencyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function accountDenominatedIn(User $user, string $currency, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Imported '.$currency,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => strtoupper($slug),
        'default_currency' => $currency,
        'starting_balance_minor' => 0,
    ]);
}

/** @return array<string, string> */
function editorOptionsFor(User $user, Account $account): array
{
    /** @var array<string, string> $options */
    $options = Livewire::actingAs($user)
        ->test(AccountCurrencyEditor::class, [
            'accountId' => $account->id,
            'accountName' => $account->name,
            'currency' => $account->default_currency,
        ])
        ->viewData('currencyOptions');

    return $options;
}

afterEach(function (): void {
    app()->setLocale(Locale::DEFAULT);
});

it('offers a currency the bundled snapshot can price', function (): void {
    $user = importedCurrencyUser('imported-sek');
    $account = accountDenominatedIn($user, 'SEK', 'imported-sek-account');

    expect(editorOptionsFor($user, $account))->toHaveKey('SEK');
});

it('names that currency in the reader language rather than in English', function (): void {
    $user = importedCurrencyUser('imported-sek-nl');
    $account = accountDenominatedIn($user, 'SEK', 'imported-sek-nl-account');

    app()->setLocale(Locale::Nl->value);
    $dutch = editorOptionsFor($user, $account);

    app()->setLocale(Locale::DEFAULT);
    $english = editorOptionsFor($user, $account);

    expect($dutch['SEK'])->toBe('Zweedse kroon')
        ->and($dutch['SEK'])->not->toBe($english['SEK']);
});

it('takes that currency from the editor without refusing it', function (): void {
    $user = importedCurrencyUser('imported-sek-save');
    $account = accountDenominatedIn($user, 'EUR', 'imported-sek-save-account');

    Livewire::actingAs($user)
        ->test(AccountCurrencyEditor::class, [
            'accountId' => $account->id,
            'accountName' => $account->name,
            'currency' => $account->default_currency,
        ])
        ->set('currency', 'SEK')
        ->call('save')
        ->assertSet('errorMessage', null)
        ->assertSet('saved', true);

    expect($account->fresh()->default_currency)->toBe('SEK');
});

// The snapshot cannot price every currency a statement can arrive in, and an
// account already holding one is the evidence that it did. Its own code is
// offered beside the priceable set so the select can show the truth, and the
// Action accepts it so a reader who relabelled by accident can put it back.
it('offers an account the currency it already holds even where no rate can price it', function (): void {
    $user = importedCurrencyUser('imported-bhd');
    $account = accountDenominatedIn($user, 'BHD', 'imported-bhd-account');

    expect(editorOptionsFor($user, $account))->toHaveKey('BHD');
});

it('accepts the currency the account already holds', function (): void {
    $user = importedCurrencyUser('imported-bhd-keep');
    $account = accountDenominatedIn($user, 'BHD', 'imported-bhd-keep-account');

    $action = app(SetAccountCurrency::class);

    expect(fn (): mixed => $action($account->id, $user, 'BHD'))->not->toThrow(InvalidArgumentException::class);
});

// The <select> narrows the choice and nothing enforces it, so the Action still
// has to refuse a code that reaches it any other way: an unpriceable currency
// the account is not already in would drop out of every converted roll-up
// instead of failing where it was chosen.
it('still refuses a currency that is neither priceable nor the account own', function (): void {
    $user = importedCurrencyUser('imported-refuse');
    $account = accountDenominatedIn($user, 'EUR', 'imported-refuse-account');

    $action = app(SetAccountCurrency::class);

    expect(fn (): mixed => $action($account->id, $user, 'BHD'))->toThrow(InvalidArgumentException::class);
});

it('still refuses three bytes that are no currency at all', function (): void {
    $user = importedCurrencyUser('imported-tamper');
    $account = accountDenominatedIn($user, 'EUR', 'imported-tamper-account');

    $action = app(SetAccountCurrency::class);

    expect(fn (): mixed => $action($account->id, $user, 'ZZZ'))->toThrow(InvalidArgumentException::class);
});
