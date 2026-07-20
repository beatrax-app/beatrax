<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\NavigationEntry;

// The palette's "view" source chip draws rows from this registry;
// NullNavigationRegistry returns an empty list as a fallback for ad-hoc
// unit tests that don't boot the full provider.
interface NavigationRegistry
{
    // Ordering is the order the palette renders rows in, so the
    // concrete impl owns the curation.
    /**
     * @return list<NavigationEntry>
     */
    public function all(): array;
}
