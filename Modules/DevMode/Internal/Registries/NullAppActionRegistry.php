<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Dto\AppAction;

// Fallback so consumer code can resolve the contract without bound()
// guards when AppActionRegistryImpl has not been wired (ad-hoc tests).
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
