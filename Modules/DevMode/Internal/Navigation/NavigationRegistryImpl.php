<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Navigation;

use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

/**
 * Concrete `NavigationRegistry` (16-08).
 *
 * Holds the curated list of authenticated app views the command
 * palette can jump to. Built as a `final readonly class` so the
 * roster is immutable once the singleton resolves — the binding
 * factory inside `DevModeServiceProvider::register()` passes the full
 * list of `NavigationEntry` rows (every authenticated nav surface
 * shipped by Phase 1-15, plus the Dev Console sub-routes registered
 * by 16-03..16-07; the dev rows are tagged with the `dev.` route name
 * prefix in their `id` so the palette modal can filter them at
 * JSON-emit time by `is_developer`).
 *
 * Replaces the `NullNavigationRegistry` binding 16-03 shipped.
 *
 * Follows the same shape as `MatcherRegistry` (Modules/Receipts) per
 * PATTERNS.md — a concrete-registry wrapper around a single
 * immutable list with `all()` as the read accessor. A `register()`
 * mutator is omitted; if a future cross-module flow needs to
 * register at runtime, swap to a non-readonly variant + a list-
 * accumulating method.
 */
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
