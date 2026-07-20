<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class MdnsBrowser
{
    use LocatesSystemBinary;

    public const string SERVICE_TYPE = '_beatrax-sync._tcp';

    // dns-sd -B runs forever until killed; the initial scan is time-boxed to
    // this many seconds to collect the current local network state.
    // Live-streaming connections in the sync:serve daemon use a persistent
    // loop instead.
    private const int DEFAULT_BROWSE_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly DeviceRegistryService $registryService,
    ) {}

    /**
     * @param  int  $userId  User scope for device_registry filtering.
     * @param  list<array{host: string, port: int, deviceId: string}>  $manualPeers
     *                                                                               Manually configured peers (from sync config, read by the caller).
     * @return list<DiscoveredPeer> Confirmed peers to attempt connections to.
     */
    public function discoverPeers(int $userId, array $manualPeers = []): array
    {
        $mdnsPeers = $this->browse($userId);
        if ($mdnsPeers !== []) {
            return $mdnsPeers;
        }

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

        return [];
    }

    // Shells out to dns-sd -B (macOS) or avahi-browse -p -r (Linux) for
    // DEFAULT_BROWSE_TIMEOUT_SECONDS, then parses the output. Returns an
    // empty list when no CLI is available, no advertisements are found, or
    // all found advertisements are for unconfirmed/unknown device_ids.
    /**
     * @param  int  $userId  User scope for device_registry pre-filter.
     * @return list<DiscoveredPeer>
     */
    public function browse(int $userId): array
    {
        $cmd = $this->buildBrowseCommand();
        if ($cmd === null) {
            return [];
        }

        $process = new Process($cmd);
        $process->setTimeout(self::DEFAULT_BROWSE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Expected — a timed-out browse still captures what was visible
            // on the LAN up to that point.
        }

        $output = $process->getOutput();
        if ($output === '') {
            return [];
        }

        return $this->parseOutput($output, $userId);
    }

    /**
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

            // Drop unresolved peers (host=''/port=0): dns-sd -B yields these
            // without a -L resolve step, which would produce a
            // non-connectable ws://:0/sync URL if handed to a caller.
            $peer = $this->parseLine($line, $confirmedDeviceIds);
            if ($peer !== null && $peer->isConnectable()) {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    // Avahi parseable format (-p -r): semicolon-delimited fields
    // =;iface;proto;name;type;domain;hostname;address;port;txt.
    // dns-sd -B format: tab-delimited "Add n n domain type instance-name" —
    // host/port requires a separate -L resolve not implemented here.
    /**
     * @param  list<string>  $confirmedDeviceIds
     */
    private function parseLine(string $line, array $confirmedDeviceIds): ?DiscoveredPeer
    {
        if (str_starts_with($line, '=;') || str_starts_with($line, '+;')) {
            return $this->parseAvahiLine($line, $confirmedDeviceIds);
        }

        if (str_contains($line, 'Add') && str_contains($line, self::SERVICE_TYPE)) {
            return $this->parseDnsSdLine($line, $confirmedDeviceIds);
        }

        return null;
    }

    /**
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

        $deviceId = $this->extractDeviceId($txt, $name);
        if ($deviceId === null) {
            return null;
        }

        if (! in_array($deviceId, $confirmedDeviceIds, true)) {
            return null;
        }

        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: $host !== '' ? $host : 'localhost',
            port: $port,
            discoveryMode: 'mdns',
        );
    }

    // Format: Add 3 5 local _beatrax-sync._tcp. Beatrax-{deviceId}. dns-sd -B
    // does not include host/port; a subsequent dns-sd -L resolve would be
    // required, so this returns the deviceId with empty host/port=0 as a
    // marker — the caller must resolve before attempting a connection.
    /**
     * @param  list<string>  $confirmedDeviceIds
     */
    private function parseDnsSdLine(string $line, array $confirmedDeviceIds): ?DiscoveredPeer
    {
        $parts = preg_split('/\s+/', trim($line));
        if (! is_array($parts)) {
            return null;
        }

        $instanceName = end($parts);
        if (! is_string($instanceName)) {
            return null;
        }

        $deviceId = $this->extractDeviceId('', $instanceName);
        if ($deviceId === null) {
            return null;
        }

        if (! in_array($deviceId, $confirmedDeviceIds, true)) {
            return null;
        }

        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: '',
            port: 0,
            discoveryMode: 'mdns',
        );
    }

    // TXT format: did={deviceId}. Instance name fallback: Beatrax-{deviceId}
    // (the -R name we advertise).
    private function extractDeviceId(string $txt, string $instanceName): ?string
    {
        if (preg_match('/\bdid=([^\s;]+)/', $txt, $m) === 1) {
            return $m[1];
        }

        if (str_starts_with($instanceName, 'Beatrax-')) {
            $extracted = substr($instanceName, strlen('Beatrax-'));
            if ($extracted !== '') {
                return $extracted;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function buildBrowseCommand(): ?array
    {
        if (is_file('/usr/bin/dns-sd')) {
            return ['/usr/bin/dns-sd', '-B', self::SERVICE_TYPE, 'local'];
        }

        $avahiBrowse = $this->findBinary('avahi-browse');
        if ($avahiBrowse !== null) {
            return [$avahiBrowse, '-p', '-r', '-t', self::SERVICE_TYPE];
        }

        return null;
    }
}
