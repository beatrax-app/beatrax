<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\LogFileStats;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Process\FileTailer;
use SplFileObject;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
final readonly class LogStreamController
{
    private const MAX_CONTEXT_RADIUS = 50;

    public function __construct(
        private FileTailer $tailer,
        private RedactSecretsProcessor $processor,
        private ValidatorFactory $validator,
        private LogFileStats $stats,
    ) {}

    // Single-shot poll: read new bytes past `?since=` (with rotation
    // detection via `?inode=`), apply redaction, return JSON — no
    // streaming, no long-running PHP process.
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

    // Returns the requested line and +/-radius surrounding lines from
    // the daily log for the requested date; `line` clamps to
    // [0, lineCount-1] via SplFileObject natural EOF semantics.
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

    // The page polls this on a slower cadence (3s) than the main 1s
    // tail poll so the O(N) file parse cost only fires when the
    // operator is looking.
    public function stats(): JsonResponse
    {
        $today = $this->stats->forToday();
        $all = $this->stats->allFiles();

        return new JsonResponse([
            'today' => [
                'path' => $today['path'],
                'exists' => $today['exists'],
                'sizeBytes' => $today['sizeBytes'],
                'totalLines' => $today['totalLines'],
                'parsedLines' => $today['parsedLines'],
                'perSeverity' => $today['perSeverity'],
                'capped' => $today['capped'],
            ],
            'allFiles' => [
                'count' => $all['count'],
                'totalBytes' => $all['totalBytes'],
            ],
        ]);
    }

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
