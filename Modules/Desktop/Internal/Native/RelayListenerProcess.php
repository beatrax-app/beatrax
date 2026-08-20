<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\LoggerInterface;
use Throwable;

// Pairing frames travel ONLY over the relay courier, so with no relay the
// handshake had nowhere to deliver: the desktop never received the phone's
// accept, never showed the safety words, and the phone polled forever.
final readonly class RelayListenerProcess
{
    // Mirrors config/sync.php 'relay_port'; duplicated because phpstan's
    // noGlobalLaravelFunction rule bans the config() helper in this layer.
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

        // Provision BEFORE spawning: relay:serve reads the certificate once, at
        // startup, so a relay started ahead of the material served plaintext for its
        // whole life while the QR advertised an https endpoint nobody could reach.
        $endpoint = $this->provisioner->ensureConfigured(self::PORT);

        if ($endpoint === null) {
            $this->logger->warning('relay listener: no LAN address found; pairing cannot advertise an endpoint.');
        }

        // As with the sync listener, a persistent ChildProcess outlives the Electron
        // process that spawned it, so a second start would fatal on the bound port.
        if (! $this->portIsBound()) {
            $this->spawn();

            return;
        }

        // A relay already up is only correct if it speaks what the endpoint
        // advertises. One started before the material existed answers in plaintext,
        // and being persistent it survives the relaunch that would have fixed it.
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
            $this->logger->warning('relay listener: failed to start relay:serve child process.', [
                'exception' => $e,
            ]);
        }
    }

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
    // holding. The dropped connection makes a TLS listener log a failed
    // negotiation each time — accepted, over fighting the daemon for its port.
    private function portIsBound(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, self::PROBE_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    // Verification is deliberately off: the certificate is self-signed and this is
    // loopback, so the question is which protocol the socket speaks, not whose key.
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
