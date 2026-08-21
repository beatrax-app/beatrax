<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

// Lets consumers resolve the contract without a bound() guard when the real
// implementation has not been wired.
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
