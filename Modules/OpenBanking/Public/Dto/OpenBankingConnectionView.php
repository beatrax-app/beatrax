<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

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

    // last_attempt_status is 'ok' on success and never null once an attempt
    // has run, so anything else is a failure.
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptStatus !== null && $this->lastAttemptStatus !== 'ok';
    }
}
