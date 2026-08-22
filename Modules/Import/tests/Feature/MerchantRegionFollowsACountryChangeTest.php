<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Import\Public\Services\MerchantNameResolver;

uses(RefreshDatabase::class);

// The corpus is region-scoped because short merchant tokens collide across
// countries: a Dutch `Albert Heijn 1042` resolved to the Czech chain ALBERT
// until the scoping existed. The scope is read once and remembered — and the
// desktop runs one long-lived process, so a memo that outlives the call
// classifies every later import against a country the reader has since
// changed. The resolver next door was fixed for this; this one was not.

function merchantRegionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function seedRegionalMerchant(string $pattern, string $name, string $region): void
{
    DB::table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => $pattern,
        'generalized_pattern' => null,
        'name' => $name,
        'category' => null,
        'region' => $region,
        'contributor' => 'fixture',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('resolves against the country the reader has now, not the one they had when the process started', function (): void {
    $user = merchantRegionUser('merchant-region-change');
    $userId = (int) $user->id;

    // The global tier is unique on pattern, so one shop per country rather
    // than one pattern in two — which is the same test either way: what the
    // reader's region decides is which rows are in scope at all.
    seedRegionalMerchant('ALBERT 1042', 'Albert (CZ)', 'CZ');
    seedRegionalMerchant('JUMBO 88', 'Jumbo', 'NL');

    /** @var UserCountry $countries */
    $countries = app(UserCountry::class);
    /** @var MerchantNameResolver $resolver */
    $resolver = app(MerchantNameResolver::class);

    $countries->store($userId, 'cz');
    expect($resolver->resolve('ALBERT 1042', $userId))->toBe('Albert (CZ)');
    expect($resolver->resolve('JUMBO 88', $userId))->toBeNull();

    $countries->store($userId, 'nl');

    expect($resolver->resolve('JUMBO 88', $userId))->toBe('Jumbo');
    expect($resolver->resolve('ALBERT 1042', $userId))->toBeNull();
});
