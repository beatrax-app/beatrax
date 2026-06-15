<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * mDNS peer browser for the Beatrax sync service.
 *
 * Browses the local network for `_beatrax-sync._tcp` advertisements, filters
 * the results to confirmed device_registry entries (T-13-10 pre-filter), and
 * returns a list of `DiscoveredPeer` value objects.
 *
 * ## D-07 Layered Discovery Protocol
 *
 *   Level 1 — mDNS (dns-sd / avahi-browse):
 *     Browse for `_beatrax-sync._tcp.local` advertisements. Filter to devices
 *     whose `did=` TXT record matches a confirmed device_id in device_registry.
 *     On macOS this is `/usr/bin/dns-sd -B _beatrax-sync._tcp local`.
 *     On Linux this is `avahi-browse -p -r _beatrax-sync._tcp`.
 *
 *   Level 2 — Manual host:port fallback:
 *     The caller may provide a list of manually-configured host:port entries
 *     (read from a sync config file by the caller). If an mDNS scan finds no
 *     confirmed peers, these are returned as `DiscoveredPeer` objects with
 *     `discoveryMode='manual'`.
 *
 *   Level 3 — Relay signal:
 *     If neither mDNS nor manual discovery yields any peer, `discoverPeers()`
 *     returns an empty list so the caller (sync:serve daemon or SyncWebSocketHandler)
 *     knows to fall back to the ZK relay for buffered delivery.
 *
 * ## Security: mDNS pre-filter (T-13-10)
 *
 *   `browse()` only returns peers whose `did=` TXT record matches an entry in
 *   `DeviceRegistryService::deviceKeys($userId)`. Unknown advertisers are dropped
 *   BEFORE any TCP connection attempt. This avoids wasted Noise handshakes against
 *   rogue/unrelated devices. The Noise handshake is the REAL auth gate — the
 *   pre-filter is an optimisation, not a trust boundary.
 *
 * ## No library dependency
 *
 *   `sharkydog/mdns` (51 installs, low confidence) is intentionally avoided.
 *   Symfony Process is already a transitive dependency.
 *
 * @internal Plan 04 — used by the sync:serve artisan command (Plan 05).
 */
final class MdnsBrowser
{
    use LocatesSystemBinary;

    /**
     * mDNS service type to browse (must match MdnsAdvertiser::SERVICE_TYPE).
     */
    public const string SERVICE_TYPE = '_beatrax-sync._tcp';

