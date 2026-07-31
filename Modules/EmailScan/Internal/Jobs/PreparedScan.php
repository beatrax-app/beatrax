<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use stdClass;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final readonly class PreparedScan
{
    // Carries the outcome of IncrementalScanJob::prepareScan so handle()
    // can fold the inbox-missing, state-missing, needs-reauth, no-sender
    // and reject-transition preconditions into a single nullable return
    // and then dispatch on the provider without re-reading any row.
    /**
     * @param  list<string>  $senderPatterns
     */
    public function __construct(
        public InboxScanContext $context,
        public string $provider,
        public array $senderPatterns,
        public stdClass $stateRow,
    ) {}
}
