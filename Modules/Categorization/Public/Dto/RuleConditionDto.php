<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

final class RuleConditionDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $field,
        public readonly string $op,
        public readonly string $valueType,
        public readonly string $value,
        public readonly ?string $value2,
    ) {}
}
