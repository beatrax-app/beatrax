<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// deltaMinor is current − previous, never the reverse: a positive delta
// means more was spent this period than last.
final class CategoryDelta extends Data
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly int $currentMinor,
        public readonly int $previousMinor,
        public readonly int $deltaMinor,
    ) {}

    public function direction(): string
    {
        return match (true) {
            $this->deltaMinor > 0 => 'up',
            $this->deltaMinor < 0 => 'down',
            default => 'flat',
        };
    }
}
