<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * Concrete DevCommandRegistry implementation.
 *
 * Holds the full SAFE (9) + DESTRUCTIVE (6) roster the Dev Console
 * surfaces, sourced from CONTEXT D-12 + D-13 (post-rename names).
 * The CONTEXT D-14 NEVER-EXPOSED commands (`migrate`,
 * `migrate:rollback`, `db:seed`) are deliberately absent — the
 * spawner whitelists against `find()` so any attempt to spawn one of
 * them throws InvalidArgumentException at the controller layer
 * before a Process is constructed.
 *
 * The spec list is supplied at construction time so the actual
 * roster lives in the DevModeServiceProvider singleton factory
 * (one-place reviewable + bindable from tests). This class is the
 * partitioning + lookup primitive; the rosters are configuration.
 */
final readonly class CommandRegistry implements DevCommandRegistry
{
    /** @var list<CommandSpec> */
    private array $specs;

    /**
     * @param  list<CommandSpec>  $specs
     */
    public function __construct(array $specs)
    {
        $this->specs = $specs;
    }

    /**
     * @return list<CommandSpec>
     */
    public function safe(): array
    {
        $safe = [];
        foreach ($this->specs as $spec) {
            if ($spec->tier === 'safe') {
                $safe[] = $spec;
            }
        }

        return $safe;
    }

    /**
     * @return list<CommandSpec>
     */
    public function destructive(): array
    {
        $destructive = [];
        foreach ($this->specs as $spec) {
            if ($spec->tier === 'destructive') {
                $destructive[] = $spec;
            }
        }

        return $destructive;
    }

    public function find(string $name): CommandSpec
    {
        foreach ($this->specs as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        throw new InvalidArgumentException(
            "Unknown Dev Console command: `{$name}`. The command is either NEVER-EXPOSED (CONTEXT D-14) or not registered in the SAFE / DESTRUCTIVE allow-list.",
        );
    }
}
