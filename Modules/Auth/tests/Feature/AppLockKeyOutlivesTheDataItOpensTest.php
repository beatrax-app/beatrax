<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockDisableResult;
use Modules\Auth\Internal\Lock\AppLockKeyState;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Counterparties\Models\Counterparty;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The loss this covers is invisible from the app-lock tables alone: they end up
// perfectly consistent, and it is the ledger that goes blank. So the assertion
// is on a value a user typed, read back the way a screen reads it.

const APP_LOCK_LIFETIME_NAME = 'Albert Heijn Amsterdam Centrum';

function appLockLifetimeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('lifetime-pass'),
        'period_start_day' => 1,
    ]);
}

function appLockLifetimeSetPin(string $pin): void
{
    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', $pin)
        ->set('confirmPin', $pin)
        ->set('accountPassword', 'lifetime-pass')
        ->call('setPin');
}

// Encryption is switched on the way DevicesAndSyncSettingsSection switches it
// on: the same service call, over rows that were written in the clear first.
function appLockLifetimeEncryptedCounterparty(User $user): Counterparty
{
    /** @var Counterparty $counterparty */
    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'app-lock-lifetime-'.bin2hex(random_bytes(4)),
        'display_name' => APP_LOCK_LIFETIME_NAME,
        'merchant_name' => 'ALBERT HEIJN',
    ]);

    /** @var Session $session */
    $session = app(Session::class);

    app(EncryptionMigrationService::class)->migrate($user, $session);

    return $counterparty;
}

function appLockLifetimeStoredName(int $counterpartyId): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $value = $db->connection()->table('counterparties')->where('id', $counterpartyId)->value('display_name');

    return is_string($value) ? $value : '';
}

function appLockLifetimeReadableName(int $userId, int $counterpartyId): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $stored = $db->connection()->table('counterparties')->where('id', $counterpartyId)->first();
    if ($stored === null) {
        return '';
    }

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    /** @var Session $session */
    $session = app(Session::class);

    $decrypted = $codec->decryptRow('counterparties', get_object_vars($stored), $userId, $session);

    /** @var mixed $name */
    $name = $decrypted['display_name'] ?? null;

    return is_string($name) ? $name : '';
}

it('keeps an encrypted value readable across a disable and a re-enable of the app lock', function (): void {
    $user = appLockLifetimeUser('lifetime-round-trip');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    $counterparty = appLockLifetimeEncryptedCounterparty($user);

    expect(appLockLifetimeStoredName((int) $counterparty->id))->not->toBe(APP_LOCK_LIFETIME_NAME);
    expect(appLockLifetimeReadableName((int) $user->id, (int) $counterparty->id))->toBe(APP_LOCK_LIFETIME_NAME);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '123456')
        ->call('disable');

    appLockLifetimeSetPin('654321');

    expect(appLockLifetimeReadableName((int) $user->id, (int) $counterparty->id))->toBe(APP_LOCK_LIFETIME_NAME);
});

it('refuses the disable and says why, leaving the lock on', function (): void {
    $user = appLockLifetimeUser('lifetime-refusal');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '123456')
        ->call('disable')
        ->assertSet('lockEnabled', true)
        ->assertSet('confirmingDisable', false)
        ->assertSee('turning the lock off would leave them unreadable');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect($provisioner->isEnabled((int) $user->id))->toBeTrue();
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Held);
});

// A wrong PIN and a refusal are different answers, and the screen shows a
// different one for each.
it('still answers a wrong PIN with a wrong-PIN error', function (): void {
    $user = appLockLifetimeUser('lifetime-wrong-pin');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect($provisioner->disable((int) $user->id, '999999'))->toBe(AppLockDisableResult::PinIncorrect);
    expect($provisioner->disable((int) $user->id, '123456'))->toBe(AppLockDisableResult::EncryptedDataDependsOnIt);
});

// The state an install that ran the old disable() is already in. Built by
// clearing the wraps exactly as that release cleared them.
function appLockLifetimeStrandRow(int $userId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('user_app_lock_configs')->where('user_id', $userId)->update([
        'lock_enabled' => false,
        'pin_hash' => null,
        'kdf_salt' => null,
        'pin_wrapped_key' => null,
        'password_wrapped_key' => null,
    ]);
}

it('names an already-stranded install rather than minting a second key over it', function (): void {
    $user = appLockLifetimeUser('lifetime-stranded');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);
    appLockLifetimeStrandRow((int) $user->id);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Stranded);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '654321')
        ->set('confirmPin', '654321')
        ->set('accountPassword', 'lifetime-pass')
        ->call('setPin')
        ->assertSet('lockEnabled', false)
        ->assertSee('no longer holds the key that opens your encrypted data');

    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Stranded);
});

it('reports a stranded install on the settings screen it is configured from', function (): void {
    $user = appLockLifetimeUser('lifetime-stranded-notice');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);
    appLockLifetimeStrandRow((int) $user->id);

    Livewire::test(AppLockSettingsSection::class)
        ->assertSee('no longer holds the key that opens your encrypted data');
});

it('raises one critical alert per unacknowledged row when a sign-in finds an install stranded', function (): void {
    $user = appLockLifetimeUser('lifetime-stranded-alert');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);
    appLockLifetimeStrandRow((int) $user->id);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    /** @var Session $session */
    $session = app(Session::class);

    $provisioner->primeSessionAfterLogin((int) $user->id, 'lifetime-pass', $session);
    $provisioner->primeSessionAfterLogin((int) $user->id, 'lifetime-pass', $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $alerts = $db->connection()->table('system_alerts')
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.key_material_stranded')
        ->pluck('severity')
        ->all();

    expect($alerts)->toBe(['critical']);
});

// Defence in depth below the settings screen: the screen refuses first, so a
// caller that skips it must still not mint a key over encrypted data.
it('refuses at the provisioner even when the settings screen is bypassed', function (): void {
    $user = appLockLifetimeUser('lifetime-provisioner-guard');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');
    appLockLifetimeEncryptedCounterparty($user);
    appLockLifetimeStrandRow((int) $user->id);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect(fn () => $provisioner->enable((int) $user->id, '654321', 'lifetime-pass'))
        ->toThrow(ValidationException::class);

    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Stranded);
});

// Enabling for the first time is the case a fresh key is right for, and it has
// to keep working — a guard that blocked it would be the same defect inverted.
it('still mints a key for a first-time enable', function (): void {
    $user = appLockLifetimeUser('lifetime-first-enable');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Absent);

    appLockLifetimeSetPin('123456');

    expect($provisioner->isEnabled((int) $user->id))->toBeTrue();
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Held);
});

// Without encryption there is nothing whose key could be stranded, so the
// ordinary disable is untouched.
it('still disables the lock for a user with nothing encrypted', function (): void {
    $user = appLockLifetimeUser('lifetime-plain-disable');
    $this->actingAs($user);

    appLockLifetimeSetPin('123456');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect($provisioner->disable((int) $user->id, '123456'))->toBe(AppLockDisableResult::Disabled);
    expect($provisioner->isEnabled((int) $user->id))->toBeFalse();
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Absent);
});
