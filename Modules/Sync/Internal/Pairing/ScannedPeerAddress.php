<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;

// The address an initiator wrote into its QR, kept on the row the responder
// seeded from that scan. One reader because three questions turn on it: where
// to send a frame, where to collect one from, and whether the screen may blame
// the network for a frame it never had anywhere to send (see @link).
/**
 * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final readonly class ScannedPeerAddress
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function forTokenHash(string $tokenHash, string $peerDeviceId): ?DiscoveredPeer
    {
        if ($tokenHash === '' || $peerDeviceId === '') {
            return null;
        }

        $row = $this->db->connection()->table('pairing_tokens')
            ->where('token_hash', $tokenHash)
            ->where('initiator_device_id', $peerDeviceId)
            ->first(['initiator_lan_host', 'initiator_lan_port']);

        $host = $row->initiator_lan_host ?? null;
        $port = $row->initiator_lan_port ?? null;

        // Manual, not Mdns: it came from a scan rather than the network, so
        // isFromNetwork() stays false for every caller that asks.
        return is_string($host) && $host !== '' && is_numeric($port) && (int) $port > 0
            ? new DiscoveredPeer($peerDeviceId, $host, (int) $port, DiscoveryMode::Manual)
            : null;
    }

    // The same address, asked for by the side that has to COLLECT rather than
    // send: a device that cannot browse knows of no peer to ask, and the scan
    // is the only thing that ever told it where the initiator was.
    /**
     * @return list<DiscoveredPeer>
     */
    public function forCollector(int $userId, string $collectingDeviceId): array
    {
        if ($collectingDeviceId === '') {
            return [];
        }

        $rows = $this->db->connection()->table('pairing_tokens')
            ->where('user_id', $userId)
            ->where('responder_device_id', $collectingDeviceId)
            ->whereIn('state', PairingState::inFlightValues())
            ->where('expires_at', '>', Instant::zulu($this->clock->now()))
            ->orderByDesc('id')
            ->get(['initiator_device_id', 'initiator_lan_host', 'initiator_lan_port']);

        $peers = [];

        foreach ($rows as $row) {
            $peer = self::peerOf($row);

            if ($peer !== null) {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    private static function peerOf(object $row): ?DiscoveredPeer
    {
        $deviceId = $row->initiator_device_id ?? null;
        $host = $row->initiator_lan_host ?? null;
        $port = $row->initiator_lan_port ?? null;

        return is_string($deviceId) && $deviceId !== ''
            && is_string($host) && $host !== ''
            && is_numeric($port) && (int) $port > 0
                ? new DiscoveredPeer($deviceId, $host, (int) $port, DiscoveryMode::Manual)
                : null;
    }
}
