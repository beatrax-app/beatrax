<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

// A rejected credential and a failed step are not the same answer: replaying
// the stash retries the second and can only ever re-lose the first, and the
// screen that offers "try again" for both was a loop with no exit.
enum DeviceProvisioningOutcome
{
    case Succeeded;

    case Failed;

    case CredentialsRejected;
}
