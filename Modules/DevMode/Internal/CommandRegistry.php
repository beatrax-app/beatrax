<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

// NEVER-EXPOSED commands (migrate, migrate:rollback, db:seed) are kept out of
// the injected spec list rather than filtered here, so the allow-list is the
// only thing standing between the Dev Console and a command.
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
            "Unknown Dev Console command: `{$name}`. The command is either NEVER-EXPOSED or not registered in the SAFE / DESTRUCTIVE allow-list.",
        );
    }
}
