<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

final class SuggestMappingDto extends Data
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $name,
        public readonly string $region,
        public readonly ?string $category = null,
    ) {}
}
