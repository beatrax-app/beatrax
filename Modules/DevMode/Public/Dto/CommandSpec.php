<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One artisan command the Dev Console knows about.
 *
 * `tier` is one of 'safe' / 'destructive'. SAFE-tier commands are
 * eligible for the command palette AND the artisan runner;
 * DESTRUCTIVE-tier commands are runner-only and must pass through
 * the triple-gate modal (typed-name confirmation +
 * disabled-until-match primary). The argsSchema describes each
 * positional / option argument the runner UI renders an input for;
 * an empty list means the command takes no arguments.
 *
 * The concrete CommandRegistry hard-codes the SAFE + DESTRUCTIVE
 * roster; this DTO is the cross-module exchange shape every
 * consumer reads.
 *
 * @param  list<ArgSpec>  $argsSchema
 */
final class CommandSpec extends Data
{
    /**
     * @param  list<ArgSpec>  $argsSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        /** 'safe' | 'destructive' */
        public readonly string $tier,
        public readonly array $argsSchema,
        public readonly ?string $description = null,
    ) {}
}
