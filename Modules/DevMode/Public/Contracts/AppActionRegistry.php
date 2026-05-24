<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\AppAction;

/**
 * Registry of every named app action the command palette can fire.
 *
 * The concrete AppActionRegistryImpl mirrors the app-menu entries;
 * NullAppActionRegistry returns an empty list as a fallback for
 * ad-hoc unit tests that don't boot the full provider.
 */
interface AppActionRegistry
{
    /**
     * Every app action the palette can fire. Ordering is the order the
     * palette renders rows in (so the concrete impl owns the
     * curation).
     *
     * @return list<AppAction>
     */
    public function all(): array;
}
