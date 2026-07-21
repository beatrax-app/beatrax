<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class RaiseOpenBankingReconsentAlert
{
    private const ALERT_KIND = 'open_banking_reconsent_required';

    private const ALERT_SEVERITY = 'warning';

    private const MESSAGE = 'Reconnect your bank';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(OpenBankingConsentFailed $event): void
    {
        $userId = $event->userId;
        $connectionId = $event->connectionId;

        if ($this->alreadyAlerted($userId, $connectionId)) {
            return;
        }

        try {
            SystemAlert::query()->create([
                'user_id' => $userId,
                'kind' => self::ALERT_KIND,
                'severity' => self::ALERT_SEVERITY,
                'message' => self::MESSAGE,
                'metadata' => [
                    'connection_id' => $connectionId,
                    'reason' => $event->reason,
                ],
            ]);
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

    /**
     * @link ../../../../.docs/features/open-banking/architecture.md
     */
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
