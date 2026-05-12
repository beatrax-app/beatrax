<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

/**
 * Dispatched by the `diederik:install` command when a new user row is
 * created. Listeners (e.g. the default-category-tree seeder shipped by the
 * Categorization module in a later plan) react to this event.
 */
final class UserInstalled
{
    public function __construct(public readonly int $userId) {}
}
