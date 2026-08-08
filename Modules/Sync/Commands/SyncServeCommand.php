<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\AllowOriginAcceptor;
use Amp\Websocket\Server\Websocket;
use Closure;
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

    /** @param Closure(): SyncWebSocketHandler $handler */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Closure $handler,
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
            // Built here rather than injected: constructing it reaches the
            // encrypted search writer, and a console boot that resolves every
            // registered command would then need an application key before one
            // could be minted.
            $handler = ($this->handler)();

            $httpServer = SocketHttpServer::createForDirectAccess($this->logger);
            $httpServer->expose("0.0.0.0:{$port}");

            // LAN-only: AllowOriginAcceptor(['*']) skips browser Origin checks.
            // The Noise handshake is the real authentication gate.
            $acceptor = new AllowOriginAcceptor(['*']);
            $wsServer = new Websocket($httpServer, $this->logger, $acceptor, $handler);

            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($wsServer, $errorHandler);

            // advertise() is a best-effort shell-out (dns-sd / avahi-publish-service);
            // it silently no-ops when neither CLI is available, falling through to
            // manual host:port entry or the relay.
            $this->advertiser->advertise($handler->localDeviceId(), $port);

            $this->logger->info('sync:serve: listener started.', ['port' => $port]);
            $stopHint = $this->canTrapSignals() ? 'SIGTERM/SIGINT to stop' : 'no signal handling on this runtime';
            $this->info("sync:serve: listening on 0.0.0.0:{$port} ({$stopHint}).");

            // trapSignal() needs ext-pcntl, and the PHP binary NativePHP
            // bundles for the desktop build ships without it: SIGTERM is an
            // undefined constant there, so the command fatalled right after
            // binding the port and the supervisor restarted it forever.
            if ($this->canTrapSignals()) {
                \Amp\trapSignal([\SIGTERM, \SIGINT]);
                $this->logger->info('sync:serve: shutdown signal received — stopping server.');
            } else {
                // Nothing to trap: park the fiber indefinitely and let the
                // supervisor kill the process. Shutdown is not graceful here,
                // which is survivable — the listener holds no unflushed state,
                // and every peer exchange is a bounded open/do/close.
                $this->logger->info('sync:serve: no signal handling available; running until terminated.');
                (new DeferredFuture)->getFuture()->await();
            }

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

    // Both are required: ext-pcntl supplies the trapping machinery and the
    // SIG* constants, and a runtime can define one without the other.
    private function canTrapSignals(): bool
    {
        return \function_exists('pcntl_signal') && \defined('SIGTERM') && \defined('SIGINT');
    }
}
