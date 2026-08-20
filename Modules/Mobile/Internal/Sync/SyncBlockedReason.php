<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

// What the initial-sync pull is waiting on, or absent while it is simply
// working. The backing value is the `mobile::setup.blocked.*` translation
// key, so every case must have copy — SetupBlockedReasonsHaveCopyTest walks
// cases() and fails on a case added without it.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
enum SyncBlockedReason: string
{
    case NoPeer = 'no_peer';

    case NoKeys = 'no_keys';

    case Unreachable = 'unreachable';

    case Reprojecting = 'reprojecting';

    case Locked = 'locked';

    // The peer says it no longer knows this device. Terminal, not retryable:
    // clearing the local confirmation left every later tick reporting "no
    // confirmed peer yet", which is what a device that had NEVER paired
    // reports — so the screen sat on "Syncing…" forever with nothing to do.
    case Revoked = 'revoked';

    // A poll tick that threw. The screen is driven entirely by those ticks,
    // so an unhandled failure answered 500, which Livewire discards — the
    // view kept its last frame and looked alive while nothing ran again.
    case Retrying = 'retrying';
}
