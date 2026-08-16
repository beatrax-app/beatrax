<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\LoggerInterface;
use Throwable;

// Runs `relay:serve` for a device that has sync enabled, and points this
// device at it. Pairing frames travel ONLY over the relay courier, so with no
// relay the handshake had nowhere to deliver: the desktop never received the
// phone's accept, never showed the safety words, and the phone polled forever.
/**
 * @link ../../../../.docs/features/desktop/architecture.md
 */
final readonly class RelayListenerProcess
{
    // Mirrors config/sync.php 'relay_port' — duplicated because the config()
    // global helper is unavailable under phpstan L10's
    // noGlobalLaravelFunction rule in this layer.
    private const PORT = 51338;

    private const ALIAS = 'relay-listener';

    private const PROBE_TIMEOUT_SECONDS = 1;

    public function __construct(
        private DeviceRegistryService $devices,
        private LocalRelayProvisioner $provisioner,
        private LoggerInterface $logger,
    ) {}

    public function startIfEnabled(): void
    {
        if (! class_exists(ChildProcess::class)) {
            return;
        }

        if (! $this->devices->hasLocalDevice()) {
            return;
        }

        // Provision BEFORE spawning. relay:serve reads the certificate once,
        // at startup, so a relay started ahead of the material served
        // plaintext for its whole life while this same call published an
        // https endpoint into the QR that no peer could then handshake with.
        $endpoint = $this->provisioner->ensureConfigured(self::PORT);

        if ($endpoint === null) {
            $this->logger->warning('relay listener: no LAN address found; pairing cannot advertise an endpoint.');
        }

        // Same reasoning as the sync listener: a persistent ChildProcess
        // outlives the Electron process that spawned it, so a relaunch finds
        // the previous relay still bound and a second start would fatal with
        // "Address already in use".
        if (! $this->portIsBound()) {
            $this->spawn();

            return;
        }

        // A relay already up is only correct if it speaks what the endpoint
        // advertises. One started before the material existed answers the
        // https endpoint above in plaintext, and being persistent it survives
        // the relaunch that would otherwise have fixed it.
        if ($endpoint !== null && str_starts_with($endpoint, 'https://') && ! $this->portSpeaksTls()) {
            $this->logger->info('relay listener: running relay is plaintext but the endpoint is https; restarting it.');

            $this->respawn();
        }
    }

    private function spawn(): void
    {
        try {
            ChildProcess::artisan(
                'relay:serve --port='.self::PORT,
                self::ALIAS,
                null,
                true,
            );
        } catch (Throwable $e) {
            // A relay that fails to start must never take the app down with
            // it: pairing degrades, the rest of the app does not.
            $this->logger->warning('relay listener: failed to start relay:serve child process.', [
                'exception' => $e,
            ]);
        }
    }

    // Stops whatever holds the alias and starts a replacement. Separate from
    // spawn() so a failed stop still leaves the start attempt logged as a
    // relay problem rather than as a silent no-op.
    private function respawn(): void
    {
        try {
            ChildProcess::stop(self::ALIAS);
        } catch (Throwable $e) {
            $this->logger->warning('relay listener: failed to stop the running relay:serve child process.', [
                'exception' => $e,
            ]);
        }

        $this->spawn();
    }

    // Connects, never binds: a bind test races the daemon for the port it is
    // holding, and this runs on every request. The dropped connection makes a
    // TLS listener log a failed negotiation each time — accepted, as the cost
    // of not fighting the daemon for its own port.
    private function portIsBound(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    // Whether the bound port completes a TLS handshake. The certificate is
    // self-signed and this is loopback, so verification is deliberately off:
    // the question is which protocol the socket speaks, not whose key it is.
    private function portSpeaksTls(): bool
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $socket = @stream_socket_client(
            'ssl://127.0.0.1:'.self::PORT,
            $errno,
            $errstr,
            self::PROBE_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
