<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\AppAction;

/**
 * Registry of every named app action the command palette can fire.
 *
 * Concrete `AppActionRegistryImpl` lands in 16-08 (palette plan) and
 * mirrors the Phase 15 app-menu entries. This module's
 * `NullAppActionRegistry` returns an empty list so the binding shape
 * is in place from day one.
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
