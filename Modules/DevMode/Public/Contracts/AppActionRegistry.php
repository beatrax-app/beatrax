<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\AppAction;

// The concrete AppActionRegistryImpl mirrors the app-menu entries;
// NullAppActionRegistry returns an empty list as the ad-hoc-test fallback.
interface AppActionRegistry
{
    // Ordering is the order the palette renders rows in, so the
    // concrete impl owns the curation.
    /**
     * @return list<AppAction>
     */
    public function all(): array;
}
