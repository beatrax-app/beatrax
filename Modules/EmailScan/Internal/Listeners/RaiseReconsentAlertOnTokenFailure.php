<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Psr\Log\LoggerInterface;
use Throwable;

final class RaiseReconsentAlertOnTokenFailure
{
    private const ALERT_KIND = 'oauth_reconsent_required';

    private const MESSAGE_GMAIL = 'Reconnect your Gmail';

    private const MESSAGE_MICROSOFT = 'Reconnect your Outlook';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
        private readonly SystemAlertWriter $alerts,
    ) {}

    public function handle(InboxTokenFailed $event): void
    {
        $userId = $event->userId;
        $inboxId = $event->inboxId;

        if ($this->alreadyAlerted($userId, $inboxId)) {
            return;
        }

        try {
            // A lapsed mail token is a fact about the account, not this
            // machine, so the row travels — otherwise the other device keeps
            // prompting long after the user reconnected.
            $this->alerts->raiseForUser(
                userId: $userId,
                kind: self::ALERT_KIND,
                severity: SystemAlertSeverity::Warning->value,
                message: $this->messageFor($event->provider),
                metadata: [
                    'inbox_id' => $inboxId,
                    'provider' => $event->provider,
                ],
            );
        } catch (Throwable $e) {
            // Defence-in-depth: the listener must never throw upward,
            // since the upstream caller is mid-error-recovery already.
            $this->logger->warning(
                'RaiseReconsentAlertOnTokenFailure failed to persist alert row',
                [
                    'inbox_id' => $inboxId,
                    'provider' => $event->provider,
                    ...SafeExceptionContext::describe($e),
                ],
            );
        }
    }

    private function alreadyAlerted(int $userId, int $inboxId): bool
    {
        $baseQuery = $this->baseDedupQuery($userId);

        try {
            return $baseQuery
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId])
                ->exists();
        } catch (Throwable) {
            // An older SQLite has no JSON1 extension, so json_extract above
            // throws and this LIKE fallback runs instead. SQLite LIKE has no
            // character classes, so the trailing boundary needs separate
            // comma- and brace-terminated needles.
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

    // Rebuilt per call: a Laravel builder is not safe to reuse once further
    // where clauses have been added to it.
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
            MailProvider::Microsoft->value => self::MESSAGE_MICROSOFT,
            default => self::MESSAGE_GMAIL,
        };
    }
}
