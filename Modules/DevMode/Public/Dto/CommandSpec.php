<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Modules\DevMode\Internal\Enums\CommandTier;
use Spatie\LaravelData\Data;

final class CommandSpec extends Data
{
    /**
     * @param  list<ArgSpec>  $argsSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly CommandTier $tier,
        public readonly array $argsSchema,
        public readonly ?string $description = null,
    ) {}
}
