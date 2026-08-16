<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class UserInstalled
{
    // False when this install is JOINING an existing account and receives its
    // starter data from a peer. Autoincrement ids start at 1 on both devices,
    // so seeding locally made the peer's rule id 1 collide with a local row:
    // the peer's rule vanished while its conditions attached to that id.
    public function __construct(
        public readonly int $userId,
        public readonly bool $seedsStarterData = true,
    ) {}
}
