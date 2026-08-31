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

// No country is the state a fresh install is in, not an edge case, and it is
// the one where naming a national institution is a guess: only Belgium's file
// defines ZORGPREMIE, which is also the ordinary Dutch word for a health
// premium. The two national tiers stay silent rather than assert a country.
it('withholds the national tiers entirely while no country is chosen', function (): void {
    expect(app(UserCountry::class)->current($this->user->id))->toBe('');

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->toBe(CounterpartyType::Unknown->value);
});

// The silence is the national tiers' alone. A shop is a shop wherever it
// trades, so the corpus still widens to every region for a reader who named no
// country — withholding merchants too would empty the counterparty list on the
// default install path, which is where most readers stay.
it('still names a merchant from another region while no country is chosen', function (): void {
    DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'COLRUYT 4471 HALLE',
        'generalized_pattern' => null,
        'name' => 'Colruyt',
        'category' => null,
        'region' => 'BE',
        'contributor' => 'fixture',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    expect(app(UserCountry::class)->current($this->user->id))->toBe('');

    $resolved = resolveDescription($this->user, $this->account, 'COLRUYT 4471 HALLE');

    expect($resolved?->type)->toBe(CounterpartyType::Merchant->value);
    // merchantName, not displayName: the corpus naming the row is what this
    // asserts, and the display name is the counterparty the row itself names.
    expect($resolved?->merchantName)->toBe('Colruyt');
});

// The column, not the seam: a resolver still reading users.tax_country_code
// would pass every test above by accident of the seam being mocked.
it('reads the country straight off the renamed users column', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['country_code' => 'be']);

    $resolved = resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART');

    expect($resolved?->type)->toBe(CounterpartyType::Government->value);
});

// CounterpartyResolver is a singleton and the desktop runs one long-lived
// process, so a memo that outlived the resolve() call meant changing the
// country in Settings and re-importing classified every row against the old
// one for the rest of the session.
it('picks up a country changed between two resolves on the same instance', function (): void {
    app(UserCountry::class)->store($this->user->id, 'be');
    expect(resolveDescription($this->user, $this->account, 'ZORGPREMIE MAART')?->type)
        ->toBe(CounterpartyType::Government->value);

    app(UserCountry::class)->store($this->user->id, 'nl');

    expect(resolveDescription($this->user, $this->account, 'ZORGPREMIE APRIL')?->type)
        ->not->toBe(CounterpartyType::Government->value);
});
