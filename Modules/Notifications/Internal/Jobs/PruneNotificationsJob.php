<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class PruneNotificationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    // Matches CounterpartyGarbageCollectorJob's retention window exactly
    // - one retention number across the project. Kept as a named
    // constant, not a config()-driven tunable, so it stays a single
    // grep-able number rather than a setting a user could widen unboundedly.
    private const int RETENTION_DAYS = 365;

    private const int CHUNK_SIZE = 500;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return Duration::Hour->seconds();
    }

    public function uniqueVia(): Repository
    {
        return LockStore::forUniqueJobs();
    }

    public function handle(
        DatabaseManager $db,
        ?Session $session = null,
        ?AppLockKeyService $appLockKeyService = null,
        ?EncryptionMigrationService $encryptionMigrationService = null,
        ?LoggerInterface $logger = null,
    ): void {
        // Defensive safety net only - the real predicate below never
        // touches an encrypted column, so this check gates nothing today;
        // it only logs, so a future contributor who adds an
        // encrypted-column predicate here notices the precedent exists.
        if ($session !== null
            && $appLockKeyService !== null
            && $encryptionMigrationService !== null
            && $logger !== null
            && $encryptionMigrationService->isEnabled($this->userId)
            && $appLockKeyService->release($session) === null
        ) {
            $logger->info(
                'PruneNotificationsJob: no app-lock KEK available for an encrypted user in this run. This is informational only — the retention sweep needs no KEK because it keys solely on the unencrypted created_at column (D-37) — logged so a future contributor who adds an encrypted-column predicate here notices this precedent.',
                ['user_id' => $this->userId],
            );
        }

        $connection = $db->connection();

        do {
            // notifications.id is a 64-char sha256 hex string primary
            // key, never an integer - every id collected/deleted below
            // stays a string throughout.
            /** @var list<string> $ids */
            $ids = $connection->table('notifications')
                ->where('notifications.user_id', $this->userId)
                // The age-based, key-less cutoff - the sole predicate.
                // Portable SQLite date arithmetic mirrors
                // CounterpartyGarbageCollectorJob's identical idiom.
                ->whereRaw("notifications.created_at < datetime('now', '-".self::RETENTION_DAYS." days')")
                ->orderBy('notifications.id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('notifications.id')
                ->filter(static fn (mixed $id): bool => is_string($id))
                ->values()
                ->all();

            if ($ids === []) {
                break;
            }

            $connection->table('notifications')
                ->where('user_id', $this->userId)
                ->whereIn('id', $ids)
                ->delete();
        } while (count($ids) === self::CHUNK_SIZE);
    }
}
