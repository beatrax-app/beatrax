<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Psr\Log\LoggerInterface;
use Throwable;

final class RaiseOpenBankingReconsentAlert
{
    private const ALERT_KIND = 'open_banking_reconsent_required';

    private const ALERT_SEVERITY = 'warning';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
        private readonly SystemAlertWriter $alerts,
    ) {}

    public function handle(OpenBankingConsentFailed $event): void
    {
        $userId = $event->userId;
        $connectionId = $event->connectionId;

        if ($this->alreadyAlerted($userId, $connectionId)) {
            return;
        }

        try {
            // A lapsed bank consent is the user's to re-grant on whichever
            // device is to hand, so the row is owned and travels; the
            // machine-local probes in Core deliberately do not.
            $this->alerts->raiseForUser(
                userId: $userId,
                kind: self::ALERT_KIND,
                severity: self::ALERT_SEVERITY,
                message: Lang::get('openbanking::messages.alert.reconsent'),
                metadata: [
                    'connection_id' => $connectionId,
                    'reason' => $event->reason,
                ],
            );
        } catch (Throwable $e) {
            // Defence-in-depth — the listener must never throw upward
            // because the upstream caller (the sync job / consent-check)
            // is mid-error-recovery already. Log at warning level so the
            // failure stays visible without a second alert flood.
            $this->logger->warning(
                'RaiseOpenBankingReconsentAlert failed to persist alert row',
                [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    private function alreadyAlerted(int $userId, int $connectionId): bool
    {
        $baseQuery = $this->baseDedupQuery($userId);

        try {
            return $baseQuery
                ->whereRaw("json_extract(metadata, '$.connection_id') = ?", [$connectionId])
                ->exists();
        } catch (Throwable) {
            // Two OR-ed needles anchor the trailing boundary since SQLite
            // LIKE has no character classes — without this, connection_id=1
            // would falsely match connection_id=10/11/123.
            $withComma = '%"connection_id":'.$connectionId.',%';
            $withBrace = '%"connection_id":'.$connectionId.'}%';

            return $this->baseDedupQuery($userId)
                ->where(static function (Builder $q) use ($withComma, $withBrace): void {
                    $q->where('metadata', 'like', $withComma)
                        ->orWhere('metadata', 'like', $withBrace);
                })
                ->exists();
        }
    }

    // The second-pass LIKE-fallback call re-builds the query because
    // Laravel builders are not safe to reuse across where re-additions.
    private function baseDedupQuery(int $userId): Builder
    {
        return $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', self::ALERT_KIND)
            ->whereNull('acknowledged_at');
    }
}
