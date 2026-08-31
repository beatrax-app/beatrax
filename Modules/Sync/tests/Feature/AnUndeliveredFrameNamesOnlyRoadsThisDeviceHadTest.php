<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

// "Check that both are on the same network" is advice only where a road
// existed to try. On an iPhone — which cannot browse, because iOS grants no
// multicast entitlement — holding a code that named no address and no relay,
// it sent the reader to fix a network that was never the reason.

const NO_ROAD_DESKTOP_DID = 'desktop-out-there';

const NO_ROAD_TOKEN_HASH = 'no-road-token-hash';

function noRoadDiscovery(bool $canLook): void
{
    app()->instance(PeerDiscovery::class, new class($canLook) implements PeerDiscovery
    {
        public function __construct(private readonly bool $canLook) {}

        public function reach(): LanDiscoveryReach
        {
            return $this->canLook ? LanDiscoveryReach::Available : LanDiscoveryReach::Unsupported;
        }

        /**
         * @return list<DiscoveredPeer>
         */
        public function browse(string $serviceType, float $timeoutSeconds = 2.0): array
        {
            return [];
        }
    });
}

function noRoadToken(?string $host, ?int $port): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('pairing_tokens')->insert([
        'user_id' => 1,
        'token_hash' => NO_ROAD_TOKEN_HASH,
        'initiator_device_id' => NO_ROAD_DESKTOP_DID,
        'initiator_ed25519_pub_hex' => str_repeat('c', 64),
        'initiator_x25519_pub_hex' => str_repeat('d', 64),
        'state' => 'pending',
        'expires_at' => '2026-12-01T00:00:00Z',
        'created_at' => '2026-08-31T00:00:00Z',
        'initiator_lan_host' => $host,
        'initiator_lan_port' => $port,
    ]);
}

beforeEach(function (): void {
    // The out-of-box state this whole case is about, asserted rather than
    // assumed: a relay.json left in the tree would make every road exist.
    expect(app(RelayConfig::class)->isConfigured())->toBeFalse();
});

it('reports no road when it cannot look, holds no relay, and was given no address', function (): void {
    noRoadDiscovery(canLook: false);
    noRoadToken(null, null);

    expect(app(PairingGateway::class)->hadAnyRoadTo(NO_ROAD_TOKEN_HASH, NO_ROAD_DESKTOP_DID))->toBeFalse();
});

it('counts the scanned address as a road even where nothing can be browsed for', function (): void {
    noRoadDiscovery(canLook: false);
    noRoadToken('192.168.178.119', 51337);

    // This is the whole point of putting the address in the code: the phone
    // has somewhere to send, so a failure really is about the network.
    expect(app(PairingGateway::class)->hadAnyRoadTo(NO_ROAD_TOKEN_HASH, NO_ROAD_DESKTOP_DID))->toBeTrue();
});

it('counts a browse this device can actually run as a road', function (): void {
    noRoadDiscovery(canLook: true);
    noRoadToken(null, null);

    // An empty browse on a device that CAN browse is an answer about the
    // network, so naming the network is honest here.
    expect(app(PairingGateway::class)->hadAnyRoadTo(NO_ROAD_TOKEN_HASH, NO_ROAD_DESKTOP_DID))->toBeTrue();
});

it('reports no road for a token it has never heard of', function (): void {
    noRoadDiscovery(canLook: false);

    expect(app(PairingGateway::class)->hadAnyRoadTo('a-hash-no-row-carries', NO_ROAD_DESKTOP_DID))->toBeFalse();
});
