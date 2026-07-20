<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

// Immutable once the singleton resolves — the binding factory inside
// DevModeServiceProvider::register() passes the full NavigationEntry
// list. Dev rows are tagged with the `dev.` route-name prefix in their
// id so the palette modal can filter them at JSON-emit time.
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
