<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Dto\AppAction;

/**
 * Null-shape AppActionRegistry concrete.
 *
 * Returns an empty action list. Exists as a fallback so consumer
 * code can resolve the contract from the container without bound()
 * guards when the curated roster (AppActionRegistryImpl) has not
 * been wired — e.g. in ad-hoc unit tests.
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
