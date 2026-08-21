<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

// The slug resolver compares a plaintext displayName against the stored value,
// so it has to decrypt first: without that, every re-resolution of one merchant
// fragments into "bol", "bol-2", "bol-3", … forever.

function ceUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function ceAccount(User $user, string $iban): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => $user->username.'-asn',
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function ceTx(int $accountId, int $userId, string $description): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        bookedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        valueDate: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        amountMinor: -1099,
        currency: 'EUR',
        settledAmountMinor: -1099,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'fixture',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn_csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    );
}

beforeEach(function (): void {
    $this->user = ceUser('cp-encryption-user');
    $this->bank = ceAccount($this->user, 'NL57ASNB0123456789');

    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'NETFLIX.COM AMSTERDAM',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // A dummy app-lock KEK, primed the way Modules/Sync/tests/TestCase.php does.
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $this->user->id, $session);
});

it('encrypts counterparties.display_name/merchant_name at the resolver creation write, and the DTO stays plaintext', function (): void {
    $tx = ceTx($this->bank->id, (int) $this->user->id, 'NETFLIX.COM AMSTERDAM');

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->displayName)->toBe('Netflix');
    expect($dto->merchantName)->toBe('Netflix');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $stored = $db->connection()->table('counterparties')->where('id', $dto->counterpartyId)->first();

    expect($stored->display_name)->not->toBe('Netflix');
    expect($stored->merchant_name)->not->toBe('Netflix');
    // The slug is a matching and routing key, so it stays plaintext.
    expect($stored->slug)->toBe('netflix');
});

it('re-resolving the same transaction stays idempotent even though display_name is ciphertext at rest', function (): void {
    $tx = ceTx($this->bank->id, (int) $this->user->id, 'NETFLIX.COM AMSTERDAM');

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    $first = $resolver->resolve($tx, $this->user);
    $second = $resolver->resolve($tx, $this->user);
    $third = $resolver->resolve($tx, $this->user);

    expect($first)->not->toBeNull();
    expect($second)->not->toBeNull();
    expect($third)->not->toBeNull();
    expect($second->counterpartyId)->toBe($first->counterpartyId);
    expect($third->counterpartyId)->toBe($first->counterpartyId);
    expect($second->slug)->toBe('netflix');
    expect($third->slug)->toBe('netflix');

    expect(DB::table('counterparties')->where('user_id', $this->user->id)->count())->toBe(1);
});

it('CounterpartyProfileQuery::bySlug decrypts display_name/merchant_name/iban', function (): void {
    $tx = ceTx($this->bank->id, (int) $this->user->id, 'NETFLIX.COM AMSTERDAM');

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);
    expect($dto)->not->toBeNull();

    /** @var CounterpartyProfileQuery $query */
    $query = $this->app->make(CounterpartyProfileQuery::class);
    $profile = $query->bySlug($this->user, $dto->slug);

    expect($profile)->not->toBeNull();
    expect($profile->displayName)->toBe('Netflix');
    expect($profile->merchantName)->toBe('Netflix');
});

it('CounterpartyIndexQuery::forUser decrypts display_name', function (): void {
    $tx = ceTx($this->bank->id, (int) $this->user->id, 'NETFLIX.COM AMSTERDAM');

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $resolver->resolve($tx, $this->user);

    /** @var CounterpartyIndexQuery $query */
    $query = $this->app->make(CounterpartyIndexQuery::class);
    $rows = $query->forUser($this->user);

    expect($rows->pluck('displayName')->all())->toContain('Netflix');
});

it('CounterpartyIndexQuery::forUser stays correctly name-sorted (PHP usort) despite orderBy(\'id\') over ciphertext (D-12)', function (): void {
    // No transaction rows, so all three totals tie at zero and the name
    // tie-break alone decides the order. They are resolved Zebra, Alpha,
    // Middle so the auto-increment ids do not accidentally match the
    // alphabetical order the assertion expects.
    DB::table('merchant_aliases')->insert([
        ['user_id' => $this->user->id, 'pattern' => 'ZEBRA STORE AMSTERDAM', 'generalized_pattern' => 'zebra', 'friendly_name' => 'Zebra', 'merged_from' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['user_id' => $this->user->id, 'pattern' => 'ALPHA SHOP AMSTERDAM', 'generalized_pattern' => 'alpha', 'friendly_name' => 'Alpha', 'merged_from' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['user_id' => $this->user->id, 'pattern' => 'MIDDLE GOODS AMSTERDAM', 'generalized_pattern' => 'middle', 'friendly_name' => 'Middle', 'merged_from' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $resolver->resolve(ceTx($this->bank->id, (int) $this->user->id, 'ZEBRA STORE AMSTERDAM'), $this->user);
    $resolver->resolve(ceTx($this->bank->id, (int) $this->user->id, 'ALPHA SHOP AMSTERDAM'), $this->user);
    $resolver->resolve(ceTx($this->bank->id, (int) $this->user->id, 'MIDDLE GOODS AMSTERDAM'), $this->user);

    /** @var CounterpartyIndexQuery $query */
    $query = $this->app->make(CounterpartyIndexQuery::class);
    $rows = $query->forUser($this->user);

    $names = $rows->pluck('displayName')->filter(
        static fn (string $name): bool => in_array($name, ['Zebra', 'Alpha', 'Middle'], true),
    )->values()->all();

    expect($names)->toBe(['Alpha', 'Middle', 'Zebra']);
});
