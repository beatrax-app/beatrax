<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncPorts;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\LoggerInterface;
use Throwable;

// Separate from the boot provider because the DeviceSyncEnabled listener also
// needs it, and that runs in an ordinary web request where the provider's
// WindowManager is not bound.
final readonly class SyncListenerProcess
{
    private const ALIAS = 'sync-listener';

    private const PROBE_TIMEOUT_SECONDS = 1;

    public function __construct(
        private DeviceRegistryService $devices,
        private SyncPorts $ports,
        private LoggerInterface $logger,
    ) {}

    // Without a sync identity no peer can dial in, so starting regardless bound a
    // socket for an app that may never sync.
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

        // A persistent ChildProcess outlives the Electron process that spawned it,
        // so a relaunch finds the previous listener still holding the port and a
        // second start would fatal with "Address already in use".
        if ($this->portIsBound()) {
            $this->reconcileRunningListener($environment);

            return;
        }

        try {
            ChildProcess::artisan(
                'sync:serve --port='.$this->ports->lan(),
                self::ALIAS,
                $environment === [] ? null : $environment,
                true,
            );
        } catch (Throwable $e) {
            $this->logger->warning('sync listener: failed to start sync:serve child process.', [
                'exception' => $e,
            ]);
        }
    }

    // A listener already up is only correct if it HAS credentials: one started at
    // boot, before the app was unlocked, answers every handshake with an unusable
    // key, so new credentials must replace it rather than be discarded.
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

    // A restart is the only way a new environment takes effect: the handler reads
    // its keypair once, at construction.
    /**
     * @param  array<string, string>  $environment
     */
    private function restartWith(array $environment): void
    {
        try {
            ChildProcess::stop(self::ALIAS);

            ChildProcess::artisan(
                'sync:serve --port='.$this->ports->lan(),
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

    // Connects, never binds: a bind test races the daemon for the very port it is
    // holding. It stole the port from a starting sync:serve, which then exited,
    // leaving every later request spawning another through Electron's sync IPC.
    private function portIsBound(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->ports->lan(), $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
