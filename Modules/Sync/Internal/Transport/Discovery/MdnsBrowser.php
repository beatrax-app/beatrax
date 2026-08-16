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

    private const int MAX_PORT = 65535;

    // -L streams like -B and never exits; a resolve only has to catch the
    // first "can be reached at" line, so it is bounded tighter than a browse.
    private const int RESOLVE_TIMEOUT_SECONDS = 2;

    private const string DISCOVERY_MODE_MDNS = 'mdns';

    private const string DISCOVERY_MODE_MANUAL = 'manual';

    private const int AVAHI_MIN_FIELDS = 10;

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
                        discoveryMode: self::DISCOVERY_MODE_MANUAL,
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
    // dns-sd -B format: tab-delimited "Add n n domain type instance-name";
    // its host/port come from the follow-up -L resolve below.
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
        $fields = $this->parseAvahiFields($line);
        if ($fields === null) {
            return null;
        }

        $deviceId = $this->confirmedDeviceId($fields['txt'], $fields['name'], $confirmedDeviceIds);
        if ($deviceId === null) {
            return null;
        }

        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: $fields['host'] !== '' ? $fields['host'] : 'localhost',
            port: $fields['port'],
            discoveryMode: self::DISCOVERY_MODE_MDNS,
        );
    }

    // Splits and range-checks the semicolon-delimited avahi record before any
    // device-id work, so a short row or an out-of-range port never reaches
    // the confirmation lookup.
    /**
     * @return array{name: string, host: string, port: int, txt: string}|null
     */
    private function parseAvahiFields(string $line): ?array
    {
        $parts = explode(';', $line);
        if (count($parts) < self::AVAHI_MIN_FIELDS) {
            return null;
        }

        $port = is_numeric($parts[8]) ? (int) $parts[8] : 0;
        if ($port <= 0 || $port > self::MAX_PORT) {
            return null;
        }

        return ['name' => $parts[3], 'host' => $parts[7], 'port' => $port, 'txt' => $parts[9]];
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
        $instanceName = is_array($parts) ? end($parts) : false;
        if (! is_string($instanceName)) {
            return null;
        }

        $deviceId = $this->confirmedDeviceId('', $instanceName, $confirmedDeviceIds);
        if ($deviceId === null) {
            return null;
        }

        // A browse alone carries no address, and an unresolved peer was
        // dropped as unconnectable — so mDNS discovery returned nothing on
        // macOS however many peers were advertising.
        $resolved = $this->resolveInstance($instanceName);

        if ($resolved === null) {
            return null;
        }

        return new DiscoveredPeer(
            deviceId: $deviceId,
            host: $resolved['host'],
            port: $resolved['port'],
            discoveryMode: self::DISCOVERY_MODE_MDNS,
        );
    }

    // `dns-sd -L` prints "... can be reached at <host>:<port>". It streams
    // like -B, so it is time-boxed the same way and read from whatever it
    // printed before the timeout.
    /**
     * @return array{host: string, port: int}|null
     */
    private function resolveInstance(string $instanceName): ?array
    {
        $binary = $this->findBinary('dns-sd');

        if ($binary === null) {
            return null;
        }

        $process = new Process([$binary, '-L', $instanceName, self::SERVICE_TYPE, 'local']);
        $process->setTimeout(self::RESOLVE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Expected: -L streams until killed, so the timeout is the
            // normal exit and whatever it printed is the answer.
        }

        if (preg_match('/can be reached at\s+([^\s:]+):(\d+)/', $process->getOutput(), $matches) !== 1) {
            return null;
        }

        $port = (int) $matches[2];

        if ($port <= 0 || $port > self::MAX_PORT) {
            return null;
        }

        return ['host' => rtrim($matches[1], '.'), 'port' => $port];
    }

    // Extracts the advertised device id and confirms it against the caller's
    // trusted set in one step — an unknown or unconfirmed device_id yields
    // null so neither parser ever surfaces a peer outside the trust boundary.
    /**
     * @param  list<string>  $confirmedDeviceIds
     */
    private function confirmedDeviceId(string $txt, string $instanceName, array $confirmedDeviceIds): ?string
    {
        $deviceId = $this->extractDeviceId($txt, $instanceName);
        if ($deviceId === null) {
            return null;
        }

        return in_array($deviceId, $confirmedDeviceIds, true) ? $deviceId : null;
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
