<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Instant;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// android-07: the responder phone waits on the safety-number screen and nothing
// ever tells it the ceremony is over. Nothing writes `expired` to the row on TTL
// lapse either — prune() deletes and only runs when a NEW token is minted, which
// the waiting device never does — so the lapse has to be derived at read time,
// exactly as hasLiveHandshake() and inFlight() already derive it.

function ttlUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return int the pairing_tokens id
 */
function ttlRow(int $userId, string $state, CarbonImmutable $expiresAt, ?string $responderDeviceId = null): int
{
    return (int) DB::table('pairing_tokens')->insertGetId([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'ttl-'.$userId.'-'.$state.'-'.$expiresAt->toIso8601ZuluString()),
        'initiator_device_id' => 'ttl-desktop',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'responder_device_id' => $responderDeviceId,
        'responder_ed25519_pub_hex' => $responderDeviceId === null ? null : str_repeat('c', 64),
        'responder_x25519_pub_hex' => $responderDeviceId === null ? null : str_repeat('d', 64),
        'state' => $state,
        'expires_at' => Instant::zulu($expiresAt),
        'responder_confirmed_at' => Instant::zulu(CarbonImmutable::now()),
        'created_at' => Instant::zulu(CarbonImmutable::now()),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = ttlUser('pairing-ttl-user');
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports a handshake whose TTL has lapsed as expired, though nothing wrote that to the row', function (): void {
    $tokenId = ttlRow((int) $this->user->id, PairingState::AwaitingConfirm->value, CarbonImmutable::now()->addMinutes(10));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

    expect(app(PairingGateway::class)->tokenState($tokenId, (int) $this->user->id))
        ->toBe(PairingState::Expired->value);

    // And the row itself is untouched — this is a read-time derivation, not a
    // write the waiting device had no opportunity to make.
    expect(DB::table('pairing_tokens')->where('id', $tokenId)->value('state'))
        ->toBe(PairingState::AwaitingConfirm->value);
});

it('reports a pending code whose TTL has lapsed as expired', function (): void {
    $tokenId = ttlRow((int) $this->user->id, PairingState::Pending->value, CarbonImmutable::now()->addMinutes(10));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

    expect(app(PairingGateway::class)->tokenState($tokenId, (int) $this->user->id))
        ->toBe(PairingState::Expired->value);
});

it('leaves a handshake still inside its TTL alone', function (): void {
    $tokenId = ttlRow((int) $this->user->id, PairingState::AwaitingConfirm->value, CarbonImmutable::now()->addMinutes(10));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(9));

    expect(app(PairingGateway::class)->tokenState($tokenId, (int) $this->user->id))
        ->toBe(PairingState::AwaitingConfirm->value);
});

// The regression the naive "expires_at is past => expired" guard would cause: a
// phone backgrounded across the TTL boundary comes back to a ceremony that DID
// complete, and would be told its code was invalid. confirm() and
// PeerConfirmVerifier both refuse past the TTL, so a confirmed row past its TTL
// confirmed while it was live and its trust is already in device_registry.
it('does not un-confirm a finished ceremony whose row has since run out of time', function (): void {
    $tokenId = ttlRow((int) $this->user->id, PairingState::Confirmed->value, CarbonImmutable::now()->addMinutes(10));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

    expect(app(PairingGateway::class)->tokenState($tokenId, (int) $this->user->id))
        ->toBe(PairingState::Confirmed->value);
});

it('leaves the waiting phone a way off the safety-number screen once the token has run out', function (): void {
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));
    $identity = app(DeviceIdentityService::class)->generateAndPersist((int) $this->user->id, $session);

    ttlRow(
        (int) $this->user->id,
        PairingState::AwaitingConfirm->value,
        CarbonImmutable::now()->addMinutes(10),
        $identity->deviceId,
    );

    $component = Livewire::test(MobilePairingScan::class)
        ->assertSet('step', 'confirm')
        ->assertSet('awaitingPeer', true);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

    $component->call('checkPairingState')
        ->assertSet('awaitingPeer', false)
        ->assertNotSet('step', 'confirm')
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.invalid_code'));
});
