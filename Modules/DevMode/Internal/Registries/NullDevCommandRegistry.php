<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

// Lets consumers resolve the contract without a bound() guard when the real
// implementation has not been wired.
final class NullDevCommandRegistry implements DevCommandRegistry
{
    /**
     * @return list<CommandSpec>
     */
    public function safe(): array
    {
        return [];
    }

    /**
     * @return list<CommandSpec>
     */
    public function destructive(): array
    {
        return [];
    }

    public function find(string $name): CommandSpec
    {
        throw new InvalidArgumentException(
            "DevCommandRegistry has no commands registered (null shape). Requested: `{$name}`.",
        );
    }
}
