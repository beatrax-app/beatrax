<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Clock\ZuluTimestamp;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Public\Events\SyncTransportCredentialsAvailable;

uses(RefreshDatabase::class);

// The event replaces the running sync:serve process, which is the only way it
// can be handed a keypair. Opening the modal fired it unconditionally, so the
// reopen the ceremony asks for killed the daemon serving the handshake.

function listenerModalUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function listenerModalRow(int $userId, string $state, CarbonImmutable $expiresAt): void
{
    DB::table('pairing_tokens')->insert([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'listener-'.$userId.'-'.$state),
        'initiator_device_id' => 'this-desktop',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'state' => $state,
        'expires_at' => ZuluTimestamp::stamp($expiresAt),
        'created_at' => ZuluTimestamp::stamp(CarbonImmutable::now()),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = listenerModalUser('listener-modal-user');
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    Event::fake([SyncTransportCredentialsAvailable::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not restart the listener when a code it showed is still live and the peer may be dialling it', function (): void {
    listenerModalRow((int) $this->user->id, PairingState::Pending->value, CarbonImmutable::now()->addMinutes(9));

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertNotDispatched(SyncTransportCredentialsAvailable::class);
});

it('does not restart the listener when reopening on a handshake waiting to be confirmed', function (): void {
    listenerModalRow((int) $this->user->id, PairingState::AwaitingConfirm->value, CarbonImmutable::now()->addMinutes(9));

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertNotDispatched(SyncTransportCredentialsAvailable::class);
});

it('still readies the listener when there is no ceremony a restart could destroy', function (): void {
    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertDispatched(SyncTransportCredentialsAvailable::class);
});

it('readies the listener when the only handshake has run out of time', function (): void {
    listenerModalRow((int) $this->user->id, PairingState::Pending->value, CarbonImmutable::now()->subMinute());

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertDispatched(SyncTransportCredentialsAvailable::class);
});

it('readies the listener when another account is the one mid-ceremony', function (): void {
    $other = listenerModalUser('listener-modal-other');
    listenerModalRow((int) $other->id, PairingState::Pending->value, CarbonImmutable::now()->addMinutes(9));

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertDispatched(SyncTransportCredentialsAvailable::class);
});
