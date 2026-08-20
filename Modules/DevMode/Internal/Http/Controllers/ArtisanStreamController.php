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

    // Long-poll SSE loop: tail the run's stdout, emit each new chunk, and
    // close with a terminal `done` event once the detached child's PID is
    // gone or the client disconnects — bounded by STREAM_TIMEOUT_SECONDS.
    private function streamLoop(RunRecord $record, int $startOffset, string $runId): void
    {
        @ini_set('output_buffering', '0');
        @ini_set('zlib.output_compression', '0');
        @ignore_user_abort(true);
        // PHP's max_execution_time is wall-clock — without this the SSE
        // loop is killed at the php.ini default (30s in the shipped
        // nativephp/php-bin) long before STREAM_TIMEOUT_SECONDS.
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

            // A gone PID means the spawner-detached child has finished;
            // emit the terminal `done` event with whatever exit code is
            // recoverable (the audit pipeline reads it authoritatively).
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

    // Flush any last chunk, pin a terminal status in the registry so SSE
    // reconnects observe it, write the authoritative audit row, then emit
    // the SSE `done` event carrying the recoverable exit code.
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

    // Reads once more in case the child wrote a final chunk between the
    // previous tail and its exit; returns the advanced byte offset.
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

    // The finalize audit write happens-before the terminal done event; a
    // failure never propagates out of the SSE handler but is still logged
    // best-effort so an operator can see a corrupt row or a DB error.
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
            // Last-resort no-op — the SSE frame still closes cleanly even
            // if the logger itself cannot be resolved or fails to write.
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

    // Browser EventSource sends Last-Event-ID on auto-reconnect; tests +
    // the run-card "show output" affordance use `?from=` as a manual
    // override. Default 0 replays the whole captured stdout.
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
