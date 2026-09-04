<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

final class MarkupToken
{
    public function __construct(
        public readonly string $name,
        public readonly bool $closing,
        public readonly bool $empty,
        public readonly int $start,
        public readonly int $end,
    ) {}
}
