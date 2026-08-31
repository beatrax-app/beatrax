<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Dto\OpenBankingConnectionView;
use Modules\OpenBanking\Internal\Enums\CuratedInstitution;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

final readonly class OpenBankingConnectionQuery
{
    private const string AGGREGATOR = 'Enable Banking';

    public function __construct(
        private DatabaseManager $db,
        private OpenBankingSecretsRepository $secrets,
        private Clock $clock,
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
            consentStatus: ConsentWindow::fromStoredRow($row, $this->clock->now())->status(),
            consentExpiresAt: $consentExpiresAt,
            lastSuccessfulSyncAt: $lastSuccessfulSyncAt,
            lastAttemptAt: $lastAttemptAt,
            lastAttemptStatus: $lastAttemptStatus,
            aggregator: self::AGGREGATOR,
            whatsFetched: Lang::get('openbanking::messages.transparency.whats_fetched'),
        );
    }

    // open_banking_connections.bank_display_name exists but is never populated,
    // so the name is derived from the institution id at read time.
    private static function bankDisplayNameFor(string $institutionId): string
    {
        return CuratedInstitution::tryFrom($institutionId)?->displayName() ?? $institutionId;
    }

    private static function toDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
