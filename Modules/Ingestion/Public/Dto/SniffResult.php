<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * The output of HeaderSniffer::sniff() — the upload wizard reads $format and
 * $encoding to render confirmation copy in the preview screen before any
 * parser touches the file.
 */
final class SniffResult extends Data
{
    public function __construct(
        public readonly string $format,
        public readonly string $delimiter,
        public readonly bool $hasHeader,
        public readonly string $encoding,
        public readonly int $columnCount,
    ) {}
}
