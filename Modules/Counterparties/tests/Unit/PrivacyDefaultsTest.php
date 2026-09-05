<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
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

// The three tests below are the branch Test 1 never reaches. `resolvePersonal`
// is only entered when the file NAMED somebody, so a fixture that always
// carries a name proved the privacy default for the one arm that could not
// break it, while arms 2 and 7 fell back to the IBAN for a display name.
it('Test 3 — an unresolved counterparty with no name takes an opaque slug rather than its IBAN', function (): void {
    $tx = makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => null,
        'counterpartyIban' => 'NL02ABNA0123456789',
    ]);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto)->not->toBeNull();
    expect($dto->type)->toBe('unknown');
    expect($dto->slug)->toBe(CounterpartySlugResolver::OPAQUE_BASE);

    $row = DB::table('counterparties')->where('id', $dto->counterpartyId)->first();
    expect($row)->not->toBeNull();
    expect($row->slug)->toBe('unnamed');
    expect($row->slug)->not->toContain('nl02');
    expect($row->slug)->not->toContain('abna');
    expect($row->slug)->not->toContain('0123456789');

    // The IBAN stays the DISPLAY name, and has to: it is sealed there, and it
    // is the only thing the reader has to tell one nameless row from another.
    expect($row->display_name)->toBe('NL02ABNA0123456789');
    expect($row->iban)->toBe('NL02ABNA0123456789');
});

it('Test 4 — two nameless counterparties stay two rows, told apart by the suffix and not by their IBANs', function (): void {
    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    $first = $resolver->resolve(makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => null,
        'counterpartyIban' => 'NL02ABNA0123456789',
    ]), $this->user);

    $second = $resolver->resolve(makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => null,
        'counterpartyIban' => 'BE68539007547034',
    ]), $this->user);

    expect($first?->slug)->toBe('unnamed');
    expect($second?->slug)->toBe('unnamed-2');
    expect($first?->counterpartyId)->not->toBe($second?->counterpartyId);
    expect(DB::table('counterparties')->where('user_id', $this->user->id)->count())->toBe(2);

    // Re-resolving the first one is the matching claim: an opaque slug that
    // could not find the row it named would mint a third row on every import.
    $again = $resolver->resolve(makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => null,
        'counterpartyIban' => 'NL02ABNA0123456789',
    ]), $this->user);

    expect($again?->counterpartyId)->toBe($first?->counterpartyId);
    expect($again?->slug)->toBe('unnamed');
    expect(DB::table('counterparties')->where('user_id', $this->user->id)->count())->toBe(2);
});

it('Test 5 — a file that writes the IBAN into the name column, compact or presented, still gets an opaque slug', function (): void {
    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);

    $compact = $resolver->resolve(makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => 'NL02ABNA0123456789',
        'counterpartyIban' => null,
    ]), $this->user);

    expect($compact?->slug)->toBe('unnamed');

    $presented = $resolver->resolve(makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => 'BE68 5390 0754 7034',
        'counterpartyIban' => null,
    ]), $this->user);

    expect($presented?->slug)->toBe('unnamed-2');
    expect($presented?->slug)->not->toContain('be68');
    expect($presented?->slug)->not->toContain('5390');
});

// Arm 2 falls back to the IBAN exactly as arm 7 does, when the alias row
// carries no notes and the statement named nobody. It reached the same
// `upsert()`, so it minted the same URL.
it('Test 6 — the known-IBAN bridge takes an opaque slug when neither the alias nor the file names the institution', function (): void {
    DB::table('known_counterparty_ibans')->insert([
        'user_id' => $this->user->id,
        'real_iban' => 'NL02ABNA0123456789',
        'target_account_kind' => 'bank',
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tx = makePrivacyTx($this->bank->id, $this->user->id, [
        'counterpartyName' => null,
        'counterpartyIban' => 'NL02ABNA0123456789',
    ]);

    /** @var CounterpartyResolverService $resolver */
    $resolver = $this->app->make(CounterpartyResolverService::class);
    $dto = $resolver->resolve($tx, $this->user);

    expect($dto?->type)->toBe('bank');
    expect($dto?->slug)->toBe('unnamed');

    // The IBAN also used to be stamped into `metadata.institution_iban`, a
    // column on no encryption list, beside the sealed `iban` it copied.
    $row = DB::table('counterparties')->where('id', $dto?->counterpartyId)->first();
    expect($row->metadata)->not->toContain('NL02ABNA0123456789');
    expect($row->metadata)->not->toContain('institution_iban');
});

it('Test 7 — a reader who renames a counterparty to an IBAN does not move it onto an IBAN slug', function (): void {
    $id = DB::table('counterparties')->insertGetId([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'bol-com',
        'display_name' => 'Bol.com',
        'iban' => null,
        'merchant_name' => 'Bol.com',
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var CounterpartySlugResolver $slugs */
    $slugs = $this->app->make(CounterpartySlugResolver::class);

    expect($slugs->resolveUnique($this->user->id, 'NL02ABNA0123456789', $id))->toBe('unnamed');
    expect($slugs->resolveUnique($this->user->id, 'Bol.com', $id))->toBe('bol-com');
});
