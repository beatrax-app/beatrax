<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Spike;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnectException;
use Illuminate\Console\Command;
use Throwable;

use function Amp\async;
use function Amp\Websocket\Client\connect;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class SpikeSyncDialCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:spike-dial {--host= : Desktop peer host to dial (default 127.0.0.1)} {--port=51337 : Desktop sync:serve WebSocket port}';

    /** @var string */
    protected $description = 'Spike A: drive the amphp/Revolt loop for a single bounded dial-out to a desktop sync:serve listener.';

    public function handle(): int
    {
        $host = $this->resolveHost();
        $port = (int) $this->option('port');

        if ($port <= 0 || $port > 65535) {
            $this->error("mobile:spike-dial: invalid port {$port}.");

            return self::FAILURE;
        }

        $uri = "ws://{$host}:{$port}/";
        $this->info("mobile:spike-dial: dialing {$uri} (5s connect budget, bounded burst)…");

        try {
            $frameSeen = $this->dialOnce($uri);
        } catch (WebsocketConnectException|CancelledException $e) {
            // Peer unreachable or the connect budget fired - either way the
            // Revolt loop still ran to completion (a refused socket reaches
            // a definite result), so the loop-topology proof holds. This is
            // the expected outcome when no desktop sync:serve is listening.
            $this->warn('mobile:spike-dial: peer unreachable — no desktop sync:serve listening (or connect timed out).');
            $this->line('  reason: '.$e::class.': '.$e->getMessage());
            $this->info('RESULT: SUCCESS (Revolt loop drove to completion; no loop-conflict).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Any other throwable is the shape a native-loop / Revolt-driver
            // conflict would take under the NativePHP mobile runtime. This is
            // the genuine NO-GO signal the on-device run is watching for.
            $this->error('mobile:spike-dial: UNEXPECTED failure driving the event loop.');
            $this->line('  '.$e::class.': '.$e->getMessage());
            $this->info('RESULT: FAILURE (possible native-loop / Revolt-driver conflict).');

            return self::FAILURE;
        }

        $this->info($frameSeen
            ? 'mobile:spike-dial: connected, received one frame, closed cleanly.'
            : 'mobile:spike-dial: connected, no frame within probe window, closed cleanly.');
        $this->info('RESULT: SUCCESS (Revolt loop drove to completion; no loop-conflict).');

        return self::SUCCESS;
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
