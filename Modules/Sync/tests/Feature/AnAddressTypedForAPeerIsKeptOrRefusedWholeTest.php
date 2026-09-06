<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Services\PeerLanAddressBook;

uses(RefreshDatabase::class);

// The field stores what the dial will later build a `ws://` around, so a
// scheme, a path or a missing port typed here becomes a string nothing can
// parse back out. Refused whole and said so, rather than stored and dialled.

function typedAddressUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('typed-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function typedAddressPeer(int $userId, string $deviceId): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-06-01 00:00:00',
        'confirmed_at' => '2026-06-01 00:00:00',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

it('stores a host and port a reader typed, and offers it back as one field', function (): void {
    $user = typedAddressUser('typed-ok');
    typedAddressPeer((int) $user->id, 'device-desktop-typed');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('manualPeerAddress', ' 192.168.1.20:8100 ')
        ->call('saveManualPeerAddress')
        ->assertSet('manualPeerAddress', '192.168.1.20:8100')
        ->assertSet('manualPeerFlashMessage', 'Device address saved.');

    expect(app(PeerLanAddressBook::class)->manual((int) $user->id, 'device-desktop-typed'))
        ->toBe(['host' => '192.168.1.20', 'port' => 8100]);
});

it('refuses an address the dial could not build a socket from', function (string $typed): void {
    $user = typedAddressUser('typed-bad-'.substr(md5($typed), 0, 6));
    typedAddressPeer((int) $user->id, 'device-desktop-typed');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('manualPeerAddress', $typed)
        ->call('saveManualPeerAddress')
        ->assertSet('manualPeerFlashMessage', 'Enter an address as host and port, for example 192.168.1.20:8100.');

    expect(app(PeerLanAddressBook::class)->manual((int) $user->id, 'device-desktop-typed'))->toBeNull();
})->with([
    'a bare host with no port' => '192.168.1.20',
    'a URL rather than an address' => 'ws://192.168.1.20:8100',
    'a path glued to the port' => '192.168.1.20:8100/sync',
    'a port out of range' => '192.168.1.20:70000',
    'a port that is not a number' => '192.168.1.20:sync',
    'a port with nothing before it' => ':8100',
    'a host with a space in it' => '192.168.1 20:8100',
]);

it('takes the rung back out when the field is emptied', function (): void {
    $user = typedAddressUser('typed-cleared');
    typedAddressPeer((int) $user->id, 'device-desktop-typed');
    $this->actingAs($user);

    app(PeerLanAddressBook::class)->setManual((int) $user->id, 'device-desktop-typed', '10.1.2.3', 8100);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('manualPeerAddress', '')
        ->call('saveManualPeerAddress')
        ->assertSet('manualPeerFlashMessage', 'Device address cleared.');

    expect(app(PeerLanAddressBook::class)->manual((int) $user->id, 'device-desktop-typed'))->toBeNull();
});

it('says there is no peer rather than storing an address nothing would dial', function (): void {
    $user = typedAddressUser('typed-no-peer');
    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->set('manualPeerAddress', '192.168.1.20:8100')
        ->call('saveManualPeerAddress')
        ->assertSet('manualPeerFlashMessage', 'No other device is paired yet.');
});
