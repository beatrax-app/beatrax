<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Dto;

use Spatie\LaravelData\Data;

final class RuleInput extends Data
{
    // $conditions/$actions are untrusted, caller-supplied arrays; the
    // create/update actions validate every element field-by-field before
    // use, so this carrier makes no shape guarantee beyond "list of maps".
    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public readonly int $priority,
        public readonly string $combinator,
        public readonly bool $active,
        public readonly ?string $notes,
        public readonly array $conditions,
        public readonly array $actions,
    ) {}
}
