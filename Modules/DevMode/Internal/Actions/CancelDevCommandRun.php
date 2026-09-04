<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Actions;

use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The PID comes from the cached RunRecord, never a request body: otherwise a
// forged runId would SIGTERM an arbitrary process.
final readonly class CancelDevCommandRun
{
    private const int SIGTERM_GRACE_SECONDS = 3;

    public function __construct(
        private RunRegistry $registry,
    ) {}

    public function __invoke(RunRecord $record): void
    {
        if (! extension_loaded('posix')) {
            throw new HttpException(500, 'posix_required_for_cancel');
        }

        if (posix_kill($record->pid, 0)) {
            @posix_kill($record->pid, SIGTERM);
            $this->waitForExit($record->pid);
        }

        $this->registry->markCancelled($record->runId);
    }

    // Blocking the caller through the grace period buys the SSE liveness check
    // a PID that is actually gone by the time it looks.
    private function waitForExit(int $pid): void
    {
        $deadline = microtime(true) + self::SIGTERM_GRACE_SECONDS;
        while (microtime(true) < $deadline) {
            if (! posix_kill($pid, 0)) {
                break;
            }
            usleep(100_000);
        }

        if (posix_kill($pid, 0)) {
            @posix_kill($pid, SIGKILL);
            usleep(200_000);
        }
    }
}
