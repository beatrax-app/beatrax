<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

// `tier` decides reachability: 'safe' reaches the palette and the runner,
// 'destructive' is runner-only and behind the triple-gate modal.
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
