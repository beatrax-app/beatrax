<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Modules\Sync\Internal\Transport\Discovery\BonjourBridgeQuery;
use Modules\Sync\Internal\Transport\Discovery\CachedPeerDiscovery;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\Discovery\MulticastMdnsQuery;
use Modules\Sync\Internal\Transport\Discovery\NativeBridge;
use Modules\Sync\Internal\Transport\Discovery\PeerDiscovery;
use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Services\PairingGateway;

// An empty browse used to be one answer covering two opposite situations:
// nobody advertising the service, and a platform that never got to ask. The
// pairing screen could only pick one line for both, and on an iPhone — which
// never asks — it picked the one that blamed the reader's network.

beforeEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM'], $_ENV['NATIVEPHP_PLATFORM']);
    putenv('NATIVEPHP_PLATFORM');
});

/**
 * @param  list<array<string, mixed>>|null  $peers
 */
function bridgeAnswering(bool $supports, ?array $peers, bool $refuses = false): NativeBridge
{
    return new class($supports, $peers, $refuses) implements NativeBridge
    {
        /**
         * @param  list<array<string, mixed>>|null  $peers
         */
        public function __construct(
            private readonly bool $supports,
            private readonly ?array $peers,
            private readonly bool $refuses,
        ) {}

        public function supports(string $function): bool
        {
            return $this->supports;
        }

        /**
         * @param  array<string, scalar>  $parameters
         * @return array<mixed>|null
         */
        public function call(string $function, array $parameters): ?array
        {
            if ($this->refuses) {
                return ['status' => 'error', 'code' => 'EXECUTION_FAILED', 'message' => 'browser failed'];
            }

            return $this->peers === null ? null : ['peers' => $this->peers];
        }
    };
}

it('reports that an empty browse on a desktop really does mean nobody is there', function (): void {
    $query = new MulticastMdnsQuery(config: app(Repository::class));

    $query->browse(MdnsAdvertiser::SERVICE_TYPE, 0.05);

    expect($query->reach())->toBe(LanDiscoveryReach::Available)
        ->and($query->reach()->silenceMeansNoPeers())->toBeTrue();
});

it('says on iOS, before any browse at all, that it cannot ask the question', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    config()->set('nativephp.entitlements', []);

    expect((new MulticastMdnsQuery(config: app(Repository::class)))->reach())->toBe(LanDiscoveryReach::Unsupported);
});

// Deliberately says nothing about the peer list. The machine running this is a
// Mac wearing the iOS signal, so its datagram really does leave and a desktop
// on the same wifi really does answer — the whole point being that the verdict
// comes from the runtime rather than from how many peers happened to reply.
it('still says it could not look after an iOS browse has run', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    config()->set('nativephp.entitlements', []);

    $query = new MulticastMdnsQuery(config: app(Repository::class));
    $query->browse(MdnsAdvertiser::SERVICE_TYPE, 0.05);

    expect($query->reach())->toBe(LanDiscoveryReach::Unsupported)
        ->and($query->reach()->silenceMeansNoPeers())->toBeFalse();
});

// The maintenance obligation, made mechanical. The iPhone-specific line is only
// true while the send is refused, and a build carrying the entitlement is proof
// Apple granted it — declaring it ungranted fails at signing, not at runtime.
it('says it CAN look on iOS the moment the multicast entitlement is declared', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    config()->set('nativephp.entitlements', [MulticastMdnsQuery::IOS_MULTICAST_ENTITLEMENT => true]);

    expect((new MulticastMdnsQuery(config: app(Repository::class)))->reach())->toBe(LanDiscoveryReach::Available);
});

it('reports a question it could not even encode as an inability, never as an empty network', function (): void {
    $query = new MulticastMdnsQuery(config: app(Repository::class));

    // A DNS label is one length byte, so 64 characters cannot be encoded and
    // the query is never put on the wire.
    $peers = $query->browse('_'.str_repeat('a', 64).'._tcp', 0.05);

    expect($peers)->toBe([])
        ->and($query->reach())->toBe(LanDiscoveryReach::Unsupported);
});

