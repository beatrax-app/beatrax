<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Events;

final readonly class OpenBankingConsentFailed
{
    public function __construct(
        public int $connectionId,
        public int $userId,
        public string $reason,
    ) {}
}
