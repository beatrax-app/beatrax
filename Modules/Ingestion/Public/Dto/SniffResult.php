<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Dto;

use Spatie\LaravelData\Data;

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
