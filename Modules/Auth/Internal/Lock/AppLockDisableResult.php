<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Three answers rather than a bool: refusing because encrypted data depends
// on the key is a different thing from refusing a wrong PIN, and the screen
// has to say which one it was.
/**
 * @link ../../../../.docs/features/auth/app-lock-data-key-lifetime.md
 */
enum AppLockDisableResult
{
    case Disabled;

    case PinIncorrect;

    case EncryptedDataDependsOnIt;
}
