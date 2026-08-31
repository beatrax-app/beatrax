<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\StoredCopy;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class RaiseReconsentAlertOnTokenFailure
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private SystemAlertWriter $alerts,
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
            $line = self::lineFor($event->provider);
            $this->alerts->raiseForUser(
                userId: $userId,
                kind: OAuthAlertKind::ReconsentRequired->value,
                severity: SystemAlertSeverity::Warning->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line) + [
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
            ->where('kind', OAuthAlertKind::ReconsentRequired->value)
            ->whereNull('acknowledged_at');
    }

    // The provider is a brand and rides as a value; the sentence around it is
    // the banner's own line, so both halves reach the reader the same way.
    private static function lineFor(string $provider): CopyLine
    {
        return CopyLine::of('core::alerts.messages.oauth_reconsent', [
            'provider' => $provider === MailProvider::Microsoft->value ? 'Outlook' : 'Gmail',
        ]);
    }
}
