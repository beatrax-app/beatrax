<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
final readonly class ArtisanStreamController
{
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
        $runIdLocal = $runId;
        $registry = $this->registry;
        $tailer = $this->tailer;
        $finalize = $this->finalize;
        $liveness = $this->liveness;

        $response = new StreamedResponse(static function () use (
            $record,
            $startOffset,
            $registry,
            $tailer,
            $runIdLocal,
            $finalize,
            $liveness,
        ): void {
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            @ignore_user_abort(true);
            // PHP's max_execution_time is wall-clock — without this the
            // SSE loop is killed at the php.ini default (30s in the shipped
            // nativephp/php-bin) long before STREAM_TIMEOUT_SECONDS.
            @set_time_limit(0);

            $offset = $startOffset;
            $deadline = microtime(true) + self::STREAM_TIMEOUT_SECONDS;

            while (microtime(true) < $deadline) {
                $result = $tailer->tailOnce($record->outPath, $offset);
                $offset = $result['newOffset'];

                if ($result['chunk'] !== '') {
                    echo 'id: '.$offset."\n";
                    echo 'data: '.json_encode([
                        'line' => $result['chunk'],
                    ], JSON_UNESCAPED_SLASHES)."\n\n";
                    @ob_flush();
                    @flush();
                }

                // A gone PID means the spawner-detached child has
                // finished; emit the terminal `done` event with
                // whatever exit code is recoverable (best-effort — the
                // audit pipeline reads the exit code authoritatively).
                if (! $liveness->isAlive($record->pid)) {
                    // Read once more in case the child wrote a final
                    // chunk between the previous tail and the exit.
                    $finalChunk = $tailer->tailOnce($record->outPath, $offset);
                    if ($finalChunk['chunk'] !== '') {
                        $offset = $finalChunk['newOffset'];
                        echo 'id: '.$offset."\n";
                        echo 'data: '.json_encode([
                            'line' => $finalChunk['chunk'],
                        ], JSON_UNESCAPED_SLASHES)."\n\n";
                    }

                    $fresh = $registry->find($runIdLocal);
                    $exit = $fresh?->exitCode;
                    $cancelled = $fresh?->status === 'cancelled';

                    // Mark finished in the registry so subsequent SSE
                    // reconnects observe a terminal status; FinalizeRunAudit
                    // (below) then writes the dev_mode_audit row that
                    // captures the authoritative exit code + stdout excerpt.
                    if ($fresh !== null && $fresh->status === 'running') {
                        $registry->markFinished($runIdLocal, $exit ?? 0);
                    }

                    // Finalize audit write happens-before the terminal
                    // done event; a failure never propagates out of the
                    // SSE handler but is still logged best-effort so an
                    // operator can see a corrupt row or a DB error.
                    try {
                        ($finalize)($runIdLocal, $exit, $cancelled);
                    } catch (\Throwable $finalizeError) {
                        try {
                            Container::getInstance()
                                ->make(LoggerInterface::class)
                                ->error('FinalizeRunAudit failed for run '.$runIdLocal, [
                                    'exception' => $finalizeError->getMessage(),
                                    'exception_class' => get_class($finalizeError),
                                ]);
                        } catch (\Throwable) {
                            // Last-resort no-op — the SSE frame still
                            // closes cleanly.
                        }
                    }

                    echo "event: done\n";
                    echo 'data: '.json_encode([
                        'exit' => $exit,
                        'cancelled' => $cancelled,
                    ], JSON_UNESCAPED_SLASHES)."\n\n";
                    @ob_flush();
                    @flush();
                    break;
                }

                if (connection_aborted() !== 0) {
                    break;
                }

                usleep(self::TICK_MICROSECONDS);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
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
