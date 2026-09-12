<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

function devicesSyncSettingsUser(string $username = 'devices-settings-user'): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('devices-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // RefreshDatabase resets the database and not the filesystem, and user ids
    // are reused between tests, so a key-file minted by an earlier one would
    // answer for this user and put the section in a state it never asked for.
    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath("sync/{$directory}/{$user->id}.enc*")) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
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

// enableSync() is the only action the component has: there is no disable path,
// so a track still rendering as an enabled role="switch" with aria-checked=true
// announced a flip that pressing it does not perform, and reported nothing.
it('stops presenting the sync track as flippable once there is no way back', function (): void {
    $user = devicesSyncSettingsUser('devices-switch-locked');
    $this->actingAs($user);

    $off = Livewire::test(DevicesAndSyncSettingsSection::class)->html();
    $offTrack = syncSwitchMarkup($off);

    expect($offTrack)->toContain('aria-checked="false"')
        ->and($offTrack)->toContain('disabled')
        ->and($offTrack)->toContain('aria-disabled="true"');

    anEnabledSyncIdentity((int) $user->id);

    $on = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncEnabled', true)
        ->html();
    $onTrack = syncSwitchMarkup($on);

    expect($onTrack)->toContain('aria-checked="true"')
        ->and($onTrack)->toContain('disabled')
        ->and($onTrack)->toContain('aria-disabled="true"');
});

// pointer-events-none is what a not-allowed cursor is drawn against, so the
// dimming it carried was the one thing on the row a pointer could never see.
it('leaves the not-allowed cursor something to render against', function (): void {
    $this->actingAs(devicesSyncSettingsUser('devices-switch-cursor'));

    expect(syncSwitchMarkup(Livewire::test(DevicesAndSyncSettingsSection::class)->html()))
        ->not->toContain('pointer-events-none')
        ->toContain('disabled:cursor-not-allowed');
});

// The opening tag of the enable-sync track alone. Asserting against the whole
// page would let a neighbouring control's disabled attribute answer for it.
function syncSwitchMarkup(string $html): string
{
    $label = strpos($html, 'aria-label="Enable sync"');
    expect($label)->not->toBeFalse();

    /** @var int $label */
    $start = strrpos(substr($html, 0, $label), '<button');
    expect($start)->not->toBeFalse();

    /** @var int $start */
    $end = strpos($html, '>', $label);
    expect($end)->not->toBeFalse();

    /** @var int $end */
    return substr($html, $start, $end - $start + 1);
}

// The real mint, not a seeded row: a self row with no key-file beside it is a
// restored database, which reads as sync being off here and names itself, and
// pinning the enabled track against it pinned the defect.
function anEnabledSyncIdentity(int $userId): void
{
    /** @var Session $session */
    $session = app(Session::class);

    app(DeviceIdentityService::class)->generateAndPersist($userId, $session);
}
