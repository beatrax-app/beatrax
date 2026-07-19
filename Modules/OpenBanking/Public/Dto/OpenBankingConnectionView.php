<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * Read-only view model for the `/settings/open-banking` transparency panel
 * (19-11, UI-SPEC Surface B4) and the Settings entry row (Surface A).
 *
 * Built exclusively by `OpenBankingConnectionQuery` from the ONE connection
 * whose `institution_id` matches the secrets file's currently active
 * session (never from an arbitrary `open_banking_connections` row) — see
 * that class's docblock for the single-live-session rationale (19-10
 * deferred-items.md).
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

    /**
     * The B4 "Last attempt" row renders ONLY when the last attempt did not
     * succeed — `SyncOpenBankingAccountJob` writes `last_attempt_status`
     * as `'ok'` on success and `'consent_failed'`/`'error'` on failure
     * (never null once at least one attempt has run).
     */
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptStatus !== null && $this->lastAttemptStatus !== 'ok';
    }
}
