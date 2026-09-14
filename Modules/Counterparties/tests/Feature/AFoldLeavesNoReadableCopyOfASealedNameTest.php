<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Public\Contracts\MergesCounterparties;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// counterparties.display_name is sealed and counterparties.metadata is not, so
// what the fold copies from one into the other is stored in whatever form it
// copies it in. This is the same column the institution_iban migration cleared.

function afwUser(): User
{
    $user = User::query()->create([
        'username' => 'fold-clear-name',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    return $user;
}

function afwCounterparty(User $user, string $displayName): int
{
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var Session $session */
    $session = app(Session::class);
    /** @var CounterpartySlugResolver $slugs */
    $slugs = app(CounterpartySlugResolver::class);

    $sealed = $codec->encryptAttrs(
        'counterparties',
        ['display_name' => $displayName],
        (int) $user->id,
        $session,
    );

    return DB::table('counterparties')->insertGetId($sealed + [
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => $slugs->resolveUnique((int) $user->id, $displayName),
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('leaves no readable copy of an absorbed sealed name in the unsealed column', function (): void {
    $user = afwUser();
    $survivorId = afwCounterparty($user, 'Acme Holdings B.V.');
    afwCounterparty($user, 'Zorgverzekeraar Menzis U.A.');

    app(MergesCounterparties::class)->fold(
        $user,
        ['Acme Holdings B.V.', 'Zorgverzekeraar Menzis U.A.'],
        'Acme Holdings B.V.',
    );

    $metadata = DB::table('counterparties')->where('id', $survivorId)->value('metadata');

    expect($metadata)->toBeString('the fold recorded no provenance at all, so this proves nothing')
        ->and($metadata)->toContain('merged_from')
        ->and($metadata)->not->toContain('Zorgverzekeraar Menzis U.A.');
});

// The slug resolver answers `unnamed` for a name that spells an account number,
// so the address bar cannot. That bound is the reason the registry accepts the
// slug as a readable shadow at all, and the fold has to honour the same one.
it('keeps the account number a slug is opaque for out of the column too', function (): void {
    $user = afwUser();
    $survivorId = afwCounterparty($user, 'Acme Holdings B.V.');
    $absorbedId = afwCounterparty($user, 'NL91ABNA0417164300');

    $absorbedSlug = DB::table('counterparties')->where('id', $absorbedId)->value('slug');

    app(MergesCounterparties::class)->fold(
        $user,
        ['Acme Holdings B.V.', 'NL91ABNA0417164300'],
        'Acme Holdings B.V.',
    );

    $metadata = DB::table('counterparties')->where('id', $survivorId)->value('metadata');

    expect($absorbedSlug)->toBe(CounterpartySlugResolver::OPAQUE_BASE, 'the slug was never opaque, so there is no bound here to defeat')
        ->and($metadata)->toBeString()
        ->and($metadata)->not->toContain('NL91ABNA0417164300');
});

function afwMigration(): object
{
    return require base_path(
        'Modules/Counterparties/Database/Migrations/2026_09_14_000001_drop_the_plaintext_absorbed_name_from_counterparty_metadata.php'
    );
}

function afwRowWithMetadata(User $user, string $slug, array $metadata): int
{
    return DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => 'sealed-value-stands-in',
        'iban' => null,
        'merchant_name' => null,
        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function afwMetadata(int $id): ?array
{
    $value = DB::table('counterparties')->where('id', $id)->value('metadata');

    return is_string($value) ? json_decode($value, true) : null;
}

// The rows a fold already wrote. The reader's own decisions live in the same
// column, so this has to be a key removal inside each entry rather than a
// column reset or a dropped provenance list.
it('clears the name a fold already wrote and disturbs nothing beside it', function (): void {
    $user = afwUser();
    $folded = afwRowWithMetadata($user, 'acme-holdings', [
        'ignored' => true,
        'default_name' => 'bank_fee',
        'merged_from' => [
            ['name' => 'NL91ABNA0417164300', 'slug' => 'unnamed', 'moved' => 4, 'merged_at' => '2026-09-01T00:00:00+00:00'],
            ['name' => 'Zorgverzekeraar Menzis U.A.', 'slug' => 'zorgverzekeraar-menzis-u-a', 'moved' => 1, 'merged_at' => '2026-09-01T00:00:00+00:00'],
        ],
    ]);
    $untouched = afwRowWithMetadata($user, 'never-folded', ['matched_keyword' => 'BELASTINGDIENST']);

    afwMigration()->up();

    $metadata = afwMetadata($folded);

    expect(json_encode($metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('NL91ABNA0417164300')
        ->not->toContain('Zorgverzekeraar Menzis U.A.')
        ->and($metadata['merged_from'][0])->toBe(['slug' => 'unnamed', 'moved' => 4, 'merged_at' => '2026-09-01T00:00:00+00:00'])
        ->and($metadata['merged_from'][1]['slug'])->toBe('zorgverzekeraar-menzis-u-a')
        ->and($metadata['ignored'])->toBeTrue()
        ->and($metadata['default_name'])->toBe('bank_fee')
        ->and(afwMetadata($untouched))->toBe(['matched_keyword' => 'BELASTINGDIENST']);
});
