<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ArtisanStreamController
{
    private const SSE_DATA_PREFIX = 'data: ';

    private const TICK_MICROSECONDS = 150_000;

    private const STREAM_TIMEOUT_SECONDS = 600;

    public function __construct(
        private RunRegistry $registry,
        private FileTailer $tailer,
        private FinalizeRunAudit $finalize,
        private ProcessLiveness $liveness,
    ) {}

    public function __invoke(
        string $runId,
        Request $request,
        CurrentUser $user,
    ): StreamedResponse {
        $record = $this->registry->find($runId);
        if ($record === null) {
            throw new NotFoundHttpException("Unknown run: {$runId}");
        }

        // Defense-in-depth ownership: a developer cannot inspect another
        // developer's run stream by guessing the UUID.
        if ($record->callerUserId !== $user->id()) {
            throw new AccessDeniedHttpException('cross_user_inspection_forbidden');
        }

        $startOffset = $this->resolveStartOffset($request);

        $response = new StreamedResponse(function () use ($record, $startOffset, $runId): void {
            $this->streamLoop($record, $startOffset, $runId);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function streamLoop(RunRecord $record, int $startOffset, string $runId): void
    {
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ignore_user_abort(true);
        // max_execution_time is wall-clock here, and the shipped php-bin
        // defaults to 30s — well short of STREAM_TIMEOUT_SECONDS.
        @set_time_limit(0);

        $offset = $startOffset;
        $deadline = microtime(true) + self::STREAM_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $result = $this->tailer->tailOnce($record->outPath, $offset);
            $offset = $result['newOffset'];
            if ($result['chunk'] !== '') {
                $this->writeChunkFrame($result['chunk'], $offset);
                $this->flushOutput();
            }

            // A gone PID is the only completion signal a detached child
            // gives; the audit pipeline owns the authoritative exit code.
            if (! $this->liveness->isAlive($record->pid)) {
                $this->emitTerminal($runId, $record->outPath, $offset);
                break;
            }

            if (connection_aborted() !== 0) {
                break;
            }

            usleep(self::TICK_MICROSECONDS);
        }
    }

    private function emitTerminal(string $runId, string $outPath, int $offset): void
    {
        $offset = $this->emitFinalChunk($outPath, $offset);

        $fresh = $this->registry->find($runId);
        $exit = $fresh?->exitCode;
        $cancelled = $fresh?->status === 'cancelled';

        if ($fresh !== null && $fresh->status === 'running') {
            $this->registry->markFinished($runId, $exit ?? 0);
        }

        $this->safelyFinalize($runId, $exit, $cancelled);

        echo "event: done\n";
        echo self::SSE_DATA_PREFIX.json_encode([
            'exit' => $exit,
            'cancelled' => $cancelled,
        ], JSON_UNESCAPED_SLASHES)."\n\n";
        $this->flushOutput();
    }

    private function emitFinalChunk(string $outPath, int $offset): int
    {
        $finalChunk = $this->tailer->tailOnce($outPath, $offset);
        if ($finalChunk['chunk'] === '') {
            return $offset;
        }

        $offset = $finalChunk['newOffset'];
        $this->writeChunkFrame($finalChunk['chunk'], $offset);

        return $offset;
    }

    // Ordered before the terminal done event, and never allowed to propagate
    // out of the SSE handler — a failed audit write must not kill the stream.
    private function safelyFinalize(string $runId, ?int $exit, bool $cancelled): void
    {
        try {
            ($this->finalize)($runId, $exit, $cancelled);
        } catch (\Throwable $finalizeError) {
            $this->logFinalizeFailure($runId, $finalizeError);
        }
    }

    private function logFinalizeFailure(string $runId, \Throwable $error): void
    {
        try {
            Container::getInstance()
                ->make(LoggerInterface::class)
                ->error('FinalizeRunAudit failed for run '.$runId, [
                    'exception' => $error->getMessage(),
                    'exception_class' => get_class($error),
                ]);
        } catch (\Throwable) {
        }
    }

    private function writeChunkFrame(string $chunk, int $offset): void
    {
        echo 'id: '.$offset."\n";
        echo self::SSE_DATA_PREFIX.json_encode(['line' => $chunk], JSON_UNESCAPED_SLASHES)."\n\n";
    }

    private function flushOutput(): void
    {
        @ob_flush();
        @flush();
    }

    // EventSource sends Last-Event-ID on auto-reconnect; `?from=` is the
    // manual override. 0 replays the whole captured stdout.
    private function resolveStartOffset(Request $request): int
    {
        $lastEventId = $request->header('Last-Event-ID');
        if (is_string($lastEventId) && preg_match('/^\d+$/', $lastEventId) === 1) {
            return (int) $lastEventId;
        }

        $fromQuery = $request->query('from');
        if (is_string($fromQuery) && preg_match('/^\d+$/', $fromQuery) === 1) {
            return (int) $fromQuery;
        }

        return 0;
    }
}