    /**
     * Default browse timeout in seconds.
     *
     * `dns-sd -B` runs forever until killed; we time-box the initial scan to this
     * many seconds to collect the current local network state. Live-streaming
     * connections in the sync:serve daemon use a persistent loop instead.
     */
    private const int DEFAULT_BROWSE_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly DeviceRegistryService $registryService,
    ) {}

    /**
     * Perform the D-07 layered peer discovery.
     *
     * Level 1 — mDNS scan (dns-sd or avahi-browse).
     * Level 2 — Manual host:port entries provided by the caller.
     * Level 3 — Empty list → caller falls back to relay.
     *
     * @param  int  $userId  User scope for device_registry filtering.
     * @param  list<array{host: string, port: int, deviceId: string}>  $manualPeers
     *                                                                               Manually configured peers (from sync config, read by the caller).
     * @return list<DiscoveredPeer> Confirmed peers to attempt connections to.
     */
    public function discoverPeers(int $userId, array $manualPeers = []): array
    {
        // Level 1: mDNS scan.
        $mdnsPeers = $this->browse($userId);
        if ($mdnsPeers !== []) {
            return $mdnsPeers;
        }

        // Level 2: manual host:port fallback.
        if ($manualPeers !== []) {
            $confirmedDeviceIds = array_keys($this->registryService->deviceKeys($userId));

            $resolved = [];
            foreach ($manualPeers as $manual) {
                if (in_array($manual['deviceId'], $confirmedDeviceIds, true)) {
                    $resolved[] = new DiscoveredPeer(
                        deviceId: $manual['deviceId'],
                        host: $manual['host'],
                        port: $manual['port'],
                        discoveryMode: 'manual',
                    );
                }
            }

            if ($resolved !== []) {
                return $resolved;
            }
        }

        // Level 3: no confirmed peers found — caller signals relay path.
        return [];
    }

    /**
     * Run a mDNS browse scan and return confirmed peers found within the timeout.
     *
     * Shells out to `dns-sd -B` (macOS) or `avahi-browse -p -r` (Linux) for
     * DEFAULT_BROWSE_TIMEOUT_SECONDS, then parses the output.
     *
     * Returns an empty list when:
     *   - No dns-sd / avahi-browse binary is available (degrade gracefully).
     *   - No `_beatrax-sync._tcp` advertisements found within the timeout.
     *   - All found advertisements are for unconfirmed or unknown device_ids.
     *
     * @param  int  $userId  User scope for device_registry pre-filter.
     * @return list<DiscoveredPeer>
     */
    public function browse(int $userId): array
    {
        $cmd = $this->buildBrowseCommand();
        if ($cmd === null) {
            // No supported mDNS CLI — graceful degrade (D-07).
            return [];
        }

        $process = new Process($cmd);
        $process->setTimeout(self::DEFAULT_BROWSE_TIMEOUT_SECONDS);

        // Run with output capture; ignore timeout termination (expected).
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Expected: timed-out browse captures what was visible on the LAN.
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        return $this->parseOutput($output, $userId);
    }

    /**
     * Parse `dns-sd -B` or `avahi-browse -p -r` output into DiscoveredPeer objects.
     *
     * Filters to entries whose `did=` TXT value is a confirmed device_id for $userId.
     *
     * dns-sd -B output line format:
     *   Add  3   5 local _beatrax-sync._tcp.      Beatrax-device-uuid
     *   Followed by detail lines from `dns-sd -L` (not available in browse mode without -L).
     *
     * For simplicity we parse the name field "Beatrax-{deviceId}" to extract the deviceId.
     * In production, a `dns-sd -L` resolve step would give host/port. For the initial
     * implementation we extract host/port from avahi-browse parseable format (`-p`).
     *
     * avahi-browse -p -r output line format (parseable):
     *   =;eth0;IPv4;Beatrax-device-uuid;_beatrax-sync._tcp;local;hostname.local;192.168.1.2;51337;did=device-uuid
     *
     * @return list<DiscoveredPeer>
     */
    private function parseOutput(string $output, int $userId): array
    {
        $confirmedDeviceIds = array_keys($this->registryService->deviceKeys($userId));
        if ($confirmedDeviceIds === []) {
            return [];
        }

        $peers = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $peer = $this->parseLine($line, $confirmedDeviceIds);
            // WR-09: drop unresolved peers (host='' / port=0). dns-sd -B browse
            // yields these without a -L resolve step; handing them to a caller
            // would produce a non-connectable ws://:0/sync URL.
            if ($peer !== null && $peer->isConnectable()) {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    /**
     * Parse a single output line from dns-sd or avahi-browse.
     *
     * Avahi parseable format (`-p -r`): semicolon-delimited fields:
     *   =;iface;proto;name;type;domain;hostname;address;port;txt
     *
     * dns-sd -B format: tab-delimited (host/port requires a separate `-L` resolve):
     *   Add  n  n  domain  type      instance-name
     * For dns-sd, we extract the deviceId from the instance name pattern "Beatrax-{deviceId}"
     * and record host='' (requires a subsequent resolve step not implemented here).
     *
     * @param  list<string>  $confirmedDeviceIds
     */
    private function parseLine(string $line, array $confirmedDeviceIds): ?DiscoveredPeer
    {
        // Try avahi parseable format first (=;...;address;port;txt).
        if (str_starts_with($line, '=;') || str_starts_with($line, '+;')) {
            return $this->parseAvahiLine($line, $confirmedDeviceIds);
        }

        // Try dns-sd -B "Add" line.
        if (str_contains($line, 'Add') && str_contains($line, self::SERVICE_TYPE)) {
            return $this->parseDnsSdLine($line, $confirmedDeviceIds);
        }

        return null;
    }

    /**
     * Parse an avahi-browse parseable output line.
     *
     * Format: =;iface;proto;name;type;domain;hostname;address;port;txt
     *
     * @param  list<string>  $confirmedDeviceIds
     */
    private function parseAvahiLine(string $line, array $confirmedDeviceIds): ?DiscoveredPeer
    {
        $parts = explode(';', $line);
        if (count($parts) < 10) {
            return null;
        }

        $name = $parts[3];
        $host = $parts[7];
        $portStr = $parts[8];
        $txt = $parts[9];

        $port = is_numeric($portStr) ? (int) $portStr : 0;
        if ($port <= 0 || $port > 65535) {
            return null;
        }

        // Extract deviceId from TXT record "did={deviceId}".
        $deviceId = $this->extractDeviceId($txt, $name);
        if ($deviceId === null) {
            return null;
        }

        if (! in_array($deviceId, $confirmedDeviceIds, true)) {
            return null; // T-13-10 pre-filter: unknown advertiser.
        }

        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: $host !== '' ? $host : 'localhost',
            port: $port,
            discoveryMode: 'mdns',
        );
    }

    /**
     * Parse a `dns-sd -B` "Add" line.
     *
     * Format: Add  3  5  local  _beatrax-sync._tcp.  Beatrax-{deviceId}
     *
     * Note: dns-sd -B does not include host/port; a subsequent `dns-sd -L` resolve
     * would be required. For now we return the deviceId with empty host/port=0 as a
     * marker — the caller would need to resolve before attempting a connection.
     * In practice the sync:serve daemon uses avahi on Linux or a persistent dns-sd
     * register+browse cycle where resolve is handled by the OS.
     *
     * @param  list<string>  $confirmedDeviceIds
     */
    private function parseDnsSdLine(string $line, array $confirmedDeviceIds): ?DiscoveredPeer
    {
        // Instance name is the last whitespace-delimited token.
        $parts = preg_split('/\s+/', trim($line));
        if (! is_array($parts)) {
            return null;
        }

        $instanceName = end($parts);
        if (! is_string($instanceName)) {
            return null;
        }

        // Extract deviceId from "Beatrax-{deviceId}".
        $deviceId = $this->extractDeviceId('', $instanceName);
        if ($deviceId === null) {
            return null;
        }

        if (! in_array($deviceId, $confirmedDeviceIds, true)) {
            return null; // T-13-10 pre-filter.
        }

        // dns-sd -B does not expose host/port without a -L resolve step.
        // Return with empty host and port=0; caller must resolve before connecting.
        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: '',
            port: 0,
            discoveryMode: 'mdns',
        );
    }

    /**
     * Extract a Beatrax deviceId from a mDNS TXT record or instance name.
     *
     * TXT format: `did={deviceId}` (space-separated TXT records may include other keys).
     * Instance name fallback: `Beatrax-{deviceId}` (the -R name we advertise).
     */
    private function extractDeviceId(string $txt, string $instanceName): ?string
    {
        // TXT record: did={deviceId}.
        if (preg_match('/\bdid=([^\s;]+)/', $txt, $m) === 1) {
            return $m[1];
        }

        // Instance name fallback: Beatrax-{deviceId}.
        if (str_starts_with($instanceName, 'Beatrax-')) {
            $extracted = substr($instanceName, strlen('Beatrax-'));
            if ($extracted !== '') {
                return $extracted;
            }
        }

        return null;
    }

    /**
     * Build the platform-specific mDNS browse command.
     *
     * Returns null when no supported CLI is available (graceful degrade — D-07).
     *
     * @return list<string>|null
     */
    private function buildBrowseCommand(): ?array
    {
        // macOS: dns-sd -B (confirmed present at /usr/bin/dns-sd on dev machine — RESEARCH.md).
        if (is_file('/usr/bin/dns-sd')) {
            return ['/usr/bin/dns-sd', '-B', self::SERVICE_TYPE, 'local'];
        }

        // Linux: avahi-browse parseable + resolve.
        $avahiBrowse = $this->findBinary('avahi-browse');
        if ($avahiBrowse !== null) {
            return [$avahiBrowse, '-p', '-r', '-t', self::SERVICE_TYPE];
        }

        return null;
    }
}
