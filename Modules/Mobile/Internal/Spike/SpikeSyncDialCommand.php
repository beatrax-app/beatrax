<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Spike;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnectException;
use Error;
use Illuminate\Console\Command;
use Modules\Sync\Public\Services\SyncPorts;
use Throwable;

use function Amp\async;
use function Amp\Websocket\Client\connect;

final class SpikeSyncDialCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:spike-dial {--host= : Desktop peer host to dial (default 127.0.0.1)} {--port= : Desktop sync:serve WebSocket port; defaults to SYNC_PORT}';

    /** @var string */
    protected $description = 'Spike A: drive the amphp/Revolt loop for a single bounded dial-out to a desktop sync:serve listener.';

    public function handle(SyncPorts $ports): int
    {
        $host = $this->resolveHost();
        $requested = $this->option('port');
        $port = is_string($requested) && $requested !== '' ? (int) $requested : $ports->lan();

        if ($port <= 0 || $port > 65535) {
            $this->error(sprintf('mobile:spike-dial: invalid port %s.', $port));

            return self::FAILURE;
        }

        $uri = sprintf('ws://%s:%s/', $host, $port);
        $this->info(sprintf('mobile:spike-dial: dialing %s (5s connect budget, bounded burst)…', $uri));

        try {
            $frameSeen = $this->dialOnce($uri);
            $this->info($frameSeen
                ? 'mobile:spike-dial: connected, received one frame, closed cleanly.'
                : 'mobile:spike-dial: connected, no frame within probe window, closed cleanly.');
            $this->info('RESULT: SUCCESS (Revolt loop drove to completion; no loop-conflict).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->reportDialFailure($e);
        }
    }

    // Splits the two catch outcomes without a second try: an unreachable
    // peer or fired connect budget still proves the Revolt loop drove to
    // completion (SUCCESS), whereas any other throwable is the native-loop
    // / Revolt-driver conflict the on-device run watches for (FAILURE).
    private function reportDialFailure(Throwable $e): int
    {
        // A supervisor captures this command's stdout to the same kind of file
        // the logger writes, and nothing narrowed what the loop threw. The two
        // dial failures name a host and a timeout and no row, so their message
        // is read where the type is known rather than where it was caught.
        if ($e instanceof WebsocketConnectException || $e instanceof CancelledException) {
            $dialMessage = $e instanceof WebsocketConnectException ? $e->getMessage() : '';
            $this->warn('mobile:spike-dial: peer unreachable — no desktop sync:serve listening (or connect timed out).');
            $this->line('  reason: '.$e::class.($dialMessage === '' ? '' : ': '.$dialMessage));
            $this->info('RESULT: SUCCESS (Revolt loop drove to completion; no loop-conflict).');

            return self::SUCCESS;
        }

        // An Error is the shape this spike watches for -- a fiber driven from
        // two loops fails as a TypeError or a fatal, and those messages name
        // types and callables. Anything else prints its class alone.
        $loopMessage = $e instanceof Error ? $e->getMessage() : '';
        $this->error('mobile:spike-dial: UNEXPECTED failure driving the event loop.');
        $this->line('  '.$e::class.($loopMessage === '' ? '' : ': '.$loopMessage));
        $this->info('RESULT: FAILURE (possible native-loop / Revolt-driver conflict).');

        return self::FAILURE;
    }

    /**
     * @return bool whether a frame arrived within the probe window
     */
    private function dialOnce(string $uri): bool
    {
        $frameSeen = async(static function () use ($uri): bool {
            $connection = connect($uri, new TimeoutCancellation(5.0));

            try {
                // The desktop peer initiates the Noise handshake, so a frame may
                // arrive without us sending first. A short window is enough — we
                // are proving the loop runs, not completing the handshake.
                $message = $connection->receive(new TimeoutCancellation(2.0));

                return $message !== null;
            } catch (Throwable) {
                // No frame inside the window (or peer closed) — irrelevant to the
                // loop-topology proof; the close in finally still runs.
                return false;
            } finally {
                $connection->close();
            }
        })->await();

        // Future::await() is typed `mixed` (amphp ships no generic stub for the
        // async() return), so narrow explicitly rather than widening the method.
        return $frameSeen === true;
    }

    private function resolveHost(): string
    {
        $host = $this->option('host');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }
}
