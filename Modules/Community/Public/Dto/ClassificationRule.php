<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

final readonly class ClassificationRule
{
    /**
     * @param  string|null  $name  the rule's own display wording, in the region's language
     * @param  string|null  $key  what that wording NAMES, for a reader who does not read it
     */
    public function __construct(
        public string $pattern,
        public ?string $name,
        public string $region,
        public ?string $key = null,
    ) {}
}
