<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

final class RuleActionDto extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $position,
        public readonly string $type,
        public readonly array $payload,
        public readonly ?string $categoryPath,
        public readonly ?string $counterpartyName,
    ) {}
}
