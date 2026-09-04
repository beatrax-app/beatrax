<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DevMode\Internal\Actions\ReadLogContextWindow;
use Modules\DevMode\Internal\Actions\ReadLogTail;
use Modules\DevMode\Internal\Logging\LogFileStats;

final readonly class LogStreamController
{
    public function __construct(
        private ValidatorFactory $validator,
        private LogFileStats $stats,
        private ReadLogTail $readTail,
        private ReadLogContextWindow $readContext,
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

        return new JsonResponse(($this->readTail)($payload['since'] ?? 0, $payload['inode'] ?? null));
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
                'radius' => ['required', 'integer', 'min:0', 'max:'.ReadLogContextWindow::MAX_RADIUS],
            ],
        )->validate();

        return new JsonResponse(($this->readContext)(
            $payload['date'] ?? null,
            $payload['line'] ?? 0,
            $payload['radius'] ?? 0,
        ));
    }

    // Polled at 3s rather than the tail's 1s, because this one re-parses the
    // whole file rather than reading forward from an offset.
    public function stats(): JsonResponse
    {
        $today = $this->stats->current();
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
}
