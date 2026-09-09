<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Three answers rather than a bool: a refused PIN and a device that would not
// hold the key are different things to the reader, and only one of them is
// worth typing again.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
enum ColdStartEnrolmentResult
{
    case Enrolled;

    case PinRejected;

    case VaultRefused;
}
