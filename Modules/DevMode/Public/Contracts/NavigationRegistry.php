<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\NavigationEntry;

interface NavigationRegistry
{
    /**
     * @return list<NavigationEntry>
     */
    public function all(): array;
}
