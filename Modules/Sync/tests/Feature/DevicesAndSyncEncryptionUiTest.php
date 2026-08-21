<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

function encryptionUiUser(string $username = 'encryption-ui-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('encryption-ui-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('(a) hides the encryption row and every Remove link when no app-lock is configured', function (): void {
    $user = encryptionUiUser('encryption-ui-no-lock');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', false)
        ->set('syncEnabled', false)
        ->assertDontSee('encryption-status-row', escape: false)
        ->assertDontSee('enable-encryption-cta', escape: false)
        ->assertDontSee('Data encrypted at rest')
        ->assertDontSee('Securing your data…');
});

it('(b) single-device (sync off), app-lock set, encryption off: shows the blue offer notice with a decline-able CTA', function (): void {
    $user = encryptionUiUser('encryption-ui-offer');
    $this->actingAs($user);

    $component = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', false)
        ->set('encryptionOn', false)
        ->assertSee('encryption-offer-notice', escape: false)
        ->assertSee('Your data is not encrypted at rest. Set up encryption to protect it if this device is lost or stolen.')
        ->assertSee('enable-encryption-cta', escape: false)
        ->assertDontSee('encryption-securing-notice', escape: false);

    // The decline button only renders inside the open enable-encryption modal.
    $component->call('showEnableEncryptionModal')
        ->assertSet('showEncryptionModal', true)
        ->assertSet('encryptionStep', 'confirm')
        ->assertSee('Keep data unencrypted')
        ->assertSee('Enable at-rest encryption');
});

it('(b2) synced, encryption off: shows the transient mandatory "Securing your data…" state with NO CTA and NO decline', function (): void {
    $user = encryptionUiUser('encryption-ui-mandatory');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', true)
        ->set('encryptionOn', false)
        ->assertSee('encryption-securing-notice', escape: false)
        ->assertSee('Securing your data…')
        ->assertDontSee('encryption-offer-notice', escape: false)
        ->assertDontSee('enable-encryption-cta', escape: false)
        ->assertDontSee('Keep data unencrypted')
        ->assertDontSee('Enable encryption');
});

it('(c) encryption on: shows "Data encrypted at rest" + the On badge, and hides the enable CTA', function (): void {
    $user = encryptionUiUser('encryption-ui-on');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', true)
        ->set('encryptionOn', true)
        ->assertSee('Data encrypted at rest')
        ->assertSee('Your data is secured with your app-lock passphrase.')
        ->assertSee('On')
        ->assertDontSee('enable-encryption-cta', escape: false)
        ->assertDontSee('encryption-securing-notice', escape: false)
        ->assertDontSee('encryption-offer-notice', escape: false);
});

it('the enable-encryption confirm step discloses amounts + search-index plaintext honestly, with no forbidden overstating phrases', function (): void {
    $user = encryptionUiUser('encryption-ui-honest-enable');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', false)
        ->set('encryptionOn', false)
        ->call('showEnableEncryptionModal')
        ->assertSee('Amounts are not encrypted at rest')
        ->assertSee('The search index keeps a plaintext copy of merchant and description text')
        ->assertSee('your data cannot be recovered')
        ->assertDontSee('remote wipe')
        ->assertDontSee("the other device's data is deleted")
        ->assertDontSee('your data is now safe from that device');
});

it('(d) startRemove opens the revocation modal for a non-self device with copy that promises key rotation, never a remote wipe', function (): void {
    $user = encryptionUiUser('encryption-ui-remove-open');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $selfId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'self-device', 'This Mac', isSelf: true));
    $peerId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'peer-device', "Wessel's iPhone", isSelf: false));

    $component = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->set('devices', [
            deviceViewRow($selfId, 'This Mac', isSelf: true),
            deviceViewRow($peerId, "Wessel's iPhone", isSelf: false),
        ])
        ->assertSee('remove-device-'.$peerId, escape: false)
        ->assertDontSee('remove-device-'.$selfId, escape: false);

    $component->call('startRemove', $peerId)
        ->assertSet('removingDeviceId', $peerId)
        ->assertSet('showRemoveModal', true)
        ->assertSee('revoke-device-modal', escape: false)
        ->assertSee('Removing: '."Wessel's iPhone")
        ->assertSee('Removing this device rotates the encryption key so it receives no future updates.')
        ->assertSee('It cannot erase data already on that device.')
        ->assertDontSee('remote wipe')
        ->assertDontSee("the other device's data is deleted")
        ->assertDontSee('your data is now safe from that device');
});

it('(e) removeDevice revokes device_registry trust (rotateAndRevoke) and the row shows the Removed badge', function (): void {
    $user = encryptionUiUser('encryption-ui-remove-confirm');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // The acting device needs a real on-disk identity: removal rotates, and the
    // rotation loads it to sign the fan-out wraps.
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $self = $identityService->generateAndPersist((int) $user->id, $session);
    $selfId = (int) $db->connection()->table('device_registry')->where('device_id', $self->deviceId)->value('id');
    $peerId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'peer-device-2', 'Old Laptop', isSelf: false));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->set('devices', [
            deviceViewRow($selfId, 'This Mac', isSelf: true),
            deviceViewRow($peerId, 'Old Laptop', isSelf: false),
        ])
        ->call('startRemove', $peerId)
        ->call('removeDevice')
        ->assertSet('removingDeviceId', null)
        ->assertSet('showRemoveModal', false)
        ->assertSee('removed-badge-'.$peerId, escape: false)
        ->assertSee('Removed');

    // Clearing confirmed_at is what actually closes the trust gate: every
    // confirmed-device query already filters on it.
    $confirmedAt = $db->connection()->table('device_registry')->where('id', $peerId)->value('confirmed_at');
    expect($confirmedAt)->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function deviceRegistryRow(int $userId, string $deviceId, string $name, bool $isSelf): array
{
    return [
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $name,
        'ed25519_public_key_hex' => str_repeat($isSelf ? 'a' : 'c', 64),
        'x25519_public_key_hex' => str_repeat($isSelf ? 'b' : 'd', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ];
}

/**
 * @return array<string, mixed>
 */
function deviceViewRow(int $id, string $name, bool $isSelf): array
{
    return [
        'id' => $id,
        'name' => $name,
        'safety_number_words' => 'abandon ability able about above absent',
        'paired_at' => '2026-07-09T10:00:00Z',
        'is_self' => $isSelf,
        'confirmed' => true,
        'removed' => false,
    ];
}
