<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

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
        ->assertSee('Your data is not encrypted at rest. Encryption hides who you pay if this device is lost or stolen')
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
        ->assertSee('Notes, transaction descriptions and the names and IBANs of who you pay are encrypted')
        ->assertSee('On')
        ->assertDontSee('enable-encryption-cta', escape: false)
        ->assertDontSee('encryption-securing-notice', escape: false)
        ->assertDontSee('encryption-offer-notice', escape: false);
});

// The status row is the surface an at-rest audit caught lying: it read "Your
// data is secured with your app-lock passphrase" beside a database file whose
// accounts.iban, accounts.name and both slug columns are readable with no key,
// and whose search index keeps merchant names in the clear. A reader deciding
// whether their merchant history is safe reads THIS row, not the enable modal
// they passed through once, so the same disclosure has to hold here.
it('the encryption-on status row names what at-rest encryption does not cover, and never restates the unqualified promise', function (): void {
    $user = encryptionUiUser('encryption-ui-honest-status');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', true)
        ->set('encryptionOn', true)
        ->assertSee('Amounts, dates and your own account name and IBAN are not')
        ->assertSee('some merchant names still appear in plain text elsewhere in the database file')
        ->assertDontSee('Your data is secured with your app-lock passphrase.');
});

it('offers the honest scope line in every locale, with the unqualified promise retired everywhere', function (): void {
    $root = dirname(__DIR__, 2).'/Resources/lang';
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $entry): bool => ! str_starts_with($entry, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    $stale = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $devices */
        $devices = require $root.'/'.$locale.'/devices.php';

        $scope = $devices['encrypted_at_rest_scope'] ?? null;
        if (! is_string($scope) || $scope === '') {
            $missing[] = $locale;
        }
        if (array_key_exists('encrypted_at_rest_help', $devices)) {
            $stale[] = $locale;
        }
    }

    expect($missing)->toBe([]);
    expect($stale)->toBe([]);
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

it('(d2) says the removal is local when another device is still here to keep the removed one', function (): void {
    // Only the ROTATION half of a removal reaches the household. Clearing the
    // peer's confirmed_at happens here alone, so a third device goes on
    // admitting and feeding the removed one until it is removed there too.
    $user = encryptionUiUser('encryption-ui-remove-local');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $selfId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'self-device-3', 'This Mac', isSelf: true));
    $peerId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'peer-device-3', "Wessel's iPhone", isSelf: false));
    $otherId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'peer-device-4', 'Study laptop', isSelf: false));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->call('startRemove', $peerId)
        ->assertSee('Your other devices keep their own list.')
        ->assertSee('they will go on syncing with it');
});

it('(d3) stays silent about other devices when this removal leaves none', function (): void {
    // A warning about devices that are not there reads as a warning about the
    // one being removed, which is the opposite of what it says.
    $user = encryptionUiUser('encryption-ui-remove-last');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $selfId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'self-device-4', 'This Mac', isSelf: true));
    $peerId = $db->connection()->table('device_registry')->insertGetId(deviceRegistryRow($user->id, 'peer-device-5', "Wessel's iPhone", isSelf: false));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('syncEnabled', true)
        ->call('startRemove', $peerId)
        ->assertSee('revoke-device-modal', escape: false)
        ->assertDontSee('Your other devices keep their own list.');
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

