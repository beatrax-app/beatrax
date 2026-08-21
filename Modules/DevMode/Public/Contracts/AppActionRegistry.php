<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\AppAction;

interface AppActionRegistry
{
    // Order is significant: the palette renders rows as they arrive.
    /**
     * @return list<AppAction>
     */
    public function all(): array;
}
