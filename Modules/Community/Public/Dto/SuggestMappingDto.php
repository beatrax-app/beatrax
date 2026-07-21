<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class SuggestMappingDto extends Data
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $name,
        public readonly ?string $category = null,
        public readonly string $region = 'NL',
    ) {}
}
