<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use InvalidArgumentException;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * Default DevCommandRegistry concrete bound by DevModeServiceProvider.
 *
 * Returns empty SAFE and DESTRUCTIVE lists; `find($name)` always
 * throws. The 16-04 audit-pipeline plan replaces this binding with
 * `DevCommandRegistryImpl` (which hard-codes the CONTEXT D-12 + D-13
 * allow-lists). The Null shape exists so this plan's tests can
 * resolve the contract without forcing a real implementation.
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
            "DevCommandRegistry has no command registered yet — wave 5 (plan 16-04) installs the real DevCommandRegistryImpl that hosts the SAFE + DESTRUCTIVE lists. Requested: `{$name}`.",
        );
    }
}
