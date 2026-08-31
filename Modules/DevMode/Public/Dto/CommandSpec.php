<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Modules\DevMode\Internal\Enums\CommandTier;
use Spatie\LaravelData\Data;

// `name` is the artisan identifier and stays English. The two visible strings
// ride as KEYS: the registry is a container singleton, so a word resolved when
// it was built would be whichever language got there first.
final class CommandSpec extends Data
{
    /**
     * @param  list<ArgSpec>  $argsSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $labelKey,
        public readonly CommandTier $tier,
        public readonly array $argsSchema,
        public readonly ?string $descriptionKey = null,
    ) {}
}
