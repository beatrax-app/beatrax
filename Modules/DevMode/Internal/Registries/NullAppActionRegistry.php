<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Registries;

use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Dto\AppAction;

// Lets consumers resolve the contract without a bound() guard when the real
// implementation has not been wired.
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
