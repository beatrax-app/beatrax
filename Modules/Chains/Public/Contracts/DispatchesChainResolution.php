<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Contracts;

interface DispatchesChainResolution
{
    public function dispatchForUser(int $userId): void;
}
