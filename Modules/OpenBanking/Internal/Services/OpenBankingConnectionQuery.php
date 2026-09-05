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
use stdClass;

final readonly class OpenBankingConnectionQuery
{
    private const string AGGREGATOR = 'Enable Banking';

    public function __construct(
        private DatabaseManager $db,
        private OpenBankingSecretsRepository $secrets,
        private Clock $clock,
    ) {}

    // Every bank this reader holds session material for, not the one the store
    // happened to hold last. A row whose institution has no stored session is
    // a connection nothing can fetch, so it is not offered as one.
    /**
     * @return list<OpenBankingConnectionView>
     */
    public function forUser(int $userId): array
    {
        $institutionIds = $this->secrets->connectedInstitutions($userId);
        if ($institutionIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('open_banking_connections')
            ->where('user_id', $userId)
            ->whereIn('institution_id', $institutionIds)
            ->orderBy('id')
            ->get();

        $views = [];
        foreach ($rows as $row) {
            $views[] = $this->viewFrom($row);
        }

        return $views;
    }

    // Both predicates are load-bearing: the user id keeps one reader's screen
    // off another's row, and the stored-session check keeps a row whose secret
    // this reader does not hold from rendering as a connection.
    public function forConnection(int $userId, int $connectionId): ?OpenBankingConnectionView
    {
        $row = $this->db->connection()
            ->table('open_banking_connections')
            ->where('id', $connectionId)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return null;
        }

        $institutionId = is_string($row->institution_id ?? null) ? $row->institution_id : '';
        if (! in_array($institutionId, $this->secrets->connectedInstitutions($userId), strict: true)) {
            return null;
        }

        return $this->viewFrom($row);
    }

    private function viewFrom(stdClass $row): OpenBankingConnectionView
    {
        $institutionIdRaw = $row->institution_id ?? null;
        $institutionId = is_string($institutionIdRaw) ? $institutionIdRaw : '';
        $lastAttemptStatusRaw = $row->last_attempt_status ?? null;

        return new OpenBankingConnectionView(
            connectionId: is_numeric($row->id ?? null) ? (int) $row->id : 0,
            enabled: (bool) ($row->enabled ?? false),
            institutionId: $institutionId,
            bankDisplayName: self::bankDisplayNameFor($institutionId),
            consentStatus: ConsentWindow::fromStoredRow($row, $this->clock->now())->status(),
            consentExpiresAt: self::toDateTime($row->consent_expires_at ?? null),
            lastSuccessfulSyncAt: self::toDateTime($row->last_successful_sync_at ?? null),
            lastAttemptAt: self::toDateTime($row->last_attempt_at ?? null),
            lastAttemptStatus: is_string($lastAttemptStatusRaw) && $lastAttemptStatusRaw !== '' ? $lastAttemptStatusRaw : null,
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
