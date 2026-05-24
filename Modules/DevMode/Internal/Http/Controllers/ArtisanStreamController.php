<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Process\FileTailer;
use Modules\DevMode\Internal\Process\RunRegistry;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /dev/artisan/stream/{runId} — SSE tail of one run's stdout.
 *
 * Adopts the cached RunRecord via RunRegistry::find() and tails the
 * per-run tmp file with the shared {@see FileTailer} primitive. The
 * loop:
 *   - clearstatcache + fseek + fread up to 65 536 bytes per tick.
 *   - Emit `id: <offset>\ndata: {"line":"..."}\n\n` for each chunk.
 *   - posix_kill($pid, 0) liveness check; if the process is gone,
 *     emit `event: done\ndata: {"exit":...}\n\n` and break.
 *   - usleep(150_000) between ticks (~6.7 ticks/s; well under the
 *     SSE per-event budget).
 *
 * CONTEXT D-16 verbatim: "a page refresh reconnects to the **live
 * stream**." The browser's EventSource auto-reconnects with the
 * `Last-Event-ID` header equal to the offset of the last delivered
 * event; this controller honors it (along with a `?from=` query
 * param fallback for manual tests + the future "show output"
 * affordance from a run-card). A reconnect that sets the offset to
 * the last-seen byte sees ONLY lines emitted after the reconnect
 * point — proving the live-stream guarantee.
 *
 * Cross-user inspection (T-16-15): the controller rejects with 403
 * when the requesting developer is not the original spawner. A
 * forged runId still produces 404 (records are unguessable UUIDs).
 *
 * Pitfall 4 (output buffering): we set X-Accel-Buffering: no and
 * ob_flush() + flush() per emitted chunk so Nginx + PHP-FPM do not
 * buffer the stream into useless silence.
 */
final readonly class ArtisanStreamController
{
    /** Sleep interval between tail ticks. */
    private const TICK_MICROSECONDS = 150_000;

    /** Maximum wall-clock seconds before the stream gracefully closes. */
    private const STREAM_TIMEOUT_SECONDS = 600;

    public function __construct(
        private RunRegistry $registry,
        private FileTailer $tailer,
        private FinalizeRunAudit $finalize,
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

        $response = new StreamedResponse(static function () use (
            $record,
            $startOffset,
            $registry,
            $tailer,
            $runIdLocal,
            $finalize,
        ): void {
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            @ignore_user_abort(true);

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

                // Liveness check via posix_kill(pid, 0). If the process is
                // gone, the spawner-detached child has finished — emit the
                // terminal `done` event with whatever exit code we can
                // recover (best-effort; 16-04b's audit pipeline reads the
                // exit code authoritatively).
                if (! posix_kill($record->pid, 0)) {
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
                    // reconnects observe a terminal status. 16-04b's
                    // FinalizeRunAudit hook (invoked below) writes the
                    // dev_mode_audit row that captures the authoritative
                    // exit code + the redacted stdout excerpt.
                    if ($fresh !== null && $fresh->status === 'running') {
                        $registry->markFinished($runIdLocal, $exit ?? 0);
                    }

                    // Audit pipeline hook: write the dev_mode_audit row
                    // BEFORE emitting the terminal `event: done` so the
                    // client observes a strict happens-before between
                    // "audit row exists" and "browser sees stream
                    // closed".
                    //
                    // A finalize failure must NEVER propagate out of
                    // the SSE handler — the client always gets a clean
                    // terminal frame. But the failure also must NOT be
                    // silently swallowed: a corrupt audit row, a DB
                    // permission error, or a missing
                    // dev_mode_audit table needs to surface somewhere
                    // an operator will see it. Best-effort log to the
                    // PSR LoggerInterface via the container; if even
                    // that fails (boot-time logger absent), give up
                    // silently rather than break the SSE frame.
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

    /**
     * Resolve the starting byte offset. Browser EventSource sends the
     * Last-Event-ID header on auto-reconnect; tests + the run-card
     * "show output" affordance use `?from=` as a manual override.
     * Default 0 — a fresh subscription replays the whole captured
     * stdout (the tmp file persists for the 24h cache TTL).
     */
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
