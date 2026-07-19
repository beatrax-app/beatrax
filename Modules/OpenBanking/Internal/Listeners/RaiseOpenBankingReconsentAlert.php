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
 * Writes a single un-acknowledged `system_alerts` row of kind
 * `open_banking_reconsent_required` whenever an Open Banking connection's
 * consent/SCA session fails or expires (`OpenBankingConsentFailed`,
 * D-09/T-19-06-02/T-19-06-03).
 *
 * A verbatim port of `Modules\EmailScan\Internal\Listeners\
 * RaiseReconsentAlertOnTokenFailure` — NOT `EmitOAuthReauthRequiredAlert`,
 * which is a one-time Phase-12-migration-specific alert, not a reusable
 * consent-expiry template (RESEARCH.md D-09 note). `connection_id` plays
 * the role `inbox_id` plays there; there is no provider-branching
 * `messageFor()` since Open Banking has exactly one provider (Enable
 * Banking).
 *
 * Dedup pattern: at most one active (un-acknowledged) row per
 * (user_id, connection_id) — once the user reconnects, a future Wave 3
 * surface acknowledges the alert; the next failure (if any) creates a
 * fresh row because the existence check filters on
 * `acknowledged_at IS NULL`.
 *
 * Dedup query uses SQLite's `json_extract` against the `metadata`
 * column, falling back to a dual-needle LIKE match (trailing comma OR
 * trailing brace) so `connection_id=1` never falsely matches
 * `connection_id=10`/`11`/`123` on a SQLite build without the JSON1
 * extension compiled in.
 *
 * Never throws upward (defence-in-depth try/catch → log warning) — the
 * caller is typically mid-error-recovery already (T-19-06-03).
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
     * Existence check for an active (un-acknowledged) re-consent alert
     * scoped to the given user + connection. Returns true when a row
     * already exists so the listener can no-op.
     *
     * Prefers the precise `json_extract(metadata, '$.connection_id')`
     * form; falls back to a LIKE form if the extracted-column predicate
     * throws on an older SQLite where the JSON1 extension is not
     * compiled in.
     */
    private function alreadyAlerted(int $userId, int $connectionId): bool
    {
        $baseQuery = $this->baseDedupQuery($userId);

        try {
            return $baseQuery
                ->whereRaw("json_extract(metadata, '$.connection_id') = ?", [$connectionId])
                ->exists();
        } catch (Throwable) {
            // Fallback — match the JSON-encoded fragment
            // `"connection_id":N` inside the raw column text. SQLite
            // LIKE does not support character classes, so the trailing
            // boundary is anchored by OR-ing two literal needles: one
            // ending in `,` (when other JSON keys follow) and one
            // ending in `}` (when connection_id is the last key in the
            // object). Without this, connection_id=1 would falsely
            // match connection_id=10, 11, 123.
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

    /**
     * Shared per-user predicate filtered to active re-consent rows. The
     * second-pass LIKE-fallback call re-builds the query because Laravel
     * builders are not safe to reuse across `where` re-additions.
     */
    private function baseDedupQuery(int $userId): Builder
    {
        return $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', self::ALERT_KIND)
            ->whereNull('acknowledged_at');
    }
}
