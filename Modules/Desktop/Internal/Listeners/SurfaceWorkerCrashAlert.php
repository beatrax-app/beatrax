<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Facades\Notification;

/**
 * Surfaces a sustained queue-worker crash-loop to the user (D-07).
 *
 * NativePHP supervises the persistent `queue:work` child process
 * configured under `config/nativephp.php` `queue_workers.default` (D-05).
 * On every restart it fires `ProcessExited` carrying the worker alias
 * + exit code. A SINGLE exit is NOT the failure signal — NativePHP
 * auto-restarts, and an occasional restart (memory-limit hit, single
 * job timeout) is the steady-state behavior of a long-running worker.
 *
 * A sustained crash-loop IS the failure signal: the worker is dying
 * faster than it can do useful work, and the non-technical partner
 * needs to know imports + scheduled scans have stopped. The listener
 * accumulates exits in a rolling window keyed by alias and only
 * escalates once the configured threshold is crossed within the
 * window.
 *
 * On escalation the listener writes ONE `system_alerts` row with
 * `kind = 'worker.crashed'`, `severity = 'critical'`, `user_id = null`
 * (a crashed background process is system-wide, not user-scoped) and
 * the UI-SPEC verbatim body. A de-dup guard on
 * `(kind = 'worker.crashed', acknowledged_at IS NULL)` ensures a
 * repeat crash-loop while the prior alert is still un-acknowledged
 * does NOT insert a second row — the partner already sees the banner.
 *
 * When the window is unfocused (D-13), the listener ALSO fires an OS
 * notification titled "Background work stopped" carrying the same body
 * — so the partner sees the alert even if the app is minimized to the
 * tray. The focus-gate suppresses the OS notification when the window
 * is focused; the in-app `SystemAlertsBanner` surfaces the row instead.
 *
 * Native-chrome carve-out: this listener calls
 * `Native\Desktop\Facades\Notification` directly. NativePHP's
 * notification API is only reachable through that facade (no
 * container-bound alternative), so the file is on the
 * `BoundaryArchTest` facade allow-list + `phpstan.neon` ignore list.
 * The other four collaborators (Clock, DatabaseManager, WindowFocusState,
 * UrlGenerator) arrive through constructor DI.
 *
 * The crash-counter state is a private property on the listener; the
 * provider binds the listener as a singleton so the counter survives
 * across multiple `ProcessExited` events.
 */
final class SurfaceWorkerCrashAlert
{
    /** Alias of the NativePHP-supervised queue worker (config/nativephp.php queue_workers.default). */
    public const WORKER_ALIAS = 'queue-default';

    /** Minimum number of ProcessExited events inside the rolling window before escalating. */
    public const CRASH_LOOP_THRESHOLD = 3;

    /** Rolling-window length in seconds — exits older than this fall out of the counter. */
    public const CRASH_LOOP_WINDOW_SECONDS = 300;

    /** kind value on the system_alerts row. */
    public const ALERT_KIND = 'worker.crashed';

    /** Verbatim UI-SPEC body (Copywriting Contract). */
    public const ALERT_BODY = "diederik's background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.";

    /** Verbatim UI-SPEC OS-notification title. */
    public const OS_NOTIFICATION_TITLE = 'Background work stopped';

    /**
     * Rolling crash-counter, keyed by worker alias, holding the unix
     * timestamp of every recorded exit inside the window. Exits older
     * than CRASH_LOOP_WINDOW_SECONDS are pruned on every record.
     *
     * @var array<string, list<int>>
     */
    private array $exits = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly WindowFocusState $focus,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * Subscriber entrypoint. Records the exit, then escalates when the
     * accumulated exits for the worker alias cross the threshold.
     *
     * Non-worker aliases are ignored — NativePHP may run other child
     * processes whose lifecycle is not the partner's concern.
     */
    public function handle(ProcessExited $event): void
    {
        $this->recordExit($event);

        if ($event->alias !== self::WORKER_ALIAS) {
            return;
        }

        if (! $this->isCrashLoop(self::WORKER_ALIAS)) {
            return;
        }

        $this->escalate();
    }

    /**
     * Records a ProcessExited event in the rolling counter without
     * deciding whether to escalate. Public so the unit tests can drive
     * the counter directly; production code goes through `handle()`.
     */
    public function recordExit(ProcessExited $event): void
    {
        $now = $this->clock->now()->getTimestamp();
        $cutoff = $now - self::CRASH_LOOP_WINDOW_SECONDS;

        $alias = $event->alias;
        $bucket = $this->exits[$alias] ?? [];
        // Prune expired entries before appending so the bucket never
        // grows beyond the most recent window-worth of exits.
        $bucket = array_values(array_filter($bucket, static fn (int $t): bool => $t > $cutoff));
        $bucket[] = $now;
        $this->exits[$alias] = $bucket;
    }

    /**
     * Returns true when the rolling-window counter for the given alias
     * is at or above the crash-loop threshold. Public so the unit tests
     * can assert the windowed-counter behavior without touching the
     * database.
     */
    public function isCrashLoop(string $alias): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $cutoff = $now - self::CRASH_LOOP_WINDOW_SECONDS;
        $bucket = $this->exits[$alias] ?? [];
        $bucket = array_filter($bucket, static fn (int $t): bool => $t > $cutoff);

        return count($bucket) >= self::CRASH_LOOP_THRESHOLD;
    }

    /**
     * Writes the system_alerts row (with de-dup against an existing
     * un-acknowledged alert) and fires the OS notification when the
     * window is unfocused.
     *
     * The OS notification is suppressed in two cases:
     *
     *   - Focused window (D-13): the in-app SystemAlertsBanner shows
     *     the row, so no double-notification.
     *   - De-duped row (`$alreadyAlerted === true`): a prior crash-loop
     *     already wrote a system_alerts row that has NOT been
     *     acknowledged. The partner is already aware via the banner
     *     (or — when re-blurring — by the previous OS notification);
     *     surfacing another OS toast for the same un-acknowledged
     *     incident is just noise. Once the row is acknowledged a new
     *     crash-loop will re-insert and re-notify, which is the
     *     correct steady-state behaviour.
     */
    private function escalate(): void
    {
        $connection = $this->db->connection();

        $alreadyAlerted = $connection->table('system_alerts')
            ->where('kind', self::ALERT_KIND)
            ->whereNull('acknowledged_at')
            ->exists();

        if (! $alreadyAlerted) {
            SystemAlert::query()->create([
                'user_id' => null, // system-wide — a crashed worker is not user-scoped.
                'kind' => self::ALERT_KIND,
                'severity' => 'critical',
                'message' => self::ALERT_BODY,
            ]);
        }

        // Suppress when the window is focused (D-13 in-app banner
        // handles the same row) OR when the row was already
        // un-acknowledged (the partner is already aware; the prior
        // OS notification has already fired). Both branches collapse
        // into the same "stay quiet" outcome.
        if ($alreadyAlerted || $this->focus->isFocused()) {
            return;
        }

        Notification::title(self::OS_NOTIFICATION_TITLE)
            ->message(self::ALERT_BODY)
            ->event(NotificationDeepLink::class)
            ->reference($this->urls->route('dashboard'))
            ->show();
    }
}
