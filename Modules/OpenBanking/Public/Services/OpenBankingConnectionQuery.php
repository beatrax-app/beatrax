<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Public\Dto\OpenBankingConnectionView;

/**
 * Read-only surface feeding the `/settings/open-banking` transparency panel
 * (Surface B4) and the Settings entry row (Surface A) — 19-11, Req 6.
 *
 * Single-active-connection honesty (19-10 deferred-items.md, carried
 * forward from the Wave 2 review-and-fix gate): `OpenBankingSecretsRepository`
 * holds exactly ONE live Enable Banking session at a time, while
 * `open_banking_connections` permits one row per `(user_id, institution_id)`
 * — i.e. the schema *can* accumulate stale rows from a bank the user
 * previously linked and then re-linked elsewhere. Resolving "the"
 * connection ONLY via the secrets file's active `institutionId` (never by
 * picking "the most recent row" or similar) means the UI never implies more
 * than one bank is simultaneously live — exactly the honesty guard flagged
 * for whichever Wave 3 plan owns this surface. A stale row for a
 * DIFFERENT institution than the one currently in the secrets file is
 * treated as "not connected", not silently surfaced as a second active
 * connection.
 */
final class OpenBankingConnectionQuery
{
    private const AGGREGATOR = 'Enable Banking';

    private const WHATS_FETCHED = 'Booked transactions + balances, last 90 days';

    /**
     * Consent status transitions to 'expiring' once fewer than this many
     * days remain until `consent_expires_at` (UI-SPEC B4 amber pill).
     */
    private const EXPIRING_SOON_DAYS = 14;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly Clock $clock,
    ) {}

    /**
     * Resolves the single connection view for the given user, or null when
     * no application is registered yet, no session has ever been
     * established, or the secrets file's active institution has no
     * matching `open_banking_connections` row for this user.
     */
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
            whatsFetched: self::WHATS_FETCHED,
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
        if ($consentExpiresAt->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        if ($consentExpiresAt->lessThanOrEqualTo($now->addDays(self::EXPIRING_SOON_DAYS))) {
            return 'expiring';
        }

        return 'connected';
    }

    /**
     * Mirrors `OpenBankingWizardModal`'s own ASN/SNS display-name mapping
     * (19-06) — the `open_banking_connections.bank_display_name` column
     * exists but is never populated by the callback controller, so the
     * display name is derived from the institution id at read time instead
     * of relying on that unpopulated column.
     */
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
