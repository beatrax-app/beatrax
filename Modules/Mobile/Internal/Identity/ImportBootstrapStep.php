<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

// Where the fresh-device import bootstrap is: collect_pin -> recovery_codes,
// or collect_pin -> provisioning_failed for the retry a post-signup throw
// lands on. Its own vocabulary — the pairing ceremony that follows this wizard
// has its own, and neither screen shares a step with the other.
enum ImportBootstrapStep: string
{
    case CollectPin = 'collect_pin';

    case ProvisioningFailed = 'provisioning_failed';

    case RecoveryCodes = 'recovery_codes';
}
