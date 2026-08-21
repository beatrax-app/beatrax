<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\CommandSpec;

// The two lists are the allow-list: a command absent from both cannot be run,
// and anything in destructive() needs the triple-gate modal before it fires.
interface DevCommandRegistry
{
    /**
     * @return list<CommandSpec>
     */
    public function safe(): array;

    /**
     * @return list<CommandSpec>
     */
    public function destructive(): array;

    /**
     * @throws \InvalidArgumentException when the command is not registered.
     */
    public function find(string $name): CommandSpec;
}
