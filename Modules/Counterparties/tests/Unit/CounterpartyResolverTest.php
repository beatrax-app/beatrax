<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Events\CounterpartyResolved;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

function makeCpResolverUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function makeCpResolverAccount(User $user, string $kind, string $iban, ?string $slugSuffix = null): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => $kind.'-'.$iban,
        'slug' => ($slugSuffix ?? $user->username).'-'.$kind,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCpResolverTx(int $accountId, ?int $userId = null, array $overrides = []): CanonicalTransaction
{
    $defaults = [
        'userId' => $userId,
        'accountId' => $accountId,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'bookedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'valueDate' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'amountMinor' => -1099,
        'currency' => 'EUR',
        'settledAmountMinor' => -1099,
        'settledCurrency' => 'EUR',
        'fxRateUsed' => null,
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
        fxRateUsed: $args['fxRateUsed'],
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

// The government and bank-fee tiers only load a country's rules once a country
// is named — no country means silence, not every country — so this fixture is a
// Dutch reader. Test 6b names Germany instead, because the rule it exercises is
// German.
beforeEach(function (): void {
    $this->user = makeCpResolverUser('resolver-fixture');
    $this->bank = makeCpResolverAccount($this->user, 'bank', 'NL57ASNB0123456789');
    app(UserCountry::class)->store($this->user->id, 'nl');
});

it('Test 1 — step 1 self_account: routes the users own IBAN to type=self_account without writing a counterparty row', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['counterpartyIban' => 'NL57ASNB0123456789'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('self_account');
    expect($dto->counterpartyId)->toBeNull();
    expect($dto->slug)->toBe('');

    expect(DB::table('counterparties')->count())->toBe(0);
});

it('Test 2 — step 2 known-bridge: PayPal Luxembourg IBAN resolves to type=bank and upserts a row', function (): void {
    // The bridge resolves into an account of the alias's target kind, so both
    // synthetic accounts have to exist before the seeder runs.
    makeCpResolverAccount($this->user, 'paypal', 'PAYPAL', 'resolver-fixture-2');
    makeCpResolverAccount($this->user, 'ics_card', 'ICS-CARD', 'resolver-fixture-3');
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    Event::fake([CounterpartyResolved::class]);

    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['counterpartyIban' => 'LU89751000135104200E'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('bank');
    expect($dto->displayName)->toContain('PayPal');
    expect($dto->counterpartyId)->not->toBeNull();

    expect(DB::table('counterparties')->where('id', $dto->counterpartyId)->count())->toBe(1);
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->type === 'bank' && $e->userId === $this->user->id);
});

it('Test 3 — step 3 merchant: a Netflix-shaped description resolves to type=merchant via MerchantNameResolver', function (): void {
    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'NETFLIX.COM AMSTERDAM',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'NETFLIX.COM AMSTERDAM'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('merchant');
    expect($dto->displayName)->toBe('Netflix');
    expect($dto->merchantName)->toBe('Netflix');
    expect($dto->slug)->toBe('netflix');
});

it('Test 4 — step 4 personal: Dutch IBAN + personal name on transfer_in resolves to type=personal and slug omits the IBAN', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: [
            'type' => 'transfer_in',
            'counterpartyName' => 'Maria van Buren',
            'counterpartyIban' => 'NL02ABNA0123456789',
        ],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('personal');
    expect($dto->displayName)->toBe('Maria van Buren');
    expect($dto->slug)->toBe('maria-van-buren');
    expect($dto->iban)->toBe('NL02ABNA0123456789');
    expect($dto->slug)->not->toContain('nl12');
    expect($dto->slug)->not->toContain('abna');
});

it('Test 5 — step 5 government: BELASTINGDIENST in the description resolves to type=government', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'BELASTINGDIENST INKOMSTENBELASTING 2025'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('government');
    expect($dto->metadata['matched_keyword'] ?? null)->toBe('BELASTINGDIENST');
});

it('Test 6 — step 6 bank fee: KOSTEN KASOPNAME in the description resolves to type=bank with metadata.subcategory=fee', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'KOSTEN KASOPNAME AUTOMAAT'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('bank');
    expect($dto->metadata['subcategory'] ?? null)->toBe('fee');
});

it('Test 6b — a regex government rule (DE Rundfunkbeitrag) resolves to type=government with the rule name', function (): void {
    app(UserCountry::class)->store($this->user->id, 'de');

    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'BEITRAGSSERVICE ARD ZDF DEUTSCHLANDRADIO'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('government');
    expect($dto->displayName)->toBe('Rundfunkbeitrag');
});

it('Test 6c — the KOSTEN bank-fee rule uses a word boundary, so ONKOSTEN does not match', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'ONKOSTENVERGOEDING DECLARATIE'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto?->type)->not->toBe('bank');
});

it('Test 7 — step 7 unknown: an unmatched transaction with only an IBAN resolves to type=unknown and preserves the IBAN', function (): void {
    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: [
            'counterpartyIban' => 'NL99BOGUS0000000001',
            'description' => 'Unmatchable random reference',
        ],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('unknown');
    expect($dto->iban)->toBe('NL99BOGUS0000000001');
});

it('Test 8 — idempotency: resolving the same transaction twice returns the same counterpartyId', function (): void {
    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'SPOTIFY AB',
        'generalized_pattern' => 'spotify',
        'friendly_name' => 'Spotify',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $tx = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'SPOTIFY AB'],
    );

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    $first = $resolver->resolve($tx, $this->user);
    $second = $resolver->resolve($tx, $this->user);

    expect($first)->not->toBeNull();
    expect($second)->not->toBeNull();
    expect($first->counterpartyId)->toBe($second->counterpartyId);
    expect(DB::table('counterparties')->where('display_name', 'Spotify')->count())->toBe(1);
});

it('Test 9 — cross-user isolation: user As resolve does not touch user Bs counterparties', function (): void {
    $userB = makeCpResolverUser('resolver-other');
    $userBBank = makeCpResolverAccount($userB, 'bank', 'NL08ASNB9999999999');

    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'SHARED MERCHANT',
        'generalized_pattern' => 'shared',
        'friendly_name' => 'Shared',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::table('merchant_aliases')->insert([
        'user_id' => $userB->id,
        'pattern' => 'SHARED MERCHANT',
        'generalized_pattern' => 'shared',
        'friendly_name' => 'Shared',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $txA = makeCpResolverTx(
        accountId: $this->bank->id,
        userId: $this->user->id,
        overrides: ['description' => 'SHARED MERCHANT'],
    );
    $txB = makeCpResolverTx(
        accountId: $userBBank->id,
        userId: $userB->id,
        overrides: ['description' => 'SHARED MERCHANT'],
    );

    /** @var CounterpartyResolver $resolver */
    $resolver = $this->app->make(CounterpartyResolver::class);

    $dtoA = $resolver->resolve($txA, $this->user);
    $dtoB = $resolver->resolve($txB, $userB);

    expect($dtoA)->not->toBeNull();
    expect($dtoB)->not->toBeNull();

    expect(DB::table('counterparties')->where('user_id', $this->user->id)->count())->toBe(1);
    expect(DB::table('counterparties')->where('user_id', $userB->id)->count())->toBe(1);

    expect(DB::table('counterparties')->where('id', $dtoA->counterpartyId)->value('user_id'))->toBe($this->user->id);
    expect(DB::table('counterparties')->where('id', $dtoB->counterpartyId)->value('user_id'))->toBe($userB->id);
});
