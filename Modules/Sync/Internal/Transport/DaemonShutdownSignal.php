<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\DeferredFuture;
use Modules\Core\Public\Services\HostPipeWatch;

use function Amp\async;
use function Amp\ByteStream\getStdin;

// Parks a long-running daemon until it should shut down, watching both the
// signals a supervisor sends and the pipe the host holds open.
final readonly class DaemonShutdownSignal
{
    // A persistent ChildProcess outlives Electron by design, and a force quit
    // never runs the supervisor's before-quit hook — so the daemon was left
    // holding its port. The host's stdin closing is the one shutdown signal
    // that survives a kill -9 of the parent.
    public function await(bool $trapSignals): void
    {
        $shutdown = new DeferredFuture;

        async(static function () use ($shutdown): void {
            try {
                $stdin = getStdin();

                // Reads until EOF. The host never writes to these daemons, so
                // anything arriving is discarded and only the close matters.
                while ($stdin->read() !== null) {
                    continue;
                }
            } catch (\Throwable) {
                // An unreadable stdin means no parent to watch — fall through
                // to the signal wait rather than exiting a healthy daemon.
                return;
            }

            if (! $shutdown->isComplete()) {
                $shutdown->complete('parent pipe closed');
            }
        });

        if ($trapSignals) {
            async(static function () use ($shutdown): void {
                \Amp\trapSignal([\SIGTERM, \SIGINT]);

                if (! $shutdown->isComplete()) {
                    $shutdown->complete('signal received');
                }
            });
        }

        $shutdown->getFuture()->await();
    }

    // Whether stdin is a pipe held by a parent, rather than a terminal or
    // /dev/null. Only then does EOF on it actually mean "the host is gone".
    public static function hasParentPipe(): bool
    {
        return HostPipeWatch::isHeldByHost();
    }
}
