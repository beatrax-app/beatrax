<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

// How long a mail scan may run before the queue gives up on it. Paging a
// provider's API is network-bound and outlasts a CPU-shaped default, so this
// sits under the worker ceiling in config/nativephp.php: a stuck scan then
// fails on its own alarm rather than being cut off with nothing recorded.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class ScanJobBudget
{
    public const int TIMEOUT_SECONDS = 280;
}
