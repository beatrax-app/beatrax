<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

function devicesSyncSettingsUser(string $username = 'devices-settings-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('devices-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('mounts with a 200 status for an authenticated user', function (): void {
    $user = devicesSyncSettingsUser();
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertStatus(200);
});

it('blocks enable-sync with the app-lock gate copy when no app-lock is configured', function (): void {
    $user = devicesSyncSettingsUser('devices-nolock');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('enableSync')
        ->assertSet('flashMessage', 'Set an app lock first to enable sync.');
});

it('it_can_rename_a_device', function (): void {
    $user = devicesSyncSettingsUser('devices-rename');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $deviceRowId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'device-rename',
        'name' => 'Old Name',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-06-15T10:00:00Z',
        'confirmed_at' => '2026-06-15T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-06-15T10:00:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('renameDevice', $deviceRowId, 'New Name')
        ->assertHasNoErrors();

    $name = $db->connection()->table('device_registry')
        ->where('id', $deviceRowId)
        ->where('user_id', $user->id)
        ->value('name');

    expect($name)->toBe('New Name');
});

it('refreshes the enable-sync gate live when an app-lock-configured event arrives', function (): void {
    // mount() computes the app-lock flag once, so without the listener the
    // "set an app lock first" gate stayed up until a manual page reload even
    // after the sibling section had just configured one.
    $user = devicesSyncSettingsUser('devices-gate-refresh');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $component = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('enableSync')
        ->assertSet('appLockConfigured', false)
        ->assertSet('flashMessage', 'Set an app lock first to enable sync.');

    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-06-15T10:00:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);

    $component->dispatch('app-lock-configured')
        ->assertSet('appLockConfigured', true)
        ->assertSet('flashMessage', '');
});

it('opens the hosted pairing modal when the open-pairing-modal event is dispatched', function (): void {
    // The modal renders unconditionally and opens on an event, so Flux sees a
    // real false-to-true transition. Mounted already-open behind a conditional
    // it never fired, and the pairing button appeared to do nothing.
    $user = devicesSyncSettingsUser('devices-modal-open');
    $this->actingAs($user);

    Livewire::test(PairingFlowModal::class)
        ->assertSet('open', false)
        ->dispatch('open-pairing-modal')
        ->assertSet('open', true)
        ->assertSet('step', 'choose_direction');
});

it('flags an http:// relay endpoint as insecure and renders the warning, https:// as secure', function (): void {
    $user = devicesSyncSettingsUser('devices-relay-insecure');
    $this->actingAs($user);

    $relayPath = UserDataPathService::appPath('sync/relay.json');

    try {
        // The relay field and its warning render only once sync is enabled.
        Livewire::test(DevicesAndSyncSettingsSection::class)
            ->set('appLockConfigured', true)
            ->set('syncEnabled', true)
            ->set('relayEndpointUrl', 'http://relay.example.com')
            ->call('saveRelayEndpoint')
            ->assertSet('relayIsInsecure', true)
            ->assertSet('relayFlashMessage', 'Relay endpoint saved.')
            ->assertSee('relay-insecure-warning', escape: false)
            ->assertSee('uses plain HTTP');

        Livewire::test(DevicesAndSyncSettingsSection::class)
            ->set('appLockConfigured', true)
            ->set('syncEnabled', true)
            ->set('relayEndpointUrl', 'https://relay.example.com')
            ->call('saveRelayEndpoint')
            ->assertSet('relayIsInsecure', false)
            ->assertSet('relayFlashMessage', 'Relay endpoint saved.')
            ->assertDontSee('relay-insecure-warning', escape: false);

        Livewire::test(DevicesAndSyncSettingsSection::class)
            ->set('appLockConfigured', true)
            ->set('relayEndpointUrl', '')
            ->call('saveRelayEndpoint')
            ->assertSet('relayIsInsecure', false)
            ->assertSet('relayFlashMessage', 'Relay endpoint cleared.');
    } finally {
        @unlink($relayPath);
    }
});

it('blocks a relay-endpoint save behind the app-lock gate', function (): void {
    $user = devicesSyncSettingsUser('devices-relay-gate');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('appLockConfigured', false)
        ->set('relayEndpointUrl', 'http://relay.example.com')
        ->call('saveRelayEndpoint')
        ->assertSet('relayFlashMessage', 'Set an app lock first to change sync settings.')
        ->assertSet('relayIsInsecure', false);
});
