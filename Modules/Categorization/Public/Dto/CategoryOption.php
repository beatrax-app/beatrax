<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

// `path` is the flattened breadcrumb (e.g. "Subscriptions / Streaming")
// so the Blade picker renders without an additional parent lookup.
final class CategoryOption extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly int $displayOrder,
    ) {}
}
