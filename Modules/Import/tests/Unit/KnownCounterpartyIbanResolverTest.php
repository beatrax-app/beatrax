<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, bank: Account, paypal: Account, icsCard: Account}
 */
function seedUserWithAccounts(string $username, bool $runAliasSeeder = true): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $bank = Account::create([
        'user_id' => $user->id,
        'name' => $username.' ASN',
        'slug' => $username.'-asn',
        'kind' => 'bank',
        'iban' => 'NL12ASNB'.str_pad((string) random_int(1000000, 9999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::create([
        'user_id' => $user->id,
        'name' => $username.' PayPal',
        'slug' => $username.'-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $icsCard = Account::create([
        'user_id' => $user->id,
        'name' => $username.' ICS',
        'slug' => $username.'-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    if ($runAliasSeeder) {
        app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);
    }

    return compact('user', 'bank', 'paypal', 'icsCard');
}

it('resolves PayPal Luxembourg IBAN to the users paypal account', function (): void {
    $world = seedUserWithAccounts('resolver-paypal');

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    $resolved = $resolver->resolveAccount('LU89751000135104200E', $world['user']->id);

    expect($resolved)->not->toBeNull();
    expect($resolved->kind)->toBe('paypal');
    expect($resolved->id)->toBe($world['paypal']->id);
});

it('resolves ICS ABN AMRO IBAN to the users ics_card account', function (): void {
    $world = seedUserWithAccounts('resolver-ics');

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    $resolved = $resolver->resolveAccount('NL08ABNA0526650664', $world['user']->id);

    expect($resolved)->not->toBeNull();
    expect($resolved->kind)->toBe('ics_card');
    expect($resolved->id)->toBe($world['icsCard']->id);
});

it('returns null when no alias row exists for the IBAN', function (): void {
    $world = seedUserWithAccounts('resolver-no-alias');

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    expect($resolver->resolveAccount('NL00BOGUS00000000000', $world['user']->id))->toBeNull();
});

it('returns null when alias exists but the user owns no account of the target kind', function (): void {
    $user = User::query()->create([
        'username' => 'resolver-no-target-account',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    Account::create([
        'user_id' => $user->id,
        'name' => 'Only ASN',
        'slug' => 'resolver-no-target-account-asn',
        'kind' => 'bank',
        'iban' => 'NL20ASNB0000000123',
        'default_currency' => 'EUR',
    ]);

    KnownCounterpartyIban::query()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
        'notes' => 'PayPal SARL et Cie SCA, Luxembourg.',
    ]);

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    expect($resolver->resolveAccount('LU89751000135104200E', $user->id))->toBeNull();
});

it('returns null on empty or whitespace IBAN', function (): void {
    $world = seedUserWithAccounts('resolver-empty-iban');

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    expect($resolver->resolveAccount('', $world['user']->id))->toBeNull();
    expect($resolver->resolveAccount('   ', $world['user']->id))->toBeNull();
});

it('returns the lowest-id account when the user owns two accounts of the alias target kind', function (): void {
    // The schema permits N accounts per kind per user, so the resolver needs a
    // deterministic tie-break. Pinned here so a storage-engine change cannot
    // silently flip which one comes back.
    $user = User::query()->create([
        'username' => 'resolver-multi-bank',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $firstBank = Account::create([
        'user_id' => $user->id,
        'name' => 'First ASN',
        'slug' => 'resolver-multi-bank-first',
        'kind' => 'bank',
        'iban' => 'NL30ASNB0123450000',
        'default_currency' => 'EUR',
    ]);
    Account::create([
        'user_id' => $user->id,
        'name' => 'Second ABN',
        'slug' => 'resolver-multi-bank-second',
        'kind' => 'bank',
        'iban' => 'NL85ABNA0000000000',
        'default_currency' => 'EUR',
    ]);

    KnownCounterpartyIban::query()->create([
        'user_id' => $user->id,
        'real_iban' => 'NL85THRD0000000000',
        'target_account_kind' => 'bank',
        'notes' => 'Fictional third-party institution that aliases to bank.',
    ]);

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    $resolved = $resolver->resolveAccount('NL85THRD0000000000', $user->id);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($firstBank->id);
});

it('per-user scoping — user As alias does not resolve for user B', function (): void {
    seedUserWithAccounts('resolver-scope-a', runAliasSeeder: true);
    $worldB = seedUserWithAccounts('resolver-scope-b', runAliasSeeder: false);

    $resolver = app(ResolvesKnownCounterpartyIban::class);
    expect($resolver->resolveAccount('LU89751000135104200E', $worldB['user']->id))->toBeNull();
});
