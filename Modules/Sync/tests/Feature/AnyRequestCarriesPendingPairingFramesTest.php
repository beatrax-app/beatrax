<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Clock\ZuluTimestamp;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;

uses(RefreshDatabase::class);

// A phone runs no daemon, no queue worker and no scheduler, so the courier's
// only driver over there is the request cycle. Wiring it is not the same as it
// running: this drives an ORDINARY page — nothing to do with pairing — and
// asserts a confirm went out because of it.

const REQ_PEER_DEVICE_ID = '9f1c2b3a-4d5e-4f60-8a71-b2c3d4e5f607';

function reqUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('carries-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function reqNoPeersAndNoRelay(): void
{
    app()->instance(PeerDiscovery::class, new class implements PeerDiscovery
    {
        public function reach(): LanDiscoveryReach
        {
            return LanDiscoveryReach::Available;
        }

        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return [];
        }
    });

    // Nothing answers, so an outbound confirm lands in the local holding space
    // — which is what makes "did a request send one" countable at all.
    Http::fake(['*' => Http::response('', 503)]);
}

function reqQueuedFrames(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('relay_mailbox')->count();
}

// A ceremony this device has already tapped through, waiting on a peer that
// never acknowledged. Confirmed locally, not confirmed by the peer.
function reqAwaitingPeer(int $userId, DeviceIdentityDto $self): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = ZuluTimestamp::stamp(now()->toImmutable());

    $db->connection()->table('pairing_tokens')->insert([
        'user_id' => $userId,
        'token_hash' => hash('sha256', 'carries-token'),
        'initiator_device_id' => $self->deviceId,
        'initiator_ed25519_pub_hex' => $self->ed25519PublicKeyHex,
        'initiator_x25519_pub_hex' => $self->x25519PublicKeyHex,
        'responder_device_id' => REQ_PEER_DEVICE_ID,
        'responder_ed25519_pub_hex' => str_repeat('c', 64),
        'responder_x25519_pub_hex' => str_repeat('d', 64),
        'state' => PairingState::AwaitingConfirm->value,
        'expires_at' => ZuluTimestamp::stamp(now()->addMinutes(5)->toImmutable()),
        'accepted_at' => $now,
        'initiator_confirmed_at' => $now,
        'created_at' => $now,
    ]);
}

it('re-emits this device\'s confirm from an ordinary page request, with no pairing screen anywhere', function (): void {
    $user = reqUser('carries-request');
    $this->actingAs($user);
    reqNoPeersAndNoRelay();

    /** @var Session $session */
    $session = app(Session::class);
    $identity = app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    reqAwaitingPeer((int) $user->id, $identity);

    expect(reqQueuedFrames())->toBe(0);

    $this->get('/notifications')->assertOk();

    expect(reqQueuedFrames())->toBeGreaterThan(0);
});

it('carries nothing on a request made while no ceremony is open', function (): void {
    $user = reqUser('carries-idle');
    $this->actingAs($user);
    reqNoPeersAndNoRelay();

    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $this->get('/notifications')->assertOk();

    expect(reqQueuedFrames())->toBe(0);
});
