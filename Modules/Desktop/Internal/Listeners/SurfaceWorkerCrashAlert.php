<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Desktop\Internal\Native\ShellState;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Facades\Notification;
use Psr\Log\LoggerInterface;
use Throwable;

// NativePHP fires ProcessExited on every restart of the supervised queue:work
// child, and a single exit is normal steady-state (auto-restart on a memory-limit
// hit), so only a sustained crash-loop within the rolling window escalates.
final readonly class SurfaceWorkerCrashAlert
{
    // NativePHP names a supervised worker `queue_` + its config key, in
    // QueueWorker::up(). The alias is the shell's, not ours: it arrives on the
    // event, and a constant guessing at it cannot be caught by a test that
    // builds the event out of that same constant.
    public const string WORKER_ALIAS_PREFIX = 'queue_';

    public const string EXIT_LOG_SLOT_PREFIX = 'desktop.shell-state.process-exits.';

    public const string RECOVERY_PROBE_SLOT = 'desktop.shell-state.worker-recovery-probed';

    public const int CRASH_LOOP_THRESHOLD = 3;

    public const int CRASH_LOOP_WINDOW_SECONDS = 300;

    public const string ALERT_KIND = 'worker.crashed';

    // The English canonical, mirrored in desktop::native.worker_alert.*; escalate()
    // renders through Lang::get, so at the `en` default the two are identical.
    public const string ALERT_BODY = "Beatrax's background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.";

    public const string OS_NOTIFICATION_TITLE = 'Background work stopped';

    public function __construct(
        private Clock $clock,
        private DatabaseManager $db,
        private WindowFocusState $focus,
        private UrlGenerator $urls,
        private SystemAlertWriter $alerts,
        private ShellState $state,
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {}

    public function handle(ProcessExited $event): void
    {
        $this->recordExit($event);

        if (! $this->isSupervisedWorker($event->alias)) {
            return;
        }

        // The event's own alias, not a constant beside it: the bucket
        // `recordExit` just wrote is keyed on what arrived, and reading a
        // different key is how a threshold comes to be counted against nothing.
        if (! $this->isCrashLoop($event->alias)) {
            return;
        }

        $this->escalate();
    }

    // ProcessExited says the worker went, and nothing said it came back, so
    // "Reopen the app to restart it" stood after the reader had done exactly
    // that. This runs inside the worker: a tick of its own daemon loop is
    // liveness no cached stamp can impersonate.
    public function handleWorkerLoop(): void
    {
        try {
            if (! $this->probeIsDue() || ! $this->everySupervisedWorkerIsQuiet()) {
                return;
            }

            $closed = $this->alerts->withdrawSystemWide(self::ALERT_KIND, $this->clock->now());
        } catch (Throwable $e) {
            // Nothing may escape: an exception raised here propagates out of the
            // daemon's own loop check and stops the worker, and the alert this
            // method exists to take down is the one that would be raised next.
            $this->logger->warning(
                'SurfaceWorkerCrashAlert: could not tell whether the worker had recovered; continuing.',
                SafeExceptionContext::describe($e),
            );

            return;
        }

        if ($closed > 0) {
            $this->logger->info(
                'SurfaceWorkerCrashAlert: withdrew the worker-crashed alert; the worker has run a full window without exiting.',
                ['closed' => $closed],
            );
        }
    }

    // The exact inverse of the rule that raised the alert: a worker that has not
    // exited once across the whole window has stopped crash-looping by the same
    // definition that said it was. Withdrawing on a spawn would take the banner
    // down in the gap between two crashes, where it is not stale but false.
    public function everySupervisedWorkerIsQuiet(): bool
    {
        foreach ($this->supervisedAliases() as $alias) {
            if ($this->exitsInsideTheWindow($alias) !== []) {
                return false;
            }
        }

        return true;
    }

    public function recordExit(ProcessExited $event): void
    {
        $stamps = $this->exitsInsideTheWindow($event->alias);
        $stamps[] = $this->clock->now()->getTimestamp();

        // Only the newest THRESHOLD stamps can answer the question, so a worker
        // exiting hundreds of times inside one window still writes a bounded row.
        // The TTL is the window itself: a device that stops crashing leaves nothing.
        $this->state->write(
            self::exitSlot($event->alias),
            array_slice($stamps, -self::CRASH_LOOP_THRESHOLD),
            self::CRASH_LOOP_WINDOW_SECONDS,
        );
    }

    // Every worker the bundle is configured to supervise, under the shell's
    // spelling. Derived from the config rather than listed, so a worker added
    // or renamed there is watched without anyone remembering this file.
    /**
     * @return list<string>
     */
    public function supervisedAliases(): array
    {
        $workers = $this->config->get('nativephp.queue_workers');

        return array_map(
            static fn (int|string $key): string => self::WORKER_ALIAS_PREFIX.$key,
            array_keys(is_array($workers) ? $workers : []),
        );
    }

    public function isSupervisedWorker(string $alias): bool
    {
        return in_array($alias, $this->supervisedAliases(), true);
    }

    public function isCrashLoop(string $alias): bool
    {
        return count($this->exitsInsideTheWindow($alias)) >= self::CRASH_LOOP_THRESHOLD;
    }

    // Pruned on the way out as well as on the way in, so a bucket read long
    // after its last write cannot count a stamp the window has already left.
    /**
     * @return list<int>
     */
    private function exitsInsideTheWindow(string $alias): array
    {
        $cutoff = $this->clock->now()->getTimestamp() - self::CRASH_LOOP_WINDOW_SECONDS;

        return array_values(array_filter(
            $this->state->read(self::exitSlot($alias)) ?? [],
            static fn (mixed $stamp): bool => is_int($stamp) && $stamp > $cutoff,
        ));
    }

    // The alias reaches us from the shell and the cache key column is bounded,
    // so the slot is one fixed length whatever a child process is called.
    private static function exitSlot(string $alias): string
    {
        return self::EXIT_LOG_SLOT_PREFIX.hash('sha256', $alias);
    }

    // The loop ticks every few seconds for as long as the app is open and the
    // question below reaches the database, so it is asked at most once a minute.
    private function probeIsDue(): bool
    {
        if ($this->state->read(self::RECOVERY_PROBE_SLOT) !== null) {
            return false;
        }

        $this->state->write(
            self::RECOVERY_PROBE_SLOT,
            ['at' => $this->clock->now()->getTimestamp()],
            Duration::Minute->seconds(),
        );

        return true;
    }

    private function escalate(): void
    {
        $connection = $this->db->connection();

        $alreadyAlerted = $connection->table('system_alerts')
            ->where('kind', self::ALERT_KIND)
            ->whereNull('acknowledged_at')
            ->exists();

        if (! $alreadyAlerted) {
            // The OS notification below stays resolved here — a push fires once
            // and cannot be re-rendered. The row is read for as long as it is
            // open, so it carries the line and keeps the sentence beside it.
            $line = CopyLine::of('core::alerts.messages.worker_crashed');

            // Null means a second process won the same race, and it decides the
            // notification too: without it both supervisors pushed, so one crash
            // knocked twice on a household that already knew.
            $alreadyAlerted = $this->alerts->raiseOnceSystemWide(
                kind: self::ALERT_KIND,
                severity: SystemAlertSeverity::Critical->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line),
            ) === null;
        }

        // The in-app banner already shows the row when focused, and an unacknowledged
        // prior alert means the household already knows.
        if ($alreadyAlerted || $this->focus->isFocused()) {
            return;
        }

        Notification::title(Lang::get('desktop::native.worker_alert.os_title'))
            ->message(Lang::get('desktop::native.worker_alert.body'))
            ->event(NotificationDeepLink::class)
            ->reference(Destination::Dashboard->urlFrom($this->urls))
            ->show();
    }
}
