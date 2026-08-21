<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

// Paging a provider is network-bound and outlasts a CPU-shaped default. Kept
// under the worker ceiling in config/nativephp.php so a stuck scan fails on
// its own alarm rather than being cut off with nothing recorded.
final class ScanJobBudget
{
    public const int TIMEOUT_SECONDS = 280;
}
