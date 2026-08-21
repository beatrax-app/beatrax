<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Closure;
use Illuminate\Console\Command;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\PairingFramePullHandler;
use Modules\Sync\Internal\Transport\PairingFrameRequestHandler;
use Modules\Sync\Internal\Transport\PairingOfferRequestHandler;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\SyncPorts;
use Psr\Log\LoggerInterface;

/**
 * @see SyncServiceProvider
 * @see SyncWebSocketHandler
 */
final class SyncServeCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:serve {--port= : WebSocket listen port; defaults to SYNC_PORT}';

    /** @var string */
    protected $description = 'Start the long-running Noise/WebSocket sync listener (amphp event loop).';

    /** @param Closure(): SyncWebSocketHandler $handler */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Closure $handler,
        private readonly MdnsAdvertiser $advertiser,
        private readonly DaemonShutdownSignal $shutdown,
        private readonly PairingOfferService $offers,
        private readonly PairingOfferRateLimiter $offerRateLimiter,
        private readonly PairingFrameApplier $frameApplier,
        private readonly PairingPeerOutbox $peerOutbox,
    ) {
        parent::__construct();
    }

    public function handle(SyncPorts $ports): int
    {
        // The desktop bundle starts PHP with -d max_execution_time=120, and
        // a listener is by definition longer-lived than any request: the loop
        // fatalled every two minutes, the watchdog respawned it, and a peer
        // dialling during that gap got a refused connection.
        set_time_limit(0);

        $requested = $this->option('port');
        $port = is_string($requested) && $requested !== '' ? (int) $requested : $ports->lan();
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

            // No origin restriction: AllowOriginAcceptor compares the Origin
            // header against its list with in_array(), so ['*'] matched the
            // LITERAL string and every upgrade got "403 Origin forbidden" —
            // a native peer sends no Origin at all. Noise is the real gate.
            $acceptor = new Rfc6455Acceptor;
            $wsServer = new Websocket($httpServer, $this->logger, $acceptor, $handler);

            // Two extra routes in front of the upgrade, and the WebSocket
            // cannot serve either: its Noise session authenticates against the
            // confirmed-device registry, and both of these exist precisely for
            // a device that is not in it yet.
            //
            // The frame route is innermost so the offer route is matched first;
            // anything that is neither reaches the WebSocket untouched.
            $frameHandler = new PairingFrameRequestHandler(
                $wsServer,
                $this->frameApplier,
                // Its own bucket: sharing the offer route's let a polling phone
                // spend the allowance a human typing a code needs.
                $this->offerRateLimiter->withLimit(PairingFrameRequestHandler::MAX_PER_WINDOW),
                $handler->localUserId(),
            );

            // The return leg. Only this side listens — a phone runs no server —
            // so frames addressed to a phone wait here until it collects them.
            $pullHandler = new PairingFramePullHandler(
                $frameHandler,
                $this->peerOutbox,
                $this->offerRateLimiter->withLimit(PairingFrameRequestHandler::MAX_PER_WINDOW),
            );

            // A device holding only a typed word-code has no way to learn this
            // device's public identity, and a fresh responder cannot accept a
            // token without it.
            $requestHandler = new PairingOfferRequestHandler(
                $pullHandler,
                $this->offers,
                $this->offerRateLimiter,
                $handler->localUserId(),
            );

            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($requestHandler, $errorHandler);

            // advertise() is a best-effort shell-out (dns-sd / avahi-publish-service);
            // it silently no-ops when neither CLI is available, falling through to
            // manual host:port entry or the relay.
            $this->advertiser->advertise($handler->localDeviceId(), $port);

            $this->logger->info('sync:serve: listener started.', ['port' => $port]);
            $stopHint = $this->canTrapSignals() ? 'SIGTERM/SIGINT to stop' : 'no signal handling on this runtime';
            $this->info("sync:serve: listening on 0.0.0.0:{$port} ({$stopHint}).");

            // Waits on a signal where ext-pcntl exists, and on the host's
            // stdin pipe closing either way — which is the only notice a
            // force-quit gives, and without it this daemon outlived the app
            // and kept holding the port.
            $this->shutdown->await($this->canTrapSignals());
            $this->logger->info('sync:serve: shutdown requested — stopping server.');

            $httpServer->stop();
        } catch (\Throwable $e) {
            $this->logger->error('sync:serve: fatal error.', SafeExceptionContext::describe($e));
            $this->error('sync:serve: fatal — '.$e::class);

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
