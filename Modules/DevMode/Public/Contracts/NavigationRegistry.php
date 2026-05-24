<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\NavigationEntry;

/**
 * Registry of every authenticated app view the command palette can
 * jump to.
 *
 * The palette's "view" source chip (UI-SPEC § Command palette) draws
 * rows from this registry; the concrete `NavigationRegistryImpl`
 * lands in 16-08 and enumerates each `Route::has(...)`-registered
 * authenticated view. This module's `NullNavigationRegistry` returns
 * an empty list so the binding shape is in place from day one.
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
