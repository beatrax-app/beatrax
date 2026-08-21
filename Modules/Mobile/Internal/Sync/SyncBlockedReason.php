<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// The backing value is the `mobile::setup.blocked.*` translation key, so a
// case added without copy fails SetupBlockedReasonsHaveCopyTest.
/**
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
enum SyncBlockedReason: string
{
    case NoPeer = 'no_peer';

    case NoKeys = 'no_keys';

    case Unreachable = 'unreachable';

    case Reprojecting = 'reprojecting';

    case Locked = 'locked';

    // Terminal, not retryable: without it a revoked peer read as "no
    // confirmed peer yet", which is what a device that never paired reports.
    case Revoked = 'revoked';

    // A poll tick that threw. Without it the failure answered 500, Livewire
    // discarded it, and the last frame stayed on screen looking alive.
    case Retrying = 'retrying';
}
