<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

// Fired the moment a device gains a sync identity. The desktop listener is a
// daemon nobody needs until this happens: with no identity there is no peer
// that could dial in, and every inbound connection would be rejected.

// Boot reads the persisted state; this event covers the other edge — sync
// enabled inside a running app, which must not wait for a restart to become
// reachable.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class DeviceSyncEnabled
{
    public function __construct(
        public int $userId,
    ) {}
}