it('does not cache the reach alongside the peers it cached', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');
    config()->set('nativephp.entitlements', []);

    $inner = new MulticastMdnsQuery(config: app(Repository::class));
    $cached = new CachedPeerDiscovery($inner);

    $cached->browse(MdnsAdvertiser::SERVICE_TYPE, 0.05);
    $cached->browse(MdnsAdvertiser::SERVICE_TYPE, 0.05);

    expect($cached->reach())->toBe(LanDiscoveryReach::Unsupported)
        ->and($cached->reach())->toBe($inner->reach());
});

it('reports that it cannot look when the shell registered no Bonjour browse function', function (): void {
    $query = new BonjourBridgeQuery(bridgeAnswering(supports: false, peers: []));

    expect($query->reach())->toBe(LanDiscoveryReach::Unsupported)
        ->and($query->browse(MdnsAdvertiser::SERVICE_TYPE))->toBe([])
        ->and($query->reach())->toBe(LanDiscoveryReach::Unsupported);
});

it('maps a native Bonjour answer onto peers and drops the rows it cannot address', function (): void {
    $query = new BonjourBridgeQuery(bridgeAnswering(supports: true, peers: [
        ['deviceId' => 'desktop-lan', 'host' => '192.0.2.44', 'port' => 51337],
        ['deviceId' => 'no-port', 'host' => '192.0.2.45'],
        ['deviceId' => '', 'host' => '192.0.2.46', 'port' => 51337],
        ['deviceId' => 'bad-port', 'host' => '192.0.2.47', 'port' => 70000],
    ]));

    $peers = $query->browse(MdnsAdvertiser::SERVICE_TYPE);

    expect($peers)->toHaveCount(1)
        ->and($peers[0])->toBeInstanceOf(DiscoveredPeer::class)
        ->and($peers[0]->deviceId)->toBe('desktop-lan')
        ->and($peers[0]->wsUrl())->toBe('ws://192.0.2.44:51337/sync')
        ->and($peers[0]->discoveryMode)->toBe(DiscoveryMode::Mdns)
        ->and($query->reach())->toBe(LanDiscoveryReach::Available);
});

it('treats a native browser refusal as an inability to look, not as an empty network', function (): void {
    $query = new BonjourBridgeQuery(bridgeAnswering(supports: true, peers: [], refuses: true));

    expect($query->browse(MdnsAdvertiser::SERVICE_TYPE))->toBe([])
        ->and($query->reach())->toBe(LanDiscoveryReach::Unsupported);
});

it('binds the shell Bonjour browser when one exists, and the multicast query when it does not', function (): void {
    app()->instance(NativeBridge::class, bridgeAnswering(supports: true, peers: []));
    app()->forgetInstance(PeerDiscovery::class);

    $inner = new ReflectionProperty(CachedPeerDiscovery::class, 'inner');

    expect($inner->getValue(app(PeerDiscovery::class)))->toBeInstanceOf(BonjourBridgeQuery::class);

    app()->instance(NativeBridge::class, bridgeAnswering(supports: false, peers: []));
    app()->forgetInstance(PeerDiscovery::class);

    expect($inner->getValue(app(PeerDiscovery::class)))->toBeInstanceOf(MulticastMdnsQuery::class);
});

// The signal the pairing screen reads. It sits beside discoverInitiatorOnLan()
// on the gateway that screen already injects, and it is a reach rather than a
// platform so the screen never has to name iOS to know what silence meant.
it('answers on the pairing gateway whether the LAN could be searched at all', function (): void {
    app()->instance(NativeBridge::class, bridgeAnswering(supports: true, peers: []));
    app()->forgetInstance(PeerDiscovery::class);
    app()->forgetInstance(PairingGateway::class);

    expect(app(PairingGateway::class)->lanDiscoveryReach())->toBe(LanDiscoveryReach::Available);

    app()->instance(NativeBridge::class, bridgeAnswering(supports: false, peers: []));
    app()->forgetInstance(PeerDiscovery::class);
    app()->forgetInstance(PairingGateway::class);
    putenv('NATIVEPHP_PLATFORM=ios');
    config()->set('nativephp.entitlements', []);

    expect(app(PairingGateway::class)->lanDiscoveryReach())->toBe(LanDiscoveryReach::Unsupported);
});
