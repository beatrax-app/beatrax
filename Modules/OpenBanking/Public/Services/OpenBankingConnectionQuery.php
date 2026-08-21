<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Public\Dto\OpenBankingConnectionView;

final class OpenBankingConnectionQuery
{
    private const AGGREGATOR = 'Enable Banking';

    // Consent status transitions to 'expiring' once fewer than this many
    // days remain until consent_expires_at.
    private const EXPIRING_SOON_DAYS = 14;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly Clock $clock,
    ) {}

    public function current(int $userId): ?OpenBankingConnectionView
    {
        $credentials = $this->secrets->load();
        $institutionId = $credentials?->institutionId;
        if ($institutionId === null || $institutionId === '') {
            return null;
        }

        $row = $this->db->connection()
            ->table('open_banking_connections')
            ->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->first();

        if ($row === null) {
            return null;
        }

        $connectionId = is_numeric($row->id ?? null) ? (int) $row->id : 0;
        $enabled = (bool) ($row->enabled ?? false);
        $consentExpiresAt = self::toDateTime($row->consent_expires_at ?? null);
        $lastSuccessfulSyncAt = self::toDateTime($row->last_successful_sync_at ?? null);
        $lastAttemptAt = self::toDateTime($row->last_attempt_at ?? null);
        $lastAttemptStatusRaw = $row->last_attempt_status ?? null;
        $lastAttemptStatus = is_string($lastAttemptStatusRaw) && $lastAttemptStatusRaw !== '' ? $lastAttemptStatusRaw : null;

        return new OpenBankingConnectionView(
            connectionId: $connectionId,
            enabled: $enabled,
            institutionId: $institutionId,
            bankDisplayName: self::bankDisplayNameFor($institutionId),
            consentStatus: $this->consentStatusFor($consentExpiresAt),
            consentExpiresAt: $consentExpiresAt,
            lastSuccessfulSyncAt: $lastSuccessfulSyncAt,
            lastAttemptAt: $lastAttemptAt,
            lastAttemptStatus: $lastAttemptStatus,
            aggregator: self::AGGREGATOR,
            whatsFetched: Lang::get('openbanking::messages.transparency.whats_fetched'),
        );
    }

    /**
     * @return 'connected'|'expiring'|'expired'
     */
    private function consentStatusFor(?CarbonImmutable $consentExpiresAt): string
    {
        if ($consentExpiresAt === null) {
            return 'expired';
        }

        $now = $this->clock->now();

        return match (true) {
            $consentExpiresAt->lessThanOrEqualTo($now) => 'expired',
            $consentExpiresAt->lessThanOrEqualTo($now->addDays(self::EXPIRING_SOON_DAYS)) => 'expiring',
            default => 'connected',
        };
    }

    // open_banking_connections.bank_display_name exists but is never populated,
    // so the name is derived from the institution id at read time.
    private static function bankDisplayNameFor(string $institutionId): string
    {
        return match ($institutionId) {
            'ASNBNL21' => 'ASN Bank',
            'SNSBNL21' => 'SNS (de Volksbank)',
            default => $institutionId,
        };
    }

    private static function toDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
