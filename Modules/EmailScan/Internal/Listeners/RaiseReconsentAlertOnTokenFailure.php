<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes a single un-acknowledged `system_alerts` row of kind
 * `oauth_reconsent_required` whenever an inbox's OAuth token refresh
 * raises `InboxTokenFailed`.
 *
 * Dedup pattern: at most one active (un-acknowledged) row per
 * (user_id, inbox_id) — once the user re-consents, the modal hands the
 * alert id back to `InboxesPage::acknowledgeReconnect` which stamps
 * `acknowledged_at` via the existing AcknowledgeSystemAlert action;
 * the next failure (if any) creates a fresh row because the existence
 * check filters on `acknowledged_at IS NULL`.
 *
 * Dedup query uses SQLite's `json_extract` against the `metadata`
 * column — Herd ships SQLite 3.45 so the function is available. A
 * Throwable from the extracted-column predicate falls through to a
 * LIKE-based form so a slightly older runtime still dedups correctly.
 *
 * Token text NEVER appears in the row: the metadata blob carries only
 * the integer inbox_id + the short provider string; the `message`
 * column is a static "Reconnect your Gmail" / "Reconnect your Outlook"
 * literal chosen from the provider field. ASVS V7 holds.
 */
final class RaiseReconsentAlertOnTokenFailure
{
    private const ALERT_KIND = 'oauth_reconsent_required';

    private const ALERT_SEVERITY = 'warning';

    private const MESSAGE_GMAIL = 'Reconnect your Gmail';

    private const MESSAGE_MICROSOFT = 'Reconnect your Outlook';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(InboxTokenFailed $event): void
    {
        $userId = $event->userId;
        $inboxId = $event->inboxId;

        if ($this->alreadyAlerted($userId, $inboxId)) {
            return;
        }

        try {
            SystemAlert::query()->create([
                'user_id' => $userId,
                'kind' => self::ALERT_KIND,
                'severity' => self::ALERT_SEVERITY,
                'message' => $this->messageFor($event->provider),
                'metadata' => [
                    'inbox_id' => $inboxId,
                    'provider' => $event->provider,
                ],
            ]);
        } catch (Throwable $e) {
            // Defence-in-depth — the listener must never throw upward
            // because the upstream caller is mid-error-recovery already.
            // Log at warning level so the failure stays visible without
            // a second alert flood.
            $this->logger->warning(
                'RaiseReconsentAlertOnTokenFailure failed to persist alert row',
                [
                    'inbox_id' => $inboxId,
                    'provider' => $event->provider,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Existence check for an active (un-acknowledged) re-consent alert
     * scoped to the given user + inbox. Returns true when a row already
     * exists so the listener can no-op.
     *
     * Prefers the precise `json_extract(metadata, '$.inbox_id')` form;
     * falls back to a LIKE form if the extracted-column predicate
     * throws on an older SQLite where the JSON1 extension is not
     * compiled in.
     */
    private function alreadyAlerted(int $userId, int $inboxId): bool
    {
        $baseQuery = $this->baseDedupQuery($userId);

        try {
            return $baseQuery
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId])
                ->exists();
        } catch (Throwable) {
            // Fallback — match the JSON-encoded fragment
            // `"inbox_id":N` inside the raw column text. SQLite LIKE
            // does not support character classes, so we anchor the
            // trailing boundary by OR-ing two literal needles: one
            // ending in `,` (when other JSON keys follow) and one
            // ending in `}` (when inbox_id is the last key in the
            // object). Without this `inbox_id=1` would falsely match
            // `inbox_id=10`, `inbox_id=11`, `inbox_id=123`.
            $withComma = '%"inbox_id":'.$inboxId.',%';
            $withBrace = '%"inbox_id":'.$inboxId.'}%';

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

    private function messageFor(string $provider): string
    {
        return match ($provider) {
            'microsoft' => self::MESSAGE_MICROSOFT,
            default => self::MESSAGE_GMAIL,
        };
    }
}
