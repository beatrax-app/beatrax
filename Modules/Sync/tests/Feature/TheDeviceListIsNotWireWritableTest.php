<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

// The device row subscripts seven keys off each entry and parses one of them as
// a date. Its neighbour on the same page — the peer list in
// sync-status-section.blade.php — narrows every field it reads and survives the
// identical payload; this one had neither a narrowing pass nor a lock.

function deviceListSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"sync.devices-and-sync-settings-section"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the devices section on /data-devices.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function deviceListTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'device-list',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->snapshot = deviceListSnapshot($this->get('/data-devices')->assertOk()->getContent());
});

it('refuses a device that is not a device however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    deviceListTamper($this->snapshot, ['syncEnabled' => true, 'devices' => ['x']])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

// A relay served over plain HTTP is the one thing the section warns about, and
// the warning is behind a boolean the server computes from the URL it stored.
it('refuses a payload that turns the insecure-relay warning off', function (): void {
    deviceListTamper($this->snapshot, ['relayIsInsecure' => false])->assertForbidden();
});

it('leaves the relay URL box the reader types into writable', function (): void {
    deviceListTamper($this->snapshot, ['relayEndpointUrl' => 'https://relay.example.com'])->assertOk();
});

it('throws rather than accepting a write to the device list', function (): void {
    Livewire::test(DevicesAndSyncSettingsSection::class)->set('devices', ['x']);
})->throws(CannotUpdateLockedPropertyException::class);

// The neighbour that already survived it, kept in the same file so the two
// answers cannot drift apart again without one of them failing here.
it('leaves the peer list beside it answering the same payload', function (): void {
    preg_match_all('/wire:snapshot="([^"]*)"/', $this->get('/data-devices')->assertOk()->getContent(), $matches);

    $peerSnapshot = null;
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"sync.sync-status-section"')) {
            $peerSnapshot = $snapshot;
        }
    }

    expect($peerSnapshot)->not->toBeNull();

    deviceListTamper((string) $peerSnapshot, ['peerStatuses' => ['x']])->assertOk();
});

// startRemove() is the only writer of the removal target: the id removeDevice()
// rotates and revokes on has to be the one the reader pressed beside, not one a
// replayed snapshot named for the whole of the action.
it('throws rather than accepting a write to the device a removal targets', function (): void {
    Livewire::test(DevicesAndSyncSettingsSection::class)->set('removingDeviceId', 1);
})->throws(CannotUpdateLockedPropertyException::class);
