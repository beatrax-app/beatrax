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
 * Throwaway Wave-0 topology probe (Phase 15-02, Spike A).
 *
 * Proves the amphp / Revolt event loop can be driven to completion for a
 * SINGLE bounded dial-out burst (open -> handshake-probe -> close) from within
 * the mobile app shell — the on-device question RESEARCH.md flags as
 * "amphp-in-mobile-runtime GO/NO-GO". The desktop peer's `sync:serve`
 * (Modules\Sync\Commands\SyncServeCommand) is the dial target: an amphp
 * WebSocket listener on `0.0.0.0:51337` that speaks the Noise handshake first
 * (Modules\Sync\Internal\Transport\PeerCatchUpExchanger). This probe does NOT
 * implement that handshake — it only opens the socket, waits briefly for the
 * peer's first frame, then closes. The load-bearing signal is whether the
 * Revolt loop runs to completion WITHOUT a driver/loop-conflict exception, not
 * whether a peer answered.
 *
 * OUTBOUND-CLIENT-ONLY discipline (RESEARCH.md Pitfall 1): this is a bounded
 * burst, never a long-lived listener — iOS/Android forbid always-on background
 * sockets. `async(...)->await()` drives the Revolt loop until the single
 * coroutine settles, then control returns.
 *
 * Exit semantics:
 *   - SUCCESS: the loop ran to completion. Either the peer answered (full
 *     connect + clean close) OR the peer was unreachable (connection refused /
 *     timeout). Both prove the loop topology works — a refused connection still
 *     means Revolt drove the socket attempt to a definite result.
 *   - FAILURE: an UNEXPECTED throwable surfaced (the shape a native-loop /
 *     Revolt-driver conflict would take under the NativePHP mobile runtime).
 *
 * @internal Throwaway spike probe — not shipped wiring. Registered only from
 *           the mobile-app root's bootstrap (never the shared MobileServiceProvider).
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
            // Peer unreachable (connection refused / handshake rejected) or the
            // connect budget's TimeoutCancellation fired. Either way the Revolt
            // loop STILL ran to completion — a refused socket reaches a definite
            // result and a fired cancellation is itself a loop-driven event — so
            // the loop-topology proof holds. This is the expected outcome when no
            // desktop sync:serve is listening.
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
     * Open one WebSocket connection, wait briefly for the peer's first frame,
     * then close. Runs entirely inside a single Revolt coroutine that
     * `await()` drives to completion.
     *
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
