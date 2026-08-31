<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

function makeSlugUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeSlugTx(int $accountId, ?int $userId, array $overrides = []): CanonicalTransaction
{
    $defaults = [
        'userId' => $userId,
        'accountId' => $accountId,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'bookedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'valueDate' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'amountMinor' => -1500,
        'currency' => 'EUR',
        'settledAmountMinor' => -1500,
        'settledCurrency' => 'EUR',
        'counterpartyName' => null,
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'fixture',
        'normalizationVersion' => 1,
        'description' => null,
        'categoryId' => null,
        'sourceFormat' => 'asn_csv',
        'importRunId' => 1,
        'sourceRowIndex' => 1,
        'sourceRef' => null,
    ];

    /** @var array<string, mixed> $args */
    $args = array_merge($defaults, $overrides);

    return new CanonicalTransaction(
        userId: $args['userId'],
        accountId: $args['accountId'],
        type: $args['type'],
        postedAt: $args['postedAt'],
        bookedAt: $args['bookedAt'],
        valueDate: $args['valueDate'],
        amountMinor: $args['amountMinor'],
        currency: $args['currency'],
        settledAmountMinor: $args['settledAmountMinor'],
        settledCurrency: $args['settledCurrency'],
        counterpartyName: $args['counterpartyName'],
        counterpartyIban: $args['counterpartyIban'],
        counterpartyNormalized: $args['counterpartyNormalized'],
        normalizationVersion: $args['normalizationVersion'],
        description: $args['description'],
        categoryId: $args['categoryId'],
        sourceFormat: $args['sourceFormat'],
        importRunId: $args['importRunId'],
        sourceRowIndex: $args['sourceRowIndex'],
        sourceRef: $args['sourceRef'],
    );
}

function seedSlugMerchantAlias(int $userId, string $pattern, string $generalized, string $friendlyName): void
{
    DB::table('merchant_aliases')->insert([
        'user_id' => $userId,
        'pattern' => $pattern,
        'generalized_pattern' => $generalized,
        'friendly_name' => $friendlyName,
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

beforeEach(function (): void {
    $this->user = makeSlugUser('slug-fixture');
    $this->bank = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Slug ASN',
        'slug' => 'slug-fixture-asn',
        'kind' => 'bank',
        'iban' => 'NL63ASNB0000000222',
        'default_currency' => 'EUR',
    ]);
});

it('Test 1 — two distinct merchants both resolving to "Bol" produce slugs "bol" and "bol-2"', function (): void {
    // The idempotency check keys on (user_id, slug, display_name), so the
    // squatting row needs a DIFFERENT display name — otherwise the resolver
    // reuses it instead of taking the collision path.
    DB::table('counterparties')->insert([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'bol',
        'display_name' => 'Bol Original',
        'iban' => null,
        'merchant_name' => 'Bol Original',
        'metadata' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    seedSlugMerchantAlias($this->user->id, 'BOL.COM B.V.', 'bol.com', 'Bol');

    $tx = makeSlugTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'BOL.COM B.V.'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->displayName)->toBe('Bol');
    expect($dto->slug)->toBe('bol-2');

    expect(DB::table('counterparties')->where('user_id', $this->user->id)->where('slug', 'bol')->value('display_name'))->toBe('Bol Original');
    expect(DB::table('counterparties')->where('user_id', $this->user->id)->where('slug', 'bol-2')->value('display_name'))->toBe('Bol');
});

it('Test 2 — a third distinct "Bol" produces "bol-3"', function (): void {
    DB::table('counterparties')->insert([
        [
            'user_id' => $this->user->id,
            'type' => 'merchant',
            'slug' => 'bol',
            'display_name' => 'Bol Original',
            'iban' => null,
            'merchant_name' => 'Bol Original',
            'metadata' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'user_id' => $this->user->id,
            'type' => 'merchant',
            'slug' => 'bol-2',
            'display_name' => 'Bol Second',
            'iban' => null,
            'merchant_name' => 'Bol Second',
            'metadata' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    seedSlugMerchantAlias($this->user->id, 'BOL THIRD', 'bol third', 'Bol');

    $tx = makeSlugTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'BOL THIRD'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->slug)->toBe('bol-3');
});

it('Test 3 — collision suffixing is per-user: user As "bol" does not block user Bs "bol"', function (): void {
    $userB = makeSlugUser('slug-other');
    $userBBank = Account::create([
        'user_id' => $userB->id,
        'name' => 'Other ASN',
        'slug' => 'slug-other-asn',
        'kind' => 'bank',
        'iban' => 'NL73ASNB0000000333',
        'default_currency' => 'EUR',
    ]);

    seedSlugMerchantAlias($this->user->id, 'BOL.COM B.V.', 'bol.com', 'Bol');
    seedSlugMerchantAlias($userB->id, 'BOL.COM B.V.', 'bol.com', 'Bol');

    $txA = makeSlugTx($this->bank->id, $this->user->id, ['description' => 'BOL.COM B.V.']);
    $txB = makeSlugTx($userBBank->id, $userB->id, ['description' => 'BOL.COM B.V.']);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    $dtoA = $resolver->resolve($txA, $this->user);
    $dtoB = $resolver->resolve($txB, $userB);

    expect($dtoA)->not->toBeNull();
    expect($dtoB)->not->toBeNull();
    expect($dtoA->slug)->toBe('bol');
    expect($dtoB->slug)->toBe('bol');

    expect(DB::table('counterparties')->where('user_id', $this->user->id)->where('slug', 'bol')->count())->toBe(1);
    expect(DB::table('counterparties')->where('user_id', $userB->id)->where('slug', 'bol')->count())->toBe(1);
});
