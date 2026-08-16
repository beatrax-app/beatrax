<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

// Fired from an unlocked request when this device's sealed identity can be
// opened, so a listener daemon started while locked can be handed its Noise
// transport keypair. Carries no key material — subscribers read the identity
// themselves through SyncDaemonIdentity.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class SyncTransportCredentialsAvailable
{
    public function __construct(
        public int $userId,
    ) {}
}
