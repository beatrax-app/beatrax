<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Events;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingConsentFailed
{
    public function __construct(
        public readonly int $connectionId,
        public readonly int $userId,
        public readonly string $reason,
    ) {}
}
