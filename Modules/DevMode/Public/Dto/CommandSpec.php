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
     * @param  list<string>  $fixedFlags  options the runner always appends, for a
     *                                    command whose non-interactive path needs
     *                                    a flag no operator is asked to choose
     */
    public function __construct(
        public readonly string $name,
        public readonly string $labelKey,
        public readonly CommandTier $tier,
        public readonly array $argsSchema,
        public readonly ?string $descriptionKey = null,
        public readonly array $fixedFlags = [],
    ) {}
}
