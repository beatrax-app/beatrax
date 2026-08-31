<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// One standing system_alerts row per (user, kind, connection). Not
// SystemAlertWriter::raiseOnceForUser(), which dedups per kind alone: a reader
// with two banks needs to be told about each of them.
final readonly class ConnectionAlerts
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private SystemAlertWriter $alerts,
    ) {}

    // Never throws: every caller is a listener running inside somebody else's
    // error recovery, and an alert that failed to persist must not become the
    // exception that buries the fault it was reporting.
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function raiseOnceForConnection(
        int $userId,
        int $connectionId,
        string $kind,
        string $severity,
        string $message,
        array $metadata = [],
    ): void {
        if ($this->alreadyAlerted($userId, $connectionId, $kind)) {
            return;
        }

        try {
            // A fault the user re-grants or re-files on whichever device is to
            // hand, so the row is owned and travels; the machine-local probes
            // in Core deliberately do not.
            $this->alerts->raiseForUser(
                userId: $userId,
                kind: $kind,
                severity: $severity,
                message: $message,
                metadata: ['connection_id' => $connectionId] + $metadata,
            );
        } catch (Throwable $e) {
            $this->logger->warning('ConnectionAlerts failed to persist an alert row', [
                'kind' => $kind,
                'connection_id' => $connectionId,
                ...SafeExceptionContext::describe($e),
            ]);
        }
    }

    private function alreadyAlerted(int $userId, int $connectionId, string $kind): bool
    {
        try {
            return $this->baseDedupQuery($userId, $kind)
                ->whereRaw("json_extract(metadata, '$.connection_id') = ?", [$connectionId])
                ->exists();
        } catch (Throwable) {
            // Two OR-ed needles anchor the trailing boundary since SQLite
            // LIKE has no character classes — without this, connection_id=1
            // would falsely match connection_id=10/11/123.
            $withComma = '%"connection_id":'.$connectionId.',%';
            $withBrace = '%"connection_id":'.$connectionId.'}%';

            return $this->baseDedupQuery($userId, $kind)
                ->where(static function (Builder $q) use ($withComma, $withBrace): void {
                    $q->where('metadata', 'like', $withComma)
                        ->orWhere('metadata', 'like', $withBrace);
                })
                ->exists();
        }
    }

    // The second-pass LIKE-fallback call re-builds the query because
    // Laravel builders are not safe to reuse across where re-additions.
    private function baseDedupQuery(int $userId, string $kind): Builder
    {
        return $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->whereNull('acknowledged_at');
    }
}
