<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Process\FileTailer;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /dev/logs/stream — SSE tail of the current daily-rolling
 * Laravel log file (storage/logs/laravel-YYYY-MM-DD.log).
 * Defense-in-depth re-applies the {@see RedactSecretsProcessor} on
 * every emitted chunk; the on-write Monolog tap is the first layer.
 *
 * Architecture is the same spawn-then-tail SSE shape
 * ArtisanStreamController uses, minus the spawn — this controller
 * adopts an existing file (the Laravel log) and tails it forever.
 * Reuses the shared {@see FileTailer} primitive so both SSE
 * pipelines live on one tested seam.
 *
 * Rotation detection:
 *   - On each tick the controller `stat()`s the path + compares
 *     inode and current filesize() to the last-observed values.
 *   - An inode change OR filesize() < lastSize (truncation) is a
 *     rotation: the controller computes the new day's filename via
 *     {@see UserDataPathService::dailyLogFile()} and reopens at
 *     offset 0.
 *   - At a midnight day-boundary the daily handler closes the old
 *     file and creates the new YYYY-MM-DD file; the controller
 *     detects this because dailyLogFile()'s on-disk path changes
 *     and the old inode disappears.
 *
 * Path safety: the log file path is computed from
 * {@see UserDataPathService::dailyLogFile()} which respects the
 * NATIVEPHP_STORAGE_PATH retarget AND has no user-controlled input
 * — no LFI surface.
 *
 * GET /dev/logs/context — paired JSON endpoint that returns the
 * ±radius lines surrounding the given absolute line offset
 * (click-to-expand). Offset is clamped to the file's valid line
 * range; radius is clamped 0..MAX_CONTEXT_RADIUS. Same redaction
 * is re-applied to every returned line.
 */
