<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final readonly class OpenBankingConnectionView
{
    /**
     * @param  'connected'|'expiring'|'expired'  $consentStatus
     */
    public function __construct(
        public int $connectionId,
        public bool $enabled,
        public string $institutionId,
        public string $bankDisplayName,
        public string $consentStatus,
        public ?CarbonImmutable $consentExpiresAt,
        public ?CarbonImmutable $lastSuccessfulSyncAt,
        public ?CarbonImmutable $lastAttemptAt,
        public ?string $lastAttemptStatus,
        public string $aggregator,
        public string $whatsFetched,
    ) {}

    // Renders only when the last attempt did not succeed — last_attempt_status
    // is 'ok' on success and 'consent_failed'/'error' on failure (never null
    // once at least one attempt has run).
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptStatus !== null && $this->lastAttemptStatus !== 'ok';
    }
}
