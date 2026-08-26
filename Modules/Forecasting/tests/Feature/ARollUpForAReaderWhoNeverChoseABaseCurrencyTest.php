<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// users.base_currency was added nullable with no backfill and no DB default,
// so every user who existed before that migration carries NULL. A roll-up that
// read the column raw handed NULL where a currency code was required and died
// on the reader whose install is oldest.

function neverChoseReader(): User
{
    $user = User::create([
        'username' => 'nochoice-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // Straight to the table: Eloquent's $attributes default would put EUR back.
    DB::table('users')->where('id', $user->id)->update(['base_currency' => null]);

    return User::query()->findOrFail($user->id);
}

function neverChoseAccount(User $user, string $currency, int $openingMinor): void
{
    $hex = bin2hex(random_bytes(4));

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'Bank '.$currency,
        'slug' => 'nochoice-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00NOCH'.strtoupper($hex),
        'default_currency' => $currency,
        'opening_balance_minor' => $openingMinor,
        'opening_balance_as_of_date' => '2026-06-01',
    ]);
}

it('rolls net worth up in the install default for a reader who never chose', function (): void {
    $user = neverChoseReader();
    neverChoseAccount($user, Currency::Eur->value, 100_000);

    expect($user->base_currency)->toBeNull();

    $netWorth = app(NetWorthQuery::class)->forUser($user);

    expect($netWorth->currency)->toBe((string) config('currency.base'))
        ->and($netWorth->totalMinor)->toBe(100_000);
});

it('keeps rolling net worth up in the currency a reader did choose', function (): void {
    $user = neverChoseReader();
    $user->forceFill(['base_currency' => Currency::Usd->value])->save();
    neverChoseAccount($user, Currency::Usd->value, 100_000);

    $netWorth = app(NetWorthQuery::class)->forUser($user->fresh());

    expect($netWorth->currency)->toBe(Currency::Usd->value)
        ->and($netWorth->totalMinor)->toBe(100_000);
});
