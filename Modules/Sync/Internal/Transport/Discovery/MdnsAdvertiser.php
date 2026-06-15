<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Symfony\Component\Process\Process;

/**
 * mDNS (Bonjour / Avahi) advertiser for the Beatrax sync service.
 *
 * Advertises `_beatrax-sync._tcp` with a `did={deviceId}` TXT record so that
 * MdnsBrowser peers on the same LAN can discover and connect via the D-07
 * layered discovery protocol.
 *
 * ## Platform strategy (D-07)
 *
 *   macOS:   `/usr/bin/dns-sd -R "Beatrax-{deviceId}" _beatrax-sync._tcp local {PORT} did={deviceId}`
 *   Linux:   `avahi-publish-service "Beatrax-{deviceId}" _beatrax-sync._tcp {PORT} "did={deviceId}"`
 *   Neither: logs a warning and returns; caller falls through to manual host:port or relay.
 *
 * ## TXT record `did={deviceId}`
 *
 *   The TXT record carries the Beatrax device_id so that the browsing peer can
 *   filter out advertisers whose device_id is NOT in its confirmed device_registry
 *   (T-13-10 pre-filter: mDNS poisoning guard). The Noise handshake is the real
 *   auth gate; filtering here avoids wasted handshakes against rogue advertisers.
 *
 * ## Process lifecycle
 *
 *   `advertise()` spawns the os-level daemon with `setTimeout(null)` (runs forever).
 *   `stop()` terminates the child. The caller (sync:serve command) owns the lifetime.
 *   Multiple consecutive `advertise()` calls replace the existing process.
 *
 * ## No library dependency
 *
 *   `sharkydog/mdns` (51 installs, low confidence) is intentionally avoided.
 *   Symfony Process is already in the dependency tree.
 *
 * @internal Plan 04 — used by the sync:serve artisan command (Plan 05).
 */
final class MdnsAdvertiser
{
    use LocatesSystemBinary;

    /**
     * mDNS service type for Beatrax sync (Bonjour / Avahi naming convention).
     */
    public const string SERVICE_TYPE = '_beatrax-sync._tcp';

    /**
     * The running dns-sd / avahi-publish-service process (null if not advertising).
     */
    private ?Process $process = null;

    /**
     * Start advertising this device's sync endpoint via mDNS.
     *
     * If a previous process is still running, it is stopped first.
     * When neither `dns-sd` nor `avahi-publish-service` is available the
     * method returns immediately (the caller falls through to manual host:port
     * or relay per D-07 — no exception thrown).
     *
     * @param  string  $deviceId  The device's unique identifier (UUIDv4 from device_registry).
     * @param  int  $port  The WebSocket port this device is listening on (default: 51337).
     */
    public function advertise(string $deviceId, int $port): void
    {
        $this->stop();

        // IN-05: defense-in-depth. Symfony Process uses argv (no shell-injection
        // risk), but a deviceId with whitespace/control characters would produce a
        // malformed mDNS instance name. In practice deviceId is a UUIDv4, so reject
        // anything that is not — a bad id silently skips advertising rather than
        // publishing a broken service name.
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $deviceId) !== 1) {
            return;
        }

        $cmd = $this->buildCommand($deviceId, $port);
        if ($cmd === null) {
            // No supported mDNS CLI found — fall through to manual / relay (D-07).
            return;
        }

        $this->process = new Process($cmd);
        $this->process->setTimeout(null); // Long-running daemon; caller calls stop() on shutdown.
        $this->process->start();
    }

    /**
     * Stop the mDNS advertisement daemon.
     *
     * Safe to call when not advertising.
     */
    public function stop(): void
    {
        $this->process?->stop(2); // 2-second grace then SIGKILL.
        $this->process = null;
    }

    /**
     * Whether the advertisement daemon is currently running.
     */
    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    /**
     * Build the platform-specific mDNS advertise command.
     *
     * Returns null when no supported CLI is found.
     *
     * @return list<string>|null
     */
    private function buildCommand(string $deviceId, int $port): ?array
    {
        // macOS: Bonjour via dns-sd (confirmed present at /usr/bin/dns-sd on dev machine — RESEARCH.md).
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

        // Linux: Avahi via avahi-publish-service.
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

        // Neither available — caller falls through to manual host:port or relay (D-07).
        return null;
    }
}
