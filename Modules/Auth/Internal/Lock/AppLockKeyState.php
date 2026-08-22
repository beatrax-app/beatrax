<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Stranded is the state no credential can leave: data is encrypted under a
// data key none of this row's wraps still hold, so no PIN and no account
// password can produce it, and only a peer that kept it can.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
enum AppLockKeyState
{
    case Absent;

    case Held;

    // The PIN wrap still opens; the account-password wrap does not, because
    // the password it was built from has been replaced. Named apart from Held
    // because the day the PIN is forgotten there is nothing behind it.
    case RecoveryUnreadable;

    case Stranded;
}
