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

/**
 * GET /dev/logs/poll — single-shot JSON read of any new bytes in the
 * current daily-rolling Laravel log file
 * (storage/logs/laravel-YYYY-MM-DD.log) past the byte offset the
 * client passes in `?since=`. Defense-in-depth re-applies the
 * {@see RedactSecretsProcessor} to every returned chunk; the on-write
 * Monolog tap is the first layer.
 *
 * Returns immediately (~5-50 ms typical) so the single-threaded PHP
 * built-in server NativePHP uses can move on to the next request.
 * The earlier SSE implementation held the server's only worker for
 * up to STREAM_TIMEOUT_SECONDS, which stalled every other in-app
 * navigation (sidebar clicks, image loads, image preloads) until the
 * stream returned. The client now polls this endpoint every second
 * instead — same perceived UX (~1 s latency for new lines) with no
 * blast-radius on the rest of the app.
 *
 * Rotation detection:
 *   - The response includes the file's current inode. The client
 *     persists that inode across polls; the next request passes it
 *     back in `?inode=`.
 *   - An inode change between the client's last value and the
 *     current file is treated as a rotation: the response sets
 *     `reset = true` and the chunk is read from offset 0.
 *   - A `?since` that lies beyond the current file size is also
 *     treated as a rotation (logrotate copytruncate, day rollover).
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
    /** Maximum radius for the context endpoint (bounds a hostile ?radius=). */
    private const MAX_CONTEXT_RADIUS = 50;

    public function __construct(
        private FileTailer $tailer,
        private RedactSecretsProcessor $processor,
        private ValidatorFactory $validator,
    ) {}

    /**
     * Single-shot poll: read any new bytes past `?since=` (with
     * rotation detection via `?inode=`), apply redaction, return JSON.
     * No streaming; no long-running PHP process.
     */
    public function poll(Request $request): JsonResponse
    {
        $payload = $this->validator->make(
            [
                'since' => $request->query('since', '0'),
                'inode' => $request->query('inode'),
            ],
            [
                'since' => ['required', 'integer', 'min:0'],
                'inode' => ['nullable', 'integer', 'min:0'],
            ],
        )->validate();

        $sinceValue = $payload['since'] ?? 0;
        $offset = is_int($sinceValue) ? $sinceValue : (is_numeric($sinceValue) ? (int) $sinceValue : 0);

        $clientInodeRaw = $payload['inode'] ?? null;
        $clientInode = is_int($clientInodeRaw)
            ? $clientInodeRaw
            : (is_numeric($clientInodeRaw) ? (int) $clientInodeRaw : null);

        $path = UserDataPathService::dailyLogFile();
        $currentInode = self::inodeOf($path);
        $currentSize = self::sizeOf($path) ?? 0;

        $reset = false;
        // Rotation detection: inode change (logrotate truncate+rename
        // or midnight day rollover) OR client offset beyond current
        // file size (logrotate copytruncate, fresh subscriber after a
        // long pause). Either way the client must zero its cursor.
        if (($clientInode !== null && $currentInode !== null && $clientInode !== $currentInode)
            || $offset > $currentSize) {
            $offset = 0;
            $reset = true;
        }

        $result = $this->tailer->tailOnce($path, $offset);
        $chunk = $result['chunk'];
        if ($chunk !== '') {
            $chunk = $this->processor->scrub($chunk);
        }

        return new JsonResponse([
            'chunk' => $chunk,
            'newOffset' => $result['newOffset'],
            'inode' => $currentInode,
            'reset' => $reset,
        ]);
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
