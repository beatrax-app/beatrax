<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Contracts;

/**
 * @link ../../../../.docs/features/chains/architecture.md
 */
interface DispatchesChainResolution
{
    public function dispatchForUser(int $userId): void;
}
