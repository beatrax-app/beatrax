<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Events;

use Carbon\CarbonImmutable;

final readonly class IcsStatementReady
{
    public function __construct(
        public int $userId,
        public int $messageId,
        public CarbonImmutable $internalDate,
    ) {}
}
