<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

/**
 * Default NavigationRegistry concrete bound by DevModeServiceProvider.
 *
 * Returns an empty entry list. The 16-08 command-palette plan
 * replaces this binding with `NavigationRegistryImpl`, which
 * enumerates every `Route::has(...)`-registered authenticated app
 * view. The Null shape exists so this plan's tests can resolve the
 * contract without forcing a real implementation.
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
