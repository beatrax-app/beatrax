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
 * @link ../../../../.docs/features/email-scan/architecture.md
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
            // Defence-in-depth: the listener must never throw upward,
            // since the upstream caller is mid-error-recovery already.
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

    // Existence check for an active (un-acknowledged) re-consent alert
    // scoped to the user + inbox, preferring json_extract and falling
    // back to LIKE when the extracted-column predicate throws on an
    // older SQLite without the JSON1 extension compiled in.
    private function alreadyAlerted(int $userId, int $inboxId): bool
    {
        $baseQuery = $this->baseDedupQuery($userId);

        try {
            return $baseQuery
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId])
                ->exists();
        } catch (Throwable) {
            // Fallback: matches the JSON fragment "inbox_id":N inside
            // the raw column text, anchoring the trailing boundary
            // with two needles (comma- and brace-terminated) since
            // SQLite LIKE has no character classes to bound the digits.
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

    // Shared per-user predicate filtered to active re-consent rows;
    // the LIKE-fallback call re-builds the query since Laravel
    // builders aren't safe to reuse across where re-additions.
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