final readonly class LogStreamController
{
    /** Sleep interval between tail ticks (250 ms). */
    private const TICK_MICROSECONDS = 250_000;

    /** Maximum wall-clock seconds before the stream gracefully closes. */
    private const STREAM_TIMEOUT_SECONDS = 600;

    /** Maximum radius for the context endpoint (bounds a hostile ?radius=). */
    private const MAX_CONTEXT_RADIUS = 50;

    public function __construct(
        private FileTailer $tailer,
        private RedactSecretsProcessor $processor,
        private ValidatorFactory $validator,
    ) {}

    /**
     * SSE tail of the rolling daily log. Defense-in-depth redaction is
     * applied per chunk; rotation detection re-opens the new day's file
     * at offset 0.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $startOffset = $this->resolveStartOffset($request);

        $tailer = $this->tailer;
        $processor = $this->processor;

        $response = new StreamedResponse(static function () use (
            $startOffset,
            $tailer,
            $processor,
        ): void {
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            @ignore_user_abort(true);

            $currentPath = UserDataPathService::dailyLogFile();
            $currentInode = self::inodeOf($currentPath);
            $offset = $startOffset;

            // Cap initial offset at the current file size — a stale
            // Last-Event-ID from a previous day's much-bigger file
            // would otherwise sit beyond EOF forever.
            $currentSize = self::sizeOf($currentPath);
            if ($currentSize !== null && $offset > $currentSize) {
                $offset = $currentSize;
            }

            $deadline = microtime(true) + self::STREAM_TIMEOUT_SECONDS;

            while (microtime(true) < $deadline) {
                $todayPath = UserDataPathService::dailyLogFile();
                $newInode = self::inodeOf($todayPath);
                $newSize = self::sizeOf($todayPath);

                // Rotation detection: the path changed (midnight
                // rollover), OR the inode changed (logrotate-style
                // truncate + rename), OR the file shrank
                // (logrotate-style copytruncate).
                if ($todayPath !== $currentPath
                    || ($newInode !== null && $currentInode !== null && $newInode !== $currentInode)
                    || ($newSize !== null && $currentSize !== null && $newSize < $currentSize)) {
                    $currentPath = $todayPath;
                    $currentInode = $newInode;
                    $currentSize = $newSize;
                    $offset = 0;
                }

                $result = $tailer->tailOnce($currentPath, $offset);
                $offset = $result['newOffset'];
                $currentSize = self::sizeOf($currentPath);

                if ($result['chunk'] !== '') {
                    $scrubbed = $processor->scrub($result['chunk']);
                    echo 'id: '.$offset."\n";
                    echo 'data: '.json_encode([
                        'line' => $scrubbed,
                    ], JSON_UNESCAPED_SLASHES)."\n\n";
                    @ob_flush();
                    @flush();
                }

                if (connection_aborted() !== 0) {
                    break;
                }

                usleep(self::TICK_MICROSECONDS);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        // no-transform pairs with no-cache so an intermediate proxy
        // never gzip-buffers the SSE response into unpredictable
        // chunks. Local-only Herd does not need it today; the header
        // is cheap insurance against a future proxied deployment.
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * GET /dev/logs/context?date=YYYY-MM-DD&line=N&radius=10
     *
     * Returns the requested line and ±radius surrounding lines from
     * the daily log for the requested date. `radius` clamps to
     * [0, MAX_CONTEXT_RADIUS]; `line` clamps to [0, lineCount-1] via
     * SplFileObject natural EOF semantics — no LFI surface.
     */
    public function context(Request $request): JsonResponse
    {
        $payload = $this->validator->make(
            [
                'date' => $request->query('date'),
                'line' => $request->query('line', '0'),
                'radius' => $request->query('radius', '10'),
            ],
            [
                'date' => ['nullable', 'date_format:Y-m-d'],
                'line' => ['required', 'integer', 'min:0'],
                'radius' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CONTEXT_RADIUS],
            ],
        )->validate();

        $dateStr = $payload['date'] ?? null;
        $date = is_string($dateStr) && $dateStr !== '' ? new DateTimeImmutable($dateStr) : new DateTimeImmutable;

        $lineValue = $payload['line'] ?? 0;
        $targetLine = is_int($lineValue) ? $lineValue : (is_numeric($lineValue) ? (int) $lineValue : 0);

        $radiusValue = $payload['radius'] ?? 0;
        $radius = is_int($radiusValue) ? $radiusValue : (is_numeric($radiusValue) ? (int) $radiusValue : 0);

        $path = UserDataPathService::dailyLogFile($date);

        if (! is_file($path) || ! is_readable($path)) {
            return new JsonResponse([
                'date' => $date->format('Y-m-d'),
                'line' => $targetLine,
                'radius' => $radius,
                'lines' => [],
                'total' => 0,
            ]);
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::DROP_NEW_LINE);

        // SplFileObject has no cheap line count short of iterating;
        // seek to end + key() returns the last line index (0-based).
        $file->seek(PHP_INT_MAX);
        $total = $file->key() + 1;

        // Clamp the requested line to the file's valid range before
        // sizing the radius window so an out-of-range ?line=999999
        // against a 5-line file returns the tail context the operator
        // can see, not an empty array (start > end edge case).
        $targetLine = min(max(0, $targetLine), max(0, $total - 1));

        $start = max(0, $targetLine - $radius);
        $end = min($total - 1, $targetLine + $radius);

        $out = [];
        $file->seek($start);
        for ($i = $start; $i <= $end; $i++) {
            $line = $file->current();
            if (! is_string($line)) {
                break;
            }
            $out[] = [
                'index' => $i,
                'text' => $this->processor->scrub($line),
            ];
            $file->next();
        }

        return new JsonResponse([
            'date' => $date->format('Y-m-d'),
            'line' => $targetLine,
            'radius' => $radius,
            'lines' => $out,
            'total' => $total,
        ]);
    }

    /**
     * Resolve the starting byte offset. Browser EventSource sends
     * `Last-Event-ID` on auto-reconnect; tests + manual debugging use
     * `?from=` as an explicit override. Default 0 — fresh subscribers
     * replay everything captured so far in the current day's file (the
     * file lives on disk; the tailer respects the byte cursor).
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

    /**
     * Return the file's inode, or null if the file does not exist.
     */
    private static function inodeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        return $stat['ino'];
    }

    /**
     * Return the file's size, or null if the file does not exist.
     */
    private static function sizeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return null;
        }
        $size = @filesize($path);

        return $size === false ? null : $size;
    }
}
