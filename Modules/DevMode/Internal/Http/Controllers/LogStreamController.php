<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\LogFileStats;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Process\FileTailer;
use SplFileObject;

final readonly class LogStreamController
{
    use CoercesScalars;

    private const MAX_CONTEXT_RADIUS = 50;

    public function __construct(
        private FileTailer $tailer,
        private RedactSecretsProcessor $processor,
        private ValidatorFactory $validator,
        private LogFileStats $stats,
    ) {}

    // A single-shot poll, deliberately not a stream: no PHP process is left
    // running between polls.
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
        $offset = self::toInt($sinceValue);

        $clientInode = self::nullableInt($payload['inode'] ?? null);

        $path = UserDataPathService::dailyLogFile();
        $currentInode = self::inodeOf($path);
        $currentSize = self::sizeOf($path) ?? 0;

        $reset = false;
        // Two rotation shapes: a new inode (truncate+rename, day rollover) and
        // an offset past EOF (copytruncate). Both mean "zero your cursor".
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
        $targetLine = self::toInt($lineValue);

        $radiusValue = $payload['radius'] ?? 0;
        $radius = self::toInt($radiusValue);

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

        // Clamped before the window is sized: an out-of-range ?line=999999
        // against a 5-line file would otherwise give start > end and no rows.
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

    // Polled at 3s rather than the tail's 1s, because this one re-parses the
    // whole file rather than reading forward from an offset.
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

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
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
