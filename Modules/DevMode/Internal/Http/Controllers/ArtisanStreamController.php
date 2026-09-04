<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Actions\SettleFinishedRun;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The pump below stays here on purpose: an SSE frame, its id, and the flush
// that pushes it are the response being written, not work the response
// reports on. What the run's completion MEANS is the action's.
final readonly class ArtisanStreamController
{
    private const string SSE_DATA_PREFIX = 'data: ';

    private const int TICK_MICROSECONDS = 150_000;

    private const int STREAM_TIMEOUT_SECONDS = 600;

    public function __construct(
        private RunRegistry $registry,
        private FileTailer $tailer,
        private ProcessLiveness $liveness,
        private SettleFinishedRun $settle,
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

        $response = new StreamedResponse(function () use ($record, $startOffset): void {
            $this->streamLoop($record, $startOffset);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function streamLoop(RunRecord $record, int $startOffset): void
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
            $offset = $this->emitAvailable($record->outPath, $offset);

            // A gone PID is the only completion signal a detached child
            // gives; the audit pipeline owns the authoritative exit code.
            if (! $this->liveness->isAlive($record->pid)) {
                $this->emitTerminal($record, $offset);

                return;
            }

            if (connection_aborted() !== 0) {
                return;
            }

            usleep(self::TICK_MICROSECONDS);
        }
    }

    private function emitAvailable(string $outPath, int $offset): int
    {
        $result = $this->tailer->tailOnce($outPath, $offset);
        if ($result['chunk'] === '') {
            return $offset;
        }

        $this->writeFrame(
            'id: '.$result['newOffset']."\n"
            .self::SSE_DATA_PREFIX.json_encode(['line' => $result['chunk']], JSON_UNESCAPED_SLASHES)."\n\n",
        );

        return $result['newOffset'];
    }

    private function emitTerminal(RunRecord $record, int $offset): void
    {
        $this->emitAvailable($record->outPath, $offset);

        $settled = ($this->settle)($record->runId, $record->outPath);

        $this->writeFrame(
            "event: done\n"
            .self::SSE_DATA_PREFIX.json_encode($settled, JSON_UNESCAPED_SLASHES)."\n\n",
        );
    }

    private function writeFrame(string $frame): void
    {
        echo $frame;
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
