<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Actions\LabelCounterparty;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The codec answers '' for ciphertext no epoch on this device opened -- the
// state its own comment records as "a phone that lost its keyring rendered
// base64 as names". A device in it must not tell the others what the name is.

function lcnSession(): Session
{
    /** @var Session $session */
    $session = app(Session::class);

    return $session;
}

function lcnUser(): User
{
    $user = User::query()->create([
        'username' => 'label-unopenable',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    AppLockTestHarness::unlock(lcnSession(), str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, lcnSession());

    return $user;
}

// Base64 of 48 random bytes: the shape looksLikeCiphertext() accepts, and no
// epoch on this keyring opens it.
function lcnUnopenableRow(User $user): int
{
    return DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => 'a-name-nothing-opened',
        'display_name' => base64_encode(random_bytes(48)),
        'iban' => null,
        'merchant_name' => null,
        'metadata' => json_encode(
            [CounterpartyMetadataKey::DefaultName->value => CounterpartyDefaultName::BANK_FEE],
            JSON_THROW_ON_ERROR,
        ),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<EntityMutated>
 */
function lcnCapturedWhileIgnoring(User $user, int $id): array
{
    $captured = [];
    Event::listen(EntityMutated::class, function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    /** @var Counterparty $row */
    $row = Counterparty::query()->findOrFail($id);
    app(LabelCounterparty::class)->ignore($row, (int) $user->id, lcnSession());

    return $captured;
}

it('blanks a name it cannot open, which is the state this guard is about', function (): void {
    $user = lcnUser();
    $stored = DB::table('counterparties')->where('id', lcnUnopenableRow($user))->value('display_name');

    $opened = app(SensitiveColumnCodec::class)
        ->decryptValue('counterparties', 'display_name', (string) $stored, (int) $user->id, lcnSession());

    expect($opened['decrypted'])->toBeFalse()
        ->and($opened['value'])->toBe('', 'the codec did not blank it, so the case below cannot arise');
});

it('does not tell the other devices a name it could not read', function (): void {
    $user = lcnUser();
    $id = lcnUnopenableRow($user);

    $captured = lcnCapturedWhileIgnoring($user, $id);

    expect($captured)->not->toBeEmpty('the ignore announced nothing at all, so this proves nothing');

    $fields = $captured[0]->dirtyFields;

    expect($fields)->toHaveKey('metadata')
        ->and($fields['metadata'][CounterpartyMetadataKey::Ignored->value] ?? null)->toBeTrue()
        ->and(array_key_exists('display_name', $fields) ? $fields['display_name'] : 'absent')
        ->not->toBe('', 'the blank the codec returned was announced, so a peer that CAN read the name overwrites it with nothing');
});

// The other half: a name this device CAN read still travels, because the token
// riding alone is what makes a peer render its reader's own rename as the
// app's placeholder. Without this, omitting always would read as a pass.
it('still tells them a name it could read', function (): void {
    $user = lcnUser();
    $sealed = app(SensitiveColumnCodec::class)->encryptAttrs(
        'counterparties',
        ['display_name' => 'Kosten betalingsverkeer'],
        (int) $user->id,
        lcnSession(),
    );

    $id = DB::table('counterparties')->insertGetId($sealed + [
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => 'a-name-this-device-opens',
        'iban' => null,
        'merchant_name' => null,
        'metadata' => json_encode(
            [CounterpartyMetadataKey::DefaultName->value => CounterpartyDefaultName::BANK_FEE],
            JSON_THROW_ON_ERROR,
        ),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $captured = lcnCapturedWhileIgnoring($user, $id);

    expect($captured)->not->toBeEmpty()
        ->and($captured[0]->dirtyFields['display_name'] ?? null)->toBe('Kosten betalingsverkeer');
});
