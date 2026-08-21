<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The resolver scopes government and bank-fee rules to the reader's country.
// That country now lives beside their language, not inside Tax, and this is the
// test that the resolver reads it from its new home.

function countryScopeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function countryScopeAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Betaalrekening',
        'slug' => 'betaalrekening-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL57ASNB012345678'.$user->id,
        'default_currency' => 'EUR',
    ]);
}

function resolveDescription(User $user, Account $account, string $description): ?object
{
    $now = CarbonImmutable::parse('2026-03-01');

    return app(CounterpartyResolver::class)->resolve(new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: $now,
        bookedAt: $now,
        valueDate: $now,
        amountMinor: -12500,
        currency: 'EUR',
        settledAmountMinor: -12500,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: $description,
        counterpartyIban: null,
        counterpartyNormalized: strtolower($description),
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    ), $user);
}

beforeEach(function (): void {
    $this->user = countryScopeUser('country-scope-owner');
    $this->account = countryScopeAccount($this->user);
});

// ZORGPREMIE is a real Belgian government rule and also the ordinary Dutch word
// for a health-insurance premium, which is what made the scoping necessary.
it('applies a Belgian rule to a reader whose country is Belgium', function (): void {
    app(UserCountry::class)->store($this->user->id, 'be');

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->toBe(CounterpartyType::Government->value);
});

it('withholds that Belgian rule from a reader whose country is the Netherlands', function (): void {
    app(UserCountry::class)->store($this->user->id, 'nl');

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->not->toBe(CounterpartyType::Government->value);
});

// Unset stays meaningful: every region loads, which is what a reader who never
// named a country had before the scoping existed.
it('falls back to every region while no country is chosen', function (): void {
    expect(app(UserCountry::class)->current($this->user->id))->toBe('');

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->toBe(CounterpartyType::Government->value);
});

// The column, not the seam: a resolver still reading users.tax_country_code
// would pass every test above by accident of the seam being mocked.
it('reads the country straight off the renamed users column', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['country_code' => 'be']);

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->toBe(CounterpartyType::Government->value);
});
