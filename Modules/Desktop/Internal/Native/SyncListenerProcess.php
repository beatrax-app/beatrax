<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\LoggerInterface;
use Throwable;

// Owns the lifetime of the `sync:serve` daemon. Extracted from the boot
// provider because two callers need it and neither can reach the other: the
// NativePHP boot hook, and the DeviceSyncEnabled listener, which runs in an
// ordinary web request where the provider's WindowManager is not bound.
/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class SyncListenerProcess
{
    // Mirrors config/sync.php 'port' — duplicated because the config()
    // global helper is unavailable under phpstan L10's
    // noGlobalLaravelFunction rule in this layer.
    private const PORT = 51337;

    private const ALIAS = 'sync-listener';

    // Loopback only, so a bound port answers immediately; this must never
    // add perceptible latency to app boot.
    private const PROBE_TIMEOUT_SECONDS = 1;

    public function __construct(
        private DeviceRegistryService $devices,
        private LoggerInterface $logger,
    ) {}

    // Starts the listener only when this device holds a sync identity.
    // Without one no peer can dial in and every inbound connection would be
    // rejected, so starting regardless bound a socket for an app that may
    // never sync.
    /**
     * @param  array<string, string>  $environment  The daemon's Noise transport
     *                                              keypair; empty when the app
     *                                              is locked and cannot open it.
     */
    public function startIfEnabled(array $environment = []): void
    {
        if (! class_exists(ChildProcess::class)) {
            return;
        }

        if (! $this->devices->hasLocalDevice()) {
            $this->logger->info('sync listener: no device identity on this device; not starting.');

            return;
        }

        // A persistent ChildProcess outlives the Electron process that spawned
        // it, so a crash-and-relaunch (or a killed app) leaves the previous
        // listener holding the port. Starting a second one fatals with
        // "Address already in use" — the running one is already what we want.
        if ($this->portIsBound()) {
            $this->reconcileRunningListener($environment);

            return;
        }

        try {
            ChildProcess::artisan(
                'sync:serve --port='.self::PORT,
                self::ALIAS,
                $environment === [] ? null : $environment,
                true,
            );
        } catch (Throwable $e) {
            // A listener that fails to start must never take the app down
            // with it: sync degrades, the rest of the app does not.
            $this->logger->warning('sync listener: failed to start sync:serve child process.', [
                'exception' => $e,
            ]);
        }
    }

    // A listener already up is only correct if it HAS credentials. One started
    // at boot, before the app was unlocked, answers every handshake with an
    // unusable key, so new credentials must replace it rather than be
    // discarded.
    /**
     * @param  array<string, string>  $environment
     */
    private function reconcileRunningListener(array $environment): void
    {
        if ($environment === []) {
            $this->logger->info('sync listener: already listening; leaving the running process in place.');

            return;
        }

        $this->restartWith($environment);
    }

    // Replaces a running listener so the new environment takes effect: the
    // handler reads its keypair once, at construction.
    /**
     * @param  array<string, string>  $environment
     */
    private function restartWith(array $environment): void
    {
        try {
            ChildProcess::stop(self::ALIAS);

            ChildProcess::artisan(
                'sync:serve --port='.self::PORT,
                self::ALIAS,
                $environment,
                true,
            );

            $this->logger->info('sync listener: restarted with device credentials.');
        } catch (Throwable $e) {
            $this->logger->warning('sync listener: failed to restart with credentials.', [
                'exception' => $e,
            ]);
        }
    }

    // Connects, never binds: a bind test races the daemon for the very port
    // it is holding, and this runs on every request — it stole the port from
    // a starting sync:serve, which then exited, leaving every later request
    // spawning another through Electron's synchronous IPC.
    private function portIsBound(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
