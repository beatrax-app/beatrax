<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * @link ../../../../../.docs/features/sync/lan-discovery-trust-model.md
 */
final class MdnsAdvertiser
{
    use LocatesSystemBinary;

    public const string SERVICE_TYPE = '_beatrax-sync._tcp';

    private ?Process $process = null;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger;
    }

    // If a previous process is still running, it is stopped first. When
    // neither dns-sd nor avahi-publish-service is available the method
    // returns immediately — the caller falls through to manual host:port or
    // relay (see @link), no exception thrown.
    public function advertise(string $deviceId, int $port): void
    {
        $this->stop();

        // Defense-in-depth: Symfony Process uses argv (no shell-injection
        // risk), but a deviceId with whitespace/control characters would
        // produce a malformed mDNS instance name. deviceId is always a
        // UUIDv4, so reject anything that is not.
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $deviceId) !== 1) {
            // A daemon spawned before the app was unlocked carries no identity
            // and lands here with an empty id, which returned silently: nothing
            // advertised, nothing logged, and four rounds of a desktop that was
            // simply invisible on the LAN.
            $this->logger->warning('mDNS advertise: refusing a device id that is not a UUID; this device will not be discoverable.', [
                'device_id_length' => strlen($deviceId),
                'port' => $port,
            ]);

            return;
        }

        $cmd = $this->buildCommand($deviceId, $port);
        if ($cmd === null) {
            $this->logger->warning('mDNS advertise: no dns-sd or avahi-publish-service on this host; falling back to manual host:port or relay.', [
                'port' => $port,
            ]);

            return;
        }

        $this->logger->info('mDNS advertise: publishing this device on the LAN.', [
            'device_id' => $deviceId,
            'port' => $port,
        ]);

        $this->process = new Process($cmd);
        $this->process->setTimeout(null);

        // Nothing reads this output, so its pipes exist only to give the
        // destructor something to block on: Symfony's close() reads them with
        // stream_select() and NO timeout, and a long-lived dns-sd never EOFs.
        // Under paratest that wedged a worker alive and silent, forever.
        $this->process->disableOutput();
        $this->process->start();
    }

    public function stop(): void
    {
        $this->process?->stop(2);
        $this->process = null;
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    /**
     * @return list<string>|null
     */
    private function buildCommand(string $deviceId, int $port): ?array
    {
        if (is_file('/usr/bin/dns-sd')) {
            return [
                '/usr/bin/dns-sd',
                '-R',
                "Beatrax-{$deviceId}",
                self::SERVICE_TYPE,
                'local',
                (string) $port,
                "did={$deviceId}",
            ];
        }

        $avahiPublish = $this->findBinary('avahi-publish-service');
        if ($avahiPublish !== null) {
            return [
                $avahiPublish,
                "Beatrax-{$deviceId}",
                self::SERVICE_TYPE,
                (string) $port,
                "did={$deviceId}",
            ];
        }

        return null;
    }
}
