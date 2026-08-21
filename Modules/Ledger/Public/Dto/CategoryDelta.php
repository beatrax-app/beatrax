<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// deltaMinor = current − previous, so positive means spent more.
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
