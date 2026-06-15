<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Websocket;
use Illuminate\Console\Command;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Psr\Log\LoggerInterface;

/**
 * Long-running Noise/WebSocket sync listener daemon (XPORT-01/02, D-03/D-04).
 *
 * Boots the amphp WebSocket server on 0.0.0.0:{port}, starts mDNS advertisement
 * so peers on the LAN can discover this device (D-07), and shuts down cleanly on
 * SIGTERM/SIGINT via \Amp\trapSignal().
 *
 * ## Host-agnostic design (D-03)
 *
 *   Runnable from two hosts:
 *     - Primary:  NativePHP ChildProcess (auto-started, persistent, auto-restarted)
 *     - Fallback: standalone launchd daemon or manual `php artisan sync:serve`
 *
 *   No import of any Electron/NativePHP-only API. The Noise/WS transport core
 *   (SyncWebSocketHandler) runs identically from either host.
 *
 * ## Event-loop lifecycle (D-04)
 *
 *   handle() calls \Amp\trapSignal([SIGTERM, SIGINT]) which suspends the current
 *   fiber until one of those signals arrives. The SocketHttpServer runs in the
 *   background (revolt event-loop). On signal receipt: stop the WS server and
 *   the mDNS advertiser, then return self::SUCCESS.
 *
 * ## SyncWebSocketHandler wiring
 *
 *   SyncWebSocketHandler is injected via the service container — the binding in
 *   SyncServiceProvider provides the device credentials resolved from
 *   DeviceIdentityLoader at container resolve-time. When no identity is available
 *   (fresh install, app locked), the injected handler carries empty placeholder
 *   credentials and closes all incoming connections at the auth gate. The
 *   NativePHP persistent:true host auto-restarts the command after setup completes.
 *
 * @internal Plan 05 — registered via SyncServiceProvider.
 */
final class SyncServeCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:serve {--port=51337 : WebSocket listen port}';

    /** @var string */
    protected $description = 'Start the long-running Noise/WebSocket sync listener (amphp event loop, D-03/D-04).';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SyncWebSocketHandler $handler,
        private readonly MdnsAdvertiser $advertiser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $port = (int) $this->option('port');
        if ($port <= 0 || $port > 65535) {
            $this->error("sync:serve: invalid port {$port}.");

            return self::FAILURE;
        }

        $this->logger->info('sync:serve: starting WebSocket listener.', ['port' => $port]);

        try {
            $httpServer = SocketHttpServer::createForDirectAccess($this->logger);
            $httpServer->expose("0.0.0.0:{$port}");

            // LAN-only: AllowOriginAcceptor(['*']) skips browser Origin checks.
            // The Noise handshake is the real authentication gate (T-13-14).
            $acceptor = new AllowOriginAcceptor(['*']);
            $wsServer = new Websocket($httpServer, $this->logger, $acceptor, $this->handler);

            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($wsServer, $errorHandler);

            // Advertise this device via mDNS so LAN peers can discover it (D-07).
            // advertise() is a best-effort shell-out (dns-sd / avahi-publish-service);
            // silently no-ops when neither CLI is available — falls through to
            // manual host:port entry or relay per D-07.
            $this->advertiser->advertise($this->handler->localDeviceId(), $port);

            $this->logger->info('sync:serve: listener started.', ['port' => $port]);
            $this->info("sync:serve: listening on 0.0.0.0:{$port} (SIGTERM/SIGINT to stop).");

            // Suspend this fiber until SIGTERM or SIGINT arrives (D-04).
            // The revolt event loop continues processing WS connections in the background.
            \Amp\trapSignal([\SIGTERM, \SIGINT]);

            $this->logger->info('sync:serve: shutdown signal received — stopping server.');
            $httpServer->stop();
        } catch (\Throwable $e) {
            $this->logger->error('sync:serve: fatal error.', ['error' => $e->getMessage()]);
            $this->error("sync:serve: fatal — {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            // Always stop the mDNS advertisement daemon on exit (even on error).
            $this->advertiser->stop();
        }

        $this->info('sync:serve: stopped cleanly.');
        $this->logger->info('sync:serve: stopped cleanly.');

        return self::SUCCESS;
    }
}
