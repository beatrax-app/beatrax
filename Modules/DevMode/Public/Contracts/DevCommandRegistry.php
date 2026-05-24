<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Contracts;

use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * Registry of every artisan command the Dev Console exposes.
 *
 * The Dev Console UI calls `safe()` to populate the command palette
 * (SAFE-tier commands are eligible for palette discovery + the
 * artisan runner) and `destructive()` to populate the runner's
 * DESTRUCTIVE-tier list (those go through the triple-gate modal
 * before they fire). `find($name)` resolves a single spec by name and
 * throws when the lookup misses, so callers do not have to nest
 * null-checks.
 *
 * CONTEXT D-12 + D-13 lock the SAFE and DESTRUCTIVE allow-lists; the
 * concrete `DevCommandRegistryImpl` (lands in 16-04) hard-codes those
 * lists. The Null* concrete bound by this module's ServiceProvider
 * returns empty lists so the binding shape is in place from day one
 * (this plan) without forcing every consumer to ship a real
 * implementation.
 */
interface DevCommandRegistry
{
    /**
     * SAFE-tier commands eligible for the command palette + artisan
     * runner. Ordering is the order the UI renders entries in (so the
     * concrete impl owns the curation).
     *
     * @return list<CommandSpec>
     */
    public function safe(): array;

    /**
     * DESTRUCTIVE-tier commands eligible for the artisan runner only.
     * Every invocation MUST pass through the triple-gate modal before
     * the runner dispatches the underlying process.
     *
     * @return list<CommandSpec>
     */
    public function destructive(): array;

    /**
     * Resolve a single spec by its command name (the `protected $signature`
     * string the artisan kernel exposes, e.g. `beatrax:doctor`). Throws
     * when no SAFE or DESTRUCTIVE entry matches.
     *
     * @throws \InvalidArgumentException when the command is not registered.
     */
    public function find(string $name): CommandSpec;
}
