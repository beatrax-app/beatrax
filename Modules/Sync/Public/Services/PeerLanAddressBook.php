<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Pairing\LanPeerBrowser;

// Where a peer was last reached on this network. Public because the mobile
// initial-sync pull needs an address to dial while the discovery stack stays
// Internal to Sync — the same reason RelayEndpointHost is Public.

// A browse costs its whole timeout, so the remembered address is the fast path
// and the browse fills or repairs it. Pairing by typed word code reached the
// desktop to fetch its offer and then discarded where it found it, leaving the
// pull with nowhere to dial and the network named as the fault.
final readonly class PeerLanAddressBook
{
    public function __construct(
        private DatabaseManager $db,
        private LanPeerBrowser $peers,
    ) {}

    /**
     * @return array{host: string, port: int}|null
     */
    public function locate(int $userId, string $deviceId): ?array
    {
        return $this->recall($userId, $deviceId) ?? $this->discoverAndRemember($userId, $deviceId);
    }

    /**
     * @return array{host: string, port: int}|null
     */
    public function recall(int $userId, string $deviceId): ?array
    {
        $row = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first(['last_lan_host', 'last_lan_port']);

        if ($row === null) {
            return null;
        }

        $host = $row->last_lan_host;
        $port = $row->last_lan_port;

        if (! is_string($host) || $host === '' || ! is_numeric($port) || (int) $port <= 0) {
            return null;
        }

        return ['host' => $host, 'port' => (int) $port];
    }

    /**
     * @return array{host: string, port: int}|null
     */
    public function discoverAndRemember(int $userId, string $deviceId): ?array
    {
        foreach ($this->peers->eachConnectablePeer(deviceId: $deviceId) as $peer) {
            $this->remember($userId, $deviceId, $peer->host, $peer->port);

            return ['host' => $peer->host, 'port' => $peer->port];
        }

        return null;
    }

    // Only ever narrows to one device's own row, so a peer that moved cannot
    // overwrite the address of a peer that did not.
    public function remember(int $userId, string $deviceId, string $host, int $port): void
    {
        if ($host === '' || $port <= 0) {
            return;
        }

        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->update(['last_lan_host' => $host, 'last_lan_port' => $port]);
    }

    // What a reader typed, for a network whose browse never answers. Read on
    // its own and never folded into recall(): forget() clears where the peer
    // was last REACHED, and a fallback a failed dial erases is not a fallback.
    /**
     * @return array{host: string, port: int}|null
     */
    public function manual(int $userId, string $deviceId): ?array
    {
        $row = $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->first(['manual_lan_host', 'manual_lan_port']);

        if ($row === null) {
            return null;
        }

        $host = $row->manual_lan_host;
        $port = $row->manual_lan_port;

        if (! is_string($host) || $host === '' || ! is_numeric($port) || (int) $port <= 0) {
            return null;
        }

        return ['host' => $host, 'port' => (int) $port];
    }

    // A null host clears the entry, which is how a reader takes the rung back
    // out of the ladder. Stored verbatim: this is the one address on the
    // device that no browse and no failed dial is allowed to rewrite.
    public function setManual(int $userId, string $deviceId, ?string $host, ?int $port): void
    {
        $clearing = $host === null || $host === '' || $port === null || $port <= 0;

        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->update([
                'manual_lan_host' => $clearing ? null : $host,
                'manual_lan_port' => $clearing ? null : $port,
            ]);
    }

    // A dial that failed against the remembered address means the peer moved
    // or went away; clearing it puts the next locate() back on the browse
    // instead of retrying an address that no longer answers.
    public function forget(int $userId, string $deviceId): void
    {
        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->update(['last_lan_host' => null, 'last_lan_port' => null]);
    }
}
