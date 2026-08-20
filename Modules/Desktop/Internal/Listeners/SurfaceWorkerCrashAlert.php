<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Facades\Notification;

// NativePHP fires ProcessExited on every restart of the supervised queue:work
// child, and a single exit is normal steady-state (auto-restart on a memory-limit
// hit), so only a sustained crash-loop within the rolling window escalates.
final class SurfaceWorkerCrashAlert
{
    public const WORKER_ALIAS = 'queue-default';

    public const CRASH_LOOP_THRESHOLD = 3;

    public const CRASH_LOOP_WINDOW_SECONDS = 300;

    public const ALERT_KIND = 'worker.crashed';

    // The English canonical, mirrored in desktop::native.worker_alert.*; escalate()
    // renders through Lang::get, so at the `en` default the two are identical.
    public const ALERT_BODY = "Beatrax's background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.";

    public const OS_NOTIFICATION_TITLE = 'Background work stopped';

    // Unix timestamps of every exit inside the window, pruned on each record.
    /** @var array<string, list<int>> */
    private array $exits = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly WindowFocusState $focus,
        private readonly UrlGenerator $urls,
    ) {}

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

    public function recordExit(ProcessExited $event): void
    {
        $now = $this->clock->now()->getTimestamp();
        $cutoff = $now - self::CRASH_LOOP_WINDOW_SECONDS;

        $alias = $event->alias;
        $bucket = $this->exits[$alias] ?? [];
        $bucket = array_values(array_filter($bucket, static fn (int $t): bool => $t > $cutoff));
        $bucket[] = $now;
        $this->exits[$alias] = $bucket;
    }

    public function isCrashLoop(string $alias): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $cutoff = $now - self::CRASH_LOOP_WINDOW_SECONDS;
        $bucket = $this->exits[$alias] ?? [];
        $bucket = array_filter($bucket, static fn (int $t): bool => $t > $cutoff);

        return count($bucket) >= self::CRASH_LOOP_THRESHOLD;
    }

    private function escalate(): void
    {
        $connection = $this->db->connection();

        $alreadyAlerted = $connection->table('system_alerts')
            ->where('kind', self::ALERT_KIND)
            ->whereNull('acknowledged_at')
            ->exists();

        if (! $alreadyAlerted) {
            SystemAlert::query()->create([
                'user_id' => null,
                'kind' => self::ALERT_KIND,
                'severity' => 'critical',
                'message' => Lang::get('desktop::native.worker_alert.body'),
            ]);
        }

        // The in-app banner already shows the row when focused, and an unacknowledged
        // prior alert means the household already knows.
        if ($alreadyAlerted || $this->focus->isFocused()) {
            return;
        }

        Notification::title(Lang::get('desktop::native.worker_alert.os_title'))
            ->message(Lang::get('desktop::native.worker_alert.body'))
            ->event(NotificationDeepLink::class)
            ->reference($this->urls->route('dashboard'))
            ->show();
    }
}
