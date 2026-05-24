<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

/**
 * Null-shape NavigationRegistry concrete.
 *
 * Returns an empty entry list. Exists as a fallback so consumer
 * code can resolve the contract from the container without bound()
 * guards when the curated roster (NavigationRegistryImpl) has not
 * been wired — e.g. in ad-hoc unit tests.
 */
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
