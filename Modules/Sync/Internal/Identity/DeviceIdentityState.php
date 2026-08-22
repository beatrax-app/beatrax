<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

// Unreadable is the state that has no second chance from this device: the
// key-file was written under an app-lock data key this install no longer
// holds, so unlocking cannot produce it and only the database that wraps
// that key can.
/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md
 */
enum DeviceIdentityState
{
    case Absent;

    case Locked;

    case Unreadable;

    case Usable;
}
