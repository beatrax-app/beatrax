<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * Null-shape DevCommandRegistry concrete.
 *
 * Returns empty SAFE and DESTRUCTIVE lists; find($name) always
 * throws. Exists as a fallback so consumer code can resolve the
 * contract from the container without bound() guards when the real
 * roster (DevModeServiceProvider's CommandRegistry binding) has not
 * been wired — e.g. in ad-hoc unit tests.
 */
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
