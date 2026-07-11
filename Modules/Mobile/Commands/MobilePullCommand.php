<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Bounded, KEK-gated one-shot background pull — D-07's background cadence
 * leg (MOBILE-01). Invoked by the OS scheduler (Android WorkManager's
 * 15-minute floor / iOS BGTaskScheduler's best-effort hint) once the
 * premium `nativephp/mobile-background-tasks` plugin (Plan 03,
 * mobile-app/vendor only) translates the Plan 01 `Routes/console.php`
 * `Schedule::command('sync:mobile-pull')->onAnyNetwork()` entry into a real
 * OS-level background firing (RESEARCH.md Pattern 2).
 *
 * Mirrors `Modules\Sync\Commands\SyncServeCommand`'s artisan-command SHAPE
 * (signature/handle) only — deliberately NOT its long-running event-loop
 * body. `handle()` runs exactly one bounded
 * `MobileSyncTriggerService::syncOnce()` burst per user and returns. There
 * is no loop, no `\Amp\trapSignal()`, nothing kept open across firings —
 * every OS-scheduled invocation is a fresh, short-lived process that opens,
 * runs one burst, and exits (T-15-24).
 *
 * ## KEK-null is the expected common case (T-15-23, RESEARCH Anti-Pattern #3)
 *
 * Each firing is an untrusted, key-less cold start until an unlocked
 * session hands `MobileSyncTriggerService::syncOnce()` a usable device
 * identity. An OS-scheduled background tick has no cookie/session-id to
 * attach to the browser-side session an in-app unlock created, so
 * `AppLockKeyService::release()` returns null on essentially every real
 * firing — that is not a defect, it is this command doing exactly what it
 * must: skip cleanly, touch nothing, and never cache the key anywhere for
 * background convenience. Data stays encrypted at rest.
 *
 * ## Single-user v1, multi-user-ready schema (CLAUDE.md constraint)
 *
 * `MobileSyncTriggerService::syncOnce()` is user-scoped, so this command
 * fans out over every `users` row rather than hard-coding a single id —
 * mirroring `SafetyNetAnomalySweepJob`'s / `BackfillAnomaliesJob`'s own
 * per-user, `DatabaseManager::table()->lazyById()` fan-out precedent
 * (STATE [09-04]) rather than an Eloquent `User::query()` chain (Larastan
 * L10 strict flags dynamic `->select()` calls chained directly off
 * `Builder::query()`). Each user's burst is isolated: a failure for one
 * user is logged and does not stop the remaining users' bursts.
 *
 * ## Best-effort cadence (RESEARCH Pitfall 2)
 *
 * Background is a BONUS leg — foreground (session-driven dial-out) and
 * manual (the `/sync` screen's own trigger) are the reliable legs. This
 * command never asserts anything about wall-clock timing; the OS decides
 * exactly when (or whether) it fires.
 *
 * @internal Plan 09 — registered by the Plan 01 `MobileServiceProvider`
 *           (class_exists-guarded singleton + `$this->commands([...])`)
 *           and scheduled by the Plan 01 `Routes/console.php` entry. This
 *           plan only creates this class; it does not edit either.
 */
final class MobilePullCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:mobile-pull';

    /** @var string */
    protected $description = 'Run one bounded background sync burst per user (D-07 background cadence leg, best-effort).';

    public function __construct(
        private readonly MobileSyncTriggerService $trigger,
        private readonly Session $session,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->db->connection()->table('users')
            ->select('id')
            ->lazyById(100)
            ->each(function (stdClass $row): void {
                $userId = is_numeric($row->id) ? (int) $row->id : 0;
                if ($userId <= 0) {
                    return;
                }

                $this->runOneBoundedBurstFor($userId);
            });

        return self::SUCCESS;
    }

    /**
     * Exactly one bounded `MobileSyncTriggerService::syncOnce()` burst for
     * a single user — no retry loop of its own (the bounded LAN retry, if
     * any, lives entirely inside `syncOnce()` itself, T-15-33). Peer LAN
     * host/port discovery is out of scope for a background tick (Plan 05),
     * so this always dials with no LAN target, falling straight to the
     * off-LAN relay leg when a KEK is available and the relay is
     * configured.
     */
    private function runOneBoundedBurstFor(int $userId): void
    {
        $result = $this->trigger->syncOnce($userId, $this->session);

        if ($result === null) {
            $this->logger?->info('sync:mobile-pull: no usable device identity — tick skipped cleanly.', [
                'user_id' => $userId,
            ]);

            return;
        }

        $this->logger?->info('sync:mobile-pull: bounded background sync burst finished.', [
            'user_id' => $userId,
            'synced' => $result,
        ]);
    }
}
