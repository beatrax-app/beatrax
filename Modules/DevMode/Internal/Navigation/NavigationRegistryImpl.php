<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

final readonly class NavigationRegistryImpl implements NavigationRegistry
{
    /** @var list<NavigationEntry> */
    private array $entries;

    /**
     * @param  list<NavigationEntry>  $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return list<NavigationEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
