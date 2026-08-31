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

function makePrivacyUser(string $username): User
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
function makePrivacyTx(int $accountId, ?int $userId, array $overrides = []): CanonicalTransaction
{
    $defaults = [
        'userId' => $userId,
        'accountId' => $accountId,
        'type' => 'transfer_in',
        'postedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'bookedAt' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'valueDate' => CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'amountMinor' => 5000,
        'currency' => 'EUR',
        'settledAmountMinor' => 5000,
        'settledCurrency' => 'EUR',
        'counterpartyName' => 'Maria van Buren',
        'counterpartyIban' => 'NL02ABNA0123456789',
        'counterpartyNormalized' => 'maria-van-buren',
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

beforeEach(function (): void {
    $this->user = makePrivacyUser('privacy-fixture');
    $this->bank = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Privacy ASN',
        'slug' => 'privacy-asn',
        'kind' => 'bank',
        'iban' => 'NL53ASNB0000000111',
        'default_currency' => 'EUR',
    ]);
});

it('Test 1 — personal-type slug carries the display name only and never the IBAN', function (): void {
    $tx = makePrivacyTx($this->bank->id, $this->user->id);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('personal');
    expect($dto->slug)->toBe('maria-van-buren');

    $row = DB::table('counterparties')->where('id', $dto->counterpartyId)->first();
    expect($row)->not->toBeNull();
    expect($row->slug)->toBe('maria-van-buren');
    expect($row->slug)->not->toContain('nl12');
    expect($row->slug)->not->toContain('abna');
    expect($row->slug)->not->toContain('0123456789');
});

it('Test 2 — personal-type iban column IS populated even though slug stays private', function (): void {
    $tx = makePrivacyTx($this->bank->id, $this->user->id);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->iban)->toBe('NL02ABNA0123456789');

    $row = DB::table('counterparties')->where('id', $dto->counterpartyId)->first();
    expect($row)->not->toBeNull();
    expect($row->iban)->toBe('NL02ABNA0123456789');
    expect($row->slug)->not->toContain($row->iban);
});
