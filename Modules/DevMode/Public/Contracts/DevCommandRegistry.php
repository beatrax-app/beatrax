<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\CommandSpec;

// safe() populates the command palette + artisan runner's SAFE list;
// destructive() populates the runner's DESTRUCTIVE list (triple-gate
// modal required before firing). CommandRegistry hard-codes both
// allow-lists; NullDevCommandRegistry is the ad-hoc-test fallback.
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
