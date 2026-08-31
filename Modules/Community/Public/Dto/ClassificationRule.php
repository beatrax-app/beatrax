<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

final readonly class ClassificationRule
{
    public function __construct(
        public string $pattern,
        public ?string $name,
        public string $region,
    ) {}
}
