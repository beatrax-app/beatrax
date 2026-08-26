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

// This file asserted the opposite, and that inversion IS the round-6 bug: the
// dispatch was skipped whenever a handshake looked live, but showMyCode() mints
// a `pending` row, so showing a code made the next open unable to credential the
// daemon at all.

// The protection it gave is real and is kept — it moved to the only seam that
// knows which identity the running daemon holds, and so can tell a restart that
// changes something from one that destroys a ceremony for nothing:
// Modules/Desktop/tests/Feature/SyncListenerIsCredentialledOnUnlockTest.php.

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

// The case that broke four rounds: a code was showing, so a handshake WAS live,
// and the daemon holding no keypair is exactly why nothing answered it.
it('readies the listener while a code it showed is still live', function (): void {
    listenerModalRow((int) $this->user->id, PairingState::Pending->value, CarbonImmutable::now()->addMinutes(9));

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertDispatched(SyncTransportCredentialsAvailable::class);
});

it('readies the listener when reopening on a handshake waiting to be confirmed', function (): void {
    listenerModalRow((int) $this->user->id, PairingState::AwaitingConfirm->value, CarbonImmutable::now()->addMinutes(9));

    Livewire::test(PairingFlowModal::class)->call('openModal');

    Event::assertDispatched(SyncTransportCredentialsAvailable::class);
});

it('readies the listener when there is no ceremony at all', function (): void {
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
