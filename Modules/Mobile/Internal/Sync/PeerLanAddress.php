<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Sync\Public\Services\PeerLanAddressBook;
use Modules\Sync\Public\Services\RelayEndpointHost;
use Modules\Sync\Public\Services\SyncPorts;

// Where to dial ONE named peer on this LAN, in the order the transport ladder
// names: where this device last REACHED it, then the address a reader typed for
// a network whose browse never answers, then a guess at the same machine
// derived from the relay endpoint's host.

// The peer is named by the caller and never chosen here. Choosing it here meant
// choosing it twice — once for the address and once, from a different read of
// the registry, for the Noise static key — and a household with two confirmed
// desktops got one desktop's key offered at the other's address.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-address-the-button-dialled-was-a-guess
 * @link ../../../../.docs/features/mobile/dialing-the-peer-this-address-belongs-to.md
 */
final readonly class PeerLanAddress
{
    public function __construct(
        private RelayEndpointHost $relayHost,
        private SyncPorts $ports,
        private PeerLanAddressBook $addresses,
    ) {}

    // Never browses. The caller is a two-second poll, and one browse costs its
    // whole timeout; the pull behind it does the discovering.
    public function recall(int $userId, string $peerDeviceId): ?PeerDial
    {
        return $this->dial($peerDeviceId, $this->addresses->recall($userId, $peerDeviceId))
            ?? $this->fallbacks($userId, $peerDeviceId);
    }

    // For the press a reader is waiting on, where one browse is worth its
    // timeout: nothing remembered is exactly the state a moved desktop, or a
    // forget() after a dead dial, leaves behind.
    public function locate(int $userId, string $peerDeviceId): ?PeerDial
    {
        return $this->dial($peerDeviceId, $this->addresses->locate($userId, $peerDeviceId))
            ?? $this->fallbacks($userId, $peerDeviceId);
    }

    // Kept, a remembered address that no longer answers is retried by every
    // later press and the peer stays permanently unreachable.
    public function forget(int $userId, string $peerDeviceId): void
    {
        $this->addresses->forget($userId, $peerDeviceId);
    }

    private function fallbacks(int $userId, string $peerDeviceId): ?PeerDial
    {
        return $this->dial($peerDeviceId, $this->addresses->manual($userId, $peerDeviceId))
            ?? $this->dial($peerDeviceId, $this->fromRelayEndpoint());
    }

    // The desktop's `sync:serve` port, deliberately not the relay endpoint's —
    // the relay is a different service on a different box.
    /**
     * @return array{host: string, port: int}|null
     */
    private function fromRelayEndpoint(): ?array
    {
        $host = $this->relayHost->host();

        return $host === null ? null : ['host' => $host, 'port' => $this->ports->lan()];
    }

    /**
     * @param  array{host: string, port: int}|null  $address
     */
    private function dial(string $peerDeviceId, ?array $address): ?PeerDial
    {
        return $address === null ? null : new PeerDial($peerDeviceId, $address['host'], $address['port']);
    }
}
