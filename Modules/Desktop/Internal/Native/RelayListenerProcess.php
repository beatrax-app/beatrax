<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\LocalRelayProvisioner;
use Modules\Sync\Public\Services\SyncPorts;
use Native\Desktop\Facades\ChildProcess;
use Psr\Log\LoggerInterface;
use Throwable;

// Pairing frames travel ONLY over the relay courier, so with no relay the
// handshake had nowhere to deliver: the desktop never received the phone's
// accept, never showed the safety words, and the phone polled forever.
final readonly class RelayListenerProcess
{
    private const string ALIAS = 'relay-listener';

    public function __construct(
        private DeviceRegistryService $devices,
        private LocalRelayProvisioner $provisioner,
        private SyncPorts $ports,
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
        $endpoint = $this->provisioner->ensureConfigured($this->ports->relay());

        if ($endpoint === null) {
            $this->logger->warning('relay listener: no LAN address found; pairing cannot advertise an endpoint.');
        }

        $this->reconcile($endpoint !== null && str_starts_with($endpoint, 'https://'));
    }

    // As with the sync listener, a persistent ChildProcess outlives the Electron
    // process that spawned it, so a second start would fatal on the bound port.
    // Which dial asks that depends on what the endpoint promises, because only
    // one of the two is legible to a relay serving TLS.
    private function reconcile(bool $advertisesTls): void
    {
        if ($advertisesTls) {
            $this->reconcileTlsRelay();

            return;
        }

        if (! $this->portIsBound()) {
            $this->spawn();
        }
    }

    // One dial, and the one a peer makes: a handshake that completes proves the
    // port is held AND that what holds it serves what the QR advertises. The
    // bare connect below proves only the first, and is silent only because it
    // is reached when nothing would read it as a handshake (see @link).
    /**
     * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-liveness-dial-reported-as-a-failed-handshake
     */
    private function reconcileTlsRelay(): void
    {
        if ($this->portSpeaksTls()) {
            return;
        }

        if (! $this->portIsBound()) {
            $this->spawn();

            return;
        }

        // A relay already up is only correct if it speaks what the endpoint
        // advertises, and the probe measures whether a handshake completes, not
        // why it did not: plaintext from one started before the material existed,
        // nothing at all from one whose key does not open its certificate.
        $this->logger->info('relay listener: the running relay completes no TLS handshake but the endpoint is https; restarting it.');

        $this->respawn();
    }

    private function spawn(): void
    {
        try {
            ChildProcess::artisan(
                'relay:serve --port='.$this->ports->relay(),
                self::ALIAS,
                null,
                true,
            );
        } catch (Throwable $e) {
            $this->logger->warning('relay listener: failed to start relay:serve child process.', [
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    private function respawn(): void
    {
        try {
            ChildProcess::stop(self::ALIAS);
        } catch (Throwable $e) {
            $this->logger->warning('relay listener: failed to stop the running relay:serve child process.', [
                ...SafeExceptionContext::describe($e),
            ]);
        }

        $this->spawn();
    }

    // Connects, never binds: a bind test races the daemon for the port it is
    // holding. It closes before any ClientHello, so a relay serving TLS files
    // the dial as a failed negotiation — which is why nothing reaches this
    // while a handshake would be read as one.
    private function portIsBound(): bool
    {
        $socket = @fsockopen('127.0.0.1', $this->ports->relay(), $errno, $errstr, LoopbackProbe::TIMEOUT_SECONDS);

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
            'ssl://127.0.0.1:'.$this->ports->relay(),
            $errno,
            $errstr,
            LoopbackProbe::TIMEOUT_SECONDS,
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
