<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Dto\AppAction;

/**
 * Default AppActionRegistry concrete bound by DevModeServiceProvider.
 *
 * Returns an empty action list. The 16-08 command-palette plan
 * replaces this binding with `AppActionRegistryImpl`, which mirrors
 * the Phase 15 app-menu entries. The Null shape exists so this plan's
 * tests can resolve the contract without forcing a real
 * implementation.
 */
final class NullAppActionRegistry implements AppActionRegistry
{
    /**
     * @return list<AppAction>
     */
    public function all(): array
    {
        return [];
    }
}
