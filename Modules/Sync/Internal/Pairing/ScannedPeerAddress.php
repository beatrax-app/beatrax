<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Transport\Discovery\DiscoveredPeer;
use Modules\Sync\Internal\Transport\Discovery\DiscoveryMode;

// The address an initiator wrote into its QR, kept on the row the responder
// seeded from that scan. One reader because two questions turn on it: where to
// send a frame, and whether the screen may blame the network for a frame it
// never had anywhere to send (see @link).
/**
 * @link ../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final readonly class ScannedPeerAddress
{
    public function __construct(private DatabaseManager $db) {}

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
}
