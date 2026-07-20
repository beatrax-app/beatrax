<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// SAFE-tier commands are eligible for the command palette AND the
// artisan runner; DESTRUCTIVE-tier commands are runner-only and must
// pass through the triple-gate modal. argsSchema describes each
// positional/option argument the runner UI renders an input for.
final class CommandSpec extends Data
{
    /**
     * @param  list<ArgSpec>  $argsSchema
     * @param  'safe'|'destructive'  $tier
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $tier,
        public readonly array $argsSchema,
        public readonly ?string $description = null,
    ) {}
}
