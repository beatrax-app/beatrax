<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\NavigationEntry;

/**
 * Registry of every authenticated app view the command palette can
 * jump to.
 *
 * The palette's "view" source chip draws rows from this registry;
 * NavigationRegistryImpl enumerates each registered authenticated
 * view. NullNavigationRegistry returns an empty list as a fallback
 * for ad-hoc unit tests that don't boot the full provider.
 */
interface NavigationRegistry
{
    /**
     * Every authenticated app view the palette can jump to. Ordering is
     * the order the palette renders rows in (so the concrete impl owns
     * the curation).
     *
     * @return list<NavigationEntry>
     */
    public function all(): array;
}
