<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class ClassificationRule
{
    public function __construct(
        public readonly string $pattern,
        public readonly ?string $name,
        public readonly string $region,
    ) {}
}
