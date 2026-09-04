<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Actions;

use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Process\RunExitCodeFile;
use Modules\DevMode\Internal\Process\RunRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

// Called from inside a live SSE handler, so nothing here may propagate: a
// failed audit write must not kill the stream that reported the run.
final readonly class SettleFinishedRun
{
    public function __construct(
        private RunRegistry $registry,
        private FinalizeRunAudit $finalize,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{exit: ?int, cancelled: bool}
     */
    public function __invoke(string $runId, string $outPath): array
    {
        $fresh = $this->registry->find($runId);
        // The registry only holds a code once something recorded one; for a
        // detached run that is the watcher subshell's sidecar.
        $exit = $fresh->exitCode ?? RunExitCodeFile::read($outPath);
        $cancelled = $fresh?->status === 'cancelled';

        if ($fresh !== null && $fresh->status === 'running') {
            $this->registry->markFinished($runId, $exit ?? 0);
        }

        try {
            ($this->finalize)($runId, $exit, $cancelled);
        } catch (Throwable $finalizeError) {
            $this->logFinalizeFailure($runId, $finalizeError);
        }

        return ['exit' => $exit, 'cancelled' => $cancelled];
    }

    private function logFinalizeFailure(string $runId, Throwable $error): void
    {
        try {
            $this->logger->error('FinalizeRunAudit failed for run '.$runId, [
                'exception' => $error->getMessage(),
                'exception_class' => $error::class,
            ]);
        } catch (Throwable) {
            // Nothing is left to try: this IS the report of a failure, and the
            // only other channel is the response body, which is a live SSE
            // stream a stray frame would corrupt. Rethrowing would kill the
            // stream — the exact outcome the catch above exists to prevent.
        }
    }
}
