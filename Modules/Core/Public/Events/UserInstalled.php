<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

/**
 * Dispatched by the `beatrax:install` command whenever it confirms the
 * user account — both when a new user row is created AND when the command
 * re-runs against an already-installed account. Listeners (e.g. the
 * default-category-tree seeder in the Categorization module) MUST be
 * idempotent: dispatching this event for an existing user is the
 * mechanism the install command uses to heal missing seeded reference
 * data (categories, currencies, …) on a re-run.
 */
final class UserInstalled
{
    public function __construct(public readonly int $userId) {}
}
