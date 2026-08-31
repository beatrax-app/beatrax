<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Enums\LanDiscoveryReach;
use Modules\Sync\Public\Transport\ProtocolTimings;

// The second road to the same answer: let the platform's own Bonjour browser
// do the multicast, instead of sending the datagram ourselves. iOS refuses the
// raw send without an entitlement Apple grants per Team, but exempts NWBrowser
// under the NSBonjourServices the app already declares.
/**
 * @link ../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final class BonjourBridgeQuery implements PeerDiscovery
{
    public const string BROWSE_FUNCTION = 'Discovery.Browse';

    private const int MAX_HOST_BYTES = 255;

    private ?LanDiscoveryReach $lastBrowseReach = null;

    public function __construct(private readonly NativeBridge $bridge) {}

    public function reach(): LanDiscoveryReach
    {
        return $this->lastBrowseReach ?? $this->registeredReach();
    }

    /**
     * @param  string  $serviceType  e.g. `_beatrax-sync._tcp`
     * @param  float  $timeoutSeconds  How long to keep collecting answers for.
     * @return list<DiscoveredPeer>
     */
    public function browse(string $serviceType, float $timeoutSeconds = ProtocolTimings::BROWSE_SECONDS): array
    {
        $this->lastBrowseReach = $this->registeredReach();

        $answer = $this->bridge->call(self::BROWSE_FUNCTION, [
            'serviceType' => $serviceType,
            'timeoutSeconds' => $timeoutSeconds,
        ]);

        // A success answer is the browse function's own dict, unwrapped — only a
        // failure carries `status`. The bridge README documents an envelope for
        // both, and reading for it would make every good browse look empty. A
        // refusal means it could not look, never "nobody is there".
        if ($answer === null || array_key_exists('status', $answer)) {
            $this->lastBrowseReach = LanDiscoveryReach::Unsupported;

            return [];
        }

        return $this->peersFrom($answer['peers'] ?? null);
    }

    private function registeredReach(): LanDiscoveryReach
    {
        return $this->bridge->supports(self::BROWSE_FUNCTION)
            ? LanDiscoveryReach::Available
            : LanDiscoveryReach::Unsupported;
    }

    /**
     * @return list<DiscoveredPeer>
     */
    private function peersFrom(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $peers = [];

        foreach ($rows as $row) {
            $peer = is_array($row) ? $this->peerFrom($row) : null;

            if ($peer !== null) {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function peerFrom(array $row): ?DiscoveredPeer
    {
        $deviceId = self::boundedString($row['deviceId'] ?? null, PeerAdvertisementLimits::MAX_DEVICE_ID_BYTES);
        $host = self::boundedString($row['host'] ?? null, self::MAX_HOST_BYTES);
        $port = $row['port'] ?? null;

        if ($deviceId === '' || ! is_int($port) || $port < 1 || $port > PeerAdvertisementLimits::MAX_PORT) {
            return null;
        }

        // Mdns, not a mode of its own: the browser changed, the trust did not.
        // A Bonjour answer is as unauthenticated as a datagram, and the safety
        // number is still the only thing that proves who replied.
        return new DiscoveredPeer($deviceId, $host, $port, DiscoveryMode::Mdns);
    }

    private static function boundedString(mixed $value, int $maxBytes): string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maxBytes ? trim($value) : '';
    }
}
