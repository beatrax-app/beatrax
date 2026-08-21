<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Services;

use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;

/**
 * @internal Bound to the public contract — call sites inject the
 *           contract, never this class directly.
 */
final class BusChainResolutionDispatcher implements DispatchesChainResolution
{
    public function dispatchForUser(int $userId): void
    {
        ResolveChainLinksJob::dispatchSync($userId);
    }
}
