<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class CorpusEntryDto extends Data
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $generalizedPattern,
        public readonly string $name,
        public readonly ?string $category,
        public readonly ?string $region,
        public readonly string $contributor,
    ) {}
}
