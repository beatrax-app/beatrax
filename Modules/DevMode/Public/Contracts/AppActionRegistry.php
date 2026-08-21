<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\AppAction;

interface AppActionRegistry
{
    /**
     * @return list<AppAction>
     */
    public function all(): array;
}
