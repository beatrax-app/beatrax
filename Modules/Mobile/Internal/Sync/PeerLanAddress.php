<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PeerLanAddressBook;
use Modules\Sync\Public\Services\RelayEndpointHost;
use Modules\Sync\Public\Services\SyncPorts;

// Where to dial the confirmed peer on this LAN, in the order the transport
// ladder names: where this device last REACHED the desktop, then the address a
// reader typed for a network whose browse never answers, then a guess at the
// same machine derived from the relay endpoint's host.
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

        if ($peerDeviceId === null) {
            return $this->fromRelayEndpoint();
        }

        return $this->addresses->recall($userId, $peerDeviceId)
            ?? $this->addresses->manual($userId, $peerDeviceId)
            ?? $this->fromRelayEndpoint();
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

        if ($peerDeviceId === null) {
            return $this->fromRelayEndpoint();
        }

        return $this->addresses->locate($userId, $peerDeviceId)
            ?? $this->addresses->manual($userId, $peerDeviceId)
            ?? $this->fromRelayEndpoint();
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
