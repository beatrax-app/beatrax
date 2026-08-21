<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use stdClass;

final readonly class PreparedScan
{
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
