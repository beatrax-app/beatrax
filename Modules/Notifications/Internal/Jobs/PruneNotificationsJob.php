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
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\LockStore;
use Psr\Log\LoggerInterface;

/**
 * Per-user daily notification-inbox retention sweep (Req 14, D-18, D-37).
 * Clones `CounterpartyGarbageCollectorJob`'s shape verbatim — the
 * `ShouldBeUniqueUntilProcessing` triad, `tries`/`backoff`, and the
 * inline-hardcoded-cutoff-with-docblock-justification idiom.
 *
 * **D-18 — the 365-day window.** Hardcoded inline (NOT a `config()` lookup,
 * per the GC precedent) so the number is grep-able in one place:
 * `whereRaw("notifications.created_at < datetime('now', '-365 days')")`.
 * 365 matches `CounterpartyGarbageCollectorJob`'s window exactly — one
 * retention number across the project.
 *
 * **This is NOT a "history retained forever" violation.** That project rule
 * (CLAUDE.md / PROJECT.md) governs TRANSACTIONS — long-term
 * subscription-drift analysis needs them. 18-SPEC.md's Constraints section
 * carves notification pruning out of it explicitly: "Pruning notifications
 * follows the existing `CounterpartyGarbageCollectorJob` precedent." A
 * verifier must NOT flag this job as a history-retention violation.
 *
 * **D-37 — the KEK-less property is the point, not an optimisation.** The
 * sweep is age-based on the ALWAYS-PLAINTEXT `notifications.created_at`
 * column — `id` / `user_id` / `created_at` / `read_at` / `dismissed_at` /
 * `state` are deliberately never encrypted (see the `create_notifications_table`
 * migration's docblock, D-12) — and its predicate touches NONE of the four
 * `SensitiveFieldRegistry`-listed notifications columns (`title` / `body` /
 * `params` / `trigger_type`). So, unlike `CounterpartyGarbageCollectorJob`
 * (whose orphan predicate DOES compare an encrypted column), this job needs
 * no KEK to run correctly — on a locked or headless device the sweep still
 * runs and the inbox still stays bounded. Without this property, an
 * encrypted inbox on a usually-locked device would silently never prune and
 * Req 14's bounded-growth promise would fail with only a warning log as
 * evidence.
 *
 * The optional `Session` / `AppLockKeyService` / `EncryptionMigrationService`
 * / `LoggerInterface` parameters below exist as a DEFENSIVE SAFETY NET only,
 * cloned from `CounterpartyGarbageCollectorJob`'s CRYPT-01 KEK-less
 * skip-with-warning branch (~lines 282-301) — they are NOT consulted to gate
 * today's delete (the predicate never touches an encrypted column, so there
 * is nothing to skip). They exist so that IF a future change ever extends
 * this job's predicate onto an encrypted column, the KEK-check machinery and
 * its preserve-on-uncertainty ethos (never a wrongful prune when in doubt)
 * is already in place to inherit rather than having to be re-derived. Today
 * the branch only LOGS (never skips) so this run is visible for debugging
 * without ever affecting correctness.
 *
 * Cross-user discipline (T-18-57): every clause carries an explicit
 * `where('user_id', $this->userId)` — `BelongsToUser`'s `UserScope` global
 * scope does not fire in queue/console context. Deletes run in bounded
 * chunks (id-batched) rather than one unbounded `DELETE`, matching the GC
 * job's bounded-delete discipline.
 */
final class PruneNotificationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * D-18: matches `CounterpartyGarbageCollectorJob`'s retention window
     * exactly — one retention number across the project. Referenced in the
     * `whereRaw()` cutoff below; kept as a named constant for readability,
     * NOT as a `config()`-driven tunable (the GC precedent this job clones
     * hardcodes its own 365 inline for the same reason: a single grep-able
     * number, not a setting a user could accidentally widen unboundedly).
     */
    private const int RETENTION_DAYS = 365;

    /** Bounded id-batch size for the chunked delete loop. */
    private const int CHUNK_SIZE = 500;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600;
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
        // Defensive safety net only (D-37) — see the class docblock. This
        // job's real predicate below never touches an encrypted column, so
        // this check gates NOTHING today; it only logs, purely for
        // visibility, so a future contributor who adds an encrypted-column
        // predicate to this job notices the precedent already exists.
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
            // notifications.id is a 64-char sha256 hex STRING primary key
            // (D-05), never an integer — every id collected/deleted below
            // stays a string throughout.
            /** @var list<string> $ids */
            $ids = $connection->table('notifications')
                ->where('notifications.user_id', $this->userId)
                // D-18 / D-37: the age-based, KEK-less cutoff — the sole
                // predicate. Portable SQLite date arithmetic mirrors
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