// The status row is the surface a reader returns to; the enable modal is read
// once, on the way in. SearchIndexWriter decrypts sealed columns straight into
// transaction_search_docs.search_body, so the field list below is derived from
// that file rather than restated here: a column added to search_body with no
// matching disclosure fails this test instead of shipping as a quiet
// under-disclosure.
it('the encryption-on status row names the search index and every column SearchIndexWriter leaves in the clear', function (): void {
    $writerPath = dirname(__DIR__, 3).'/Search/Internal/Services/SearchIndexWriter.php';
    expect(is_file($writerPath))->toBeTrue();

    $source = file_get_contents($writerPath);
    expect($source)->toBeString();

    $matches = [];
    preg_match_all("/decryptValue\('([a-z_]+)', '([a-z_]+)'/", is_string($source) ? $source : '', $matches, PREG_SET_ORDER);

    $indexedInTheClear = [];
    foreach ($matches as $match) {
        $indexedInTheClear[] = $match[1].'.'.$match[2];
    }
    $indexedInTheClear = array_values(array_unique($indexedInTheClear));
    sort($indexedInTheClear);

    // Each column the writer decrypts, paired with the words the permanent
    // status row has to spend on it. A new column breaks the first
    // expectation; a softened sentence breaks the second.
    $disclosures = [
        'tax_transaction_tags.note' => 'tax notes',
        'transactions.counterparty_name' => 'who you pay',
        'transactions.description' => 'transaction descriptions',
    ];

    expect($indexedInTheClear)->toBe(array_keys($disclosures));

    $user = encryptionUiUser('encryption-ui-search-index');
    $this->actingAs($user);

    $component = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', true)
        ->set('encryptionOn', true)
        ->assertSee('search index');

    foreach ($disclosures as $phrase) {
        $component->assertSee($phrase);
    }
});

// Shown one screen after the modal that just disclosed two carve-outs, and it
// is the last sentence the reader takes away. Retired as a KEY rather than
// reworded, exactly as encrypted_at_rest_help was, so no locale can be left
// rendering the unqualified promise while its translation lands.
it('the enable-encryption done step repeats the carve-out instead of restating an unqualified promise', function (): void {
    $user = encryptionUiUser('encryption-ui-done-scope');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', false)
        ->set('encryptionOn', false)
        ->set('showEncryptionModal', true)
        ->set('encryptionStep', 'done')
        ->assertSee('Encryption enabled')
        ->assertSee('Amounts, dates and the search index stay readable')
        ->assertDontSee('Your data is now encrypted at rest.');
});

// $encryptionStep carries no #[Locked], so the client decides what arrives in
// it. Typing it as a backed enum would make a crafted value a 500 rather than
// a harmless fallback, which is why the property stays a string.
it('shows the confirm step when an encryption step outside the modal arrives from the wire', function (): void {
    $user = encryptionUiUser('encryption-ui-bogus-step');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', false)
        ->set('encryptionOn', false)
        ->set('showEncryptionModal', true)
        ->set('encryptionStep', 'not-a-step')
        ->assertSet('encryptionStep', 'not-a-step')
        ->assertSee('Enable at-rest encryption')
        ->assertDontSee('Encryption setup failed');
});

it('retires the unqualified done-step promise in every locale and replaces it with the scoped one', function (): void {
    $root = dirname(__DIR__, 2).'/Resources/lang';
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $entry): bool => ! str_starts_with($entry, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    $stale = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $devices */
        $devices = require $root.'/'.$locale.'/devices.php';

        $scope = $devices['encryption_enabled_scope'] ?? null;
        if (! is_string($scope) || $scope === '') {
            $missing[] = $locale;
        }
        if (array_key_exists('encryption_enabled_body', $devices)) {
            $stale[] = $locale;
        }
    }

    expect($missing)->toBe([]);
    expect($stale)->toBe([]);
});

// Encryption is one-way and its snapshot is deleted on success, so the app-lock
// data key can never be replaced afterwards and AppLockProvisioner::disable()
// refuses for the life of the install. The reader agrees to that here, not at
// the disable button that later says no.
it('the enable-encryption confirm step says the app lock becomes permanent, before the irreversible step', function (): void {
    $user = encryptionUiUser('encryption-ui-permanent-lock');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', true)
        ->set('syncEnabled', false)
        ->set('encryptionOn', false)
        ->call('showEnableEncryptionModal')
        ->assertSee('the app lock can no longer be turned off');
});

// Sync auto-activates encryption with no confirm step at all, so the toggle's
// own description is the only place its reader is told.
it('states the same permanence in the sync toggle description, which has no confirm step of its own', function (): void {
    $this->actingAs(encryptionUiUser('encryption-ui-permanent-sync'));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSee('the app lock can no longer be turned off');
});
