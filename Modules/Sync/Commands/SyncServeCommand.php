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
 * @link ../../../.docs/features/sync/architecture.md
 * @see SyncServiceProvider
 * @see SyncWebSocketHandler
 */
final class SyncServeCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:serve {--port=51337 : WebSocket listen port}';

    /** @var string */
    protected $description = 'Start the long-running Noise/WebSocket sync listener (amphp event loop).';

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
            // The Noise handshake is the real authentication gate.
            $acceptor = new AllowOriginAcceptor(['*']);
            $wsServer = new Websocket($httpServer, $this->logger, $acceptor, $this->handler);

            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($wsServer, $errorHandler);

            // advertise() is a best-effort shell-out (dns-sd / avahi-publish-service);
            // it silently no-ops when neither CLI is available, falling through to
            // manual host:port entry or the relay.
            $this->advertiser->advertise($this->handler->localDeviceId(), $port);

            $this->logger->info('sync:serve: listener started.', ['port' => $port]);
            $this->info("sync:serve: listening on 0.0.0.0:{$port} (SIGTERM/SIGINT to stop).");

            // Suspend this fiber until SIGTERM or SIGINT arrives. The revolt
            // event loop continues processing WS connections in the background.
            \Amp\trapSignal([\SIGTERM, \SIGINT]);

            $this->logger->info('sync:serve: shutdown signal received — stopping server.');
            $httpServer->stop();
        } catch (\Throwable $e) {
            $this->logger->error('sync:serve: fatal error.', ['error' => $e->getMessage()]);
            $this->error("sync:serve: fatal — {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $this->advertiser->stop();
        }

        $this->info('sync:serve: stopped cleanly.');
        $this->logger->info('sync:serve: stopped cleanly.');

        return self::SUCCESS;
    }
}
