<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PeerLanAddressBook;
use Modules\Sync\Public\Services\RelayEndpointHost;
use Modules\Sync\Public\Services\SyncPorts;

// Where to dial the confirmed peer on this LAN. The address this device last
// REACHED the desktop at comes first, host and port together; the relay
// endpoint below is a guess at the same machine, and a LAN-only pairing never
// configures one, which is how the manual sync came to dial nothing at all.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-address-the-button-dialled-was-a-guess
 */
final readonly class PeerLanAddress
{
    public function __construct(
        private RelayEndpointHost $relayHost,
        private SyncPorts $ports,
        private PeerLanAddressBook $addresses,
        private DeviceRegistryService $devices,
    ) {}

    // Never browses. The caller is a two-second poll, and one browse costs its
    // whole timeout; the pull behind it does the discovering.
    /**
     * @return array{host: string, port: int}|null
     */
    public function recall(int $userId): ?array
    {
        $peerDeviceId = $this->peerDeviceId($userId);
        $recalled = $peerDeviceId === null ? null : $this->addresses->recall($userId, $peerDeviceId);

        return $recalled ?? $this->fromRelayEndpoint();
    }

    // For the press a reader is waiting on, where one browse is worth its
    // timeout: nothing remembered is exactly the state a moved desktop, or a
    // forget() after a dead dial, leaves behind.
    /**
     * @return array{host: string, port: int}|null
     */
    public function locate(int $userId): ?array
    {
        $peerDeviceId = $this->peerDeviceId($userId);
        $located = $peerDeviceId === null ? null : $this->addresses->locate($userId, $peerDeviceId);

        return $located ?? $this->fromRelayEndpoint();
    }

    // Kept, a remembered address that no longer answers is retried by every
    // later press and the peer stays permanently unreachable.
    public function forget(int $userId): void
    {
        $peerDeviceId = $this->peerDeviceId($userId);

        if ($peerDeviceId !== null) {
            $this->addresses->forget($userId, $peerDeviceId);
        }
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

    private function peerDeviceId(int $userId): ?string
    {
        return array_key_first($this->devices->otherDeviceNames($userId));
    }
}
