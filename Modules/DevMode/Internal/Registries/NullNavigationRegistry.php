<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

// Fallback so consumer code can resolve the contract without bound()
// guards when NavigationRegistryImpl has not been wired (ad-hoc tests).
final class NullNavigationRegistry implements NavigationRegistry
{
    /**
     * @return list<NavigationEntry>
     */
    public function all(): array
    {
        return [];
    }
}
