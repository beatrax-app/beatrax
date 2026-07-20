<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

// Fallback so consumer code can resolve the contract without bound()
// guards when the real CommandRegistry binding has not been wired
// (ad-hoc tests); find($name) always throws.
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
