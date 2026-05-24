<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Throwable;

/**
 * Dev Console overview page (UI-SPEC § /dev overview surfaces).
 *
 * Primary visual anchor: the theme-locked dark `.console-pane` (fixed
 * #0b1220 bg / #f1f5f9 text regardless of theme). Three-column head:
 *   - Worker heartbeat (read from cache key {@see WriteWorkerHeartbeat::CACHE_KEY},
 *     rendered as "Ns ago · ttl 60s" when fresh; "NOT RUNNING" when
 *     stale or missing).
 *   - Queue counts (pending / failed / active batches via raw query
 *     builder; the rendering Blade uses the same `data-testid`
 *     markers downstream tests assert against).
 *   - Last command (most recent dev_mode_audit row, mono rendering).
 *
 * Console-pane tail: the last 8 lines of today's rolling daily log
 * file ({@see UserDataPathService::dailyLogFile()}), each line passed
 * through the same {@see RedactSecretsProcessor} that the on-write
 * Monolog tap + the on-stream log tailer apply (D-29 belt+braces).
 *
 * Recent-runs card: the calling developer's last 5 dev_mode_audit
 * rows; each row's link href encodes `?command=<encoded>` so a click
 * jumps to /dev/audit pre-filtered to the command in question.
 *
 * Open-alerts card: the {@see SystemAlertQuery::active()} read against
 * the current user — un-acknowledged system_alerts rows scoped to the
 * caller-or-system-wide cohort.
 *
 * Pattern B (PATTERNS.md): no constructor on a Livewire Component;
 * collaborators arrive as method-DI on render().
 */
#[Layout('dev::layouts.dev-shell')]
final class DevOverviewPage extends Component
{
    /** Heading copy is verbatim per UI-SPEC § Copywriting. */
    private const string RECENT_RUNS_EMPTY = 'No runs yet. Press ⌘K to run a command.';

    private const string OPEN_ALERTS_EMPTY = 'No open alerts.';

    /** Tail line cap — 8 lines per UI-SPEC § /dev overview surfaces. */
    private const int TAIL_LINES = 8;

    /** Recent-runs cap — 5 rows per UI-SPEC § /dev overview surfaces. */
    private const int RECENT_RUNS_LIMIT = 5;

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        CacheRepository $cache,
        Clock $clock,
        SystemAlertQuery $alerts,
        DatabaseManager $db,
        RedactSecretsProcessor $scrubber,
    ): View {
        $user = $currentUser->user();
        $now = $clock->now();

        return $views->make('dev::livewire.dev-overview-page', [
            'workerHeartbeat' => $this->resolveWorkerHeartbeat($cache, $now),
            'queueCounts' => $this->resolveQueueCounts($db),
            'lastCommand' => $this->resolveLastCommand($db),
            'recentRuns' => $this->resolveRecentRuns($db, $user),
            'openAlerts' => $alerts->active($user)->take(self::RECENT_RUNS_LIMIT),
            'logTail' => $this->resolveLogTail($scrubber),
            'recentRunsEmptyCopy' => self::RECENT_RUNS_EMPTY,
            'openAlertsEmptyCopy' => self::OPEN_ALERTS_EMPTY,
        ]);
    }

    /**
     * Heartbeat reader. Returns either a "Ns ago · ttl 60s" string
     * (cache populated + timestamp parseable) or null (cache empty,
     * stale, or unparseable — the Blade renders the NOT RUNNING pill).
     *
     * @return array{label: string, secondsAgo: ?int}
     */
    private function resolveWorkerHeartbeat(CacheRepository $cache, CarbonImmutable $now): array
    {
        $raw = $cache->get(WriteWorkerHeartbeat::CACHE_KEY);
        if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
            return ['label' => 'NOT RUNNING', 'secondsAgo' => null];
        }
        $timestamp = is_int($raw) ? $raw : (int) $raw;
        $secondsAgo = $now->getTimestamp() - $timestamp;
        if ($secondsAgo < 0 || $secondsAgo > WriteWorkerHeartbeat::TTL_SECONDS) {
            return ['label' => 'NOT RUNNING', 'secondsAgo' => null];
        }

        return [
            'label' => $secondsAgo.'s ago · ttl '.WriteWorkerHeartbeat::TTL_SECONDS.'s',
            'secondsAgo' => $secondsAgo,
        ];
    }

    /**
     * Queue depth counts via the raw query builder (the same shape the
     * Phase 16-06 QueueInspectorPage uses for larastan-strict
     * compatibility).
     *
     * @return array{pending: int, failed: int, batches: int}
     */
    private function resolveQueueCounts(DatabaseManager $db): array
    {
        return [
            'pending' => $db->connection()->table('jobs')->count(),
            'failed' => $db->connection()->table('failed_jobs')->count(),
            'batches' => $db->connection()->table('job_batches')
                ->whereNull('cancelled_at')
                ->whereNull('finished_at')
                ->count(),
        ];
    }

    /**
     * Latest dev_mode_audit row (any user) — surfaces the "last
     * command" cell of the console-pane head.
     */
    private function resolveLastCommand(DatabaseManager $db): ?string
    {
        $row = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->orderByDesc('created_at')
            ->limit(1)
            ->first();
        if ($row === null) {
            return null;
        }
        $properties = $this->extractProperties($row);
        $command = $properties['command'] ?? null;

        return is_string($command) ? $command : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractProperties(object $row): array
    {
        $vars = get_object_vars($row);
        $raw = $vars['properties'] ?? null;
        if (! is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        $normalised = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    /**
     * Last 5 dev_mode_audit rows belonging to the calling developer.
     * The rendered link href encodes the `?command=<urlencoded>` query
     * pre-filter so clicks navigate to /dev/audit pre-scoped.
     *
     * @return list<array{command: string, tier: string, exitCode: ?int, createdAt: ?string, href: string}>
     */
    private function resolveRecentRuns(DatabaseManager $db, User $user): array
    {
        $rows = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->where('causer_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_RUNS_LIMIT)
            ->get();

        $rendered = [];
        foreach ($rows as $row) {
            $properties = $this->extractProperties($row);
            $command = is_string($properties['command'] ?? null) ? $properties['command'] : '';
            $tier = is_string($properties['tier'] ?? null) ? $properties['tier'] : 'safe';
            $exitCode = is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null;
            $vars = get_object_vars($row);
            $createdAtRaw = $vars['created_at'] ?? null;
            $createdAt = is_string($createdAtRaw) ? $createdAtRaw : null;

            $rendered[] = [
                'command' => $command,
                'tier' => $tier,
                'exitCode' => $exitCode,
                'createdAt' => $createdAt,
                'href' => '/dev/audit?command='.rawurlencode($command),
            ];
        }

        return $rendered;
    }

    /**
     * Read the last 8 lines of today's daily-rolled Laravel log file
     * and apply the on-stream redaction processor (defense-in-depth on
     * top of the on-write Monolog tap — CONTEXT D-29). Missing log
     * file is treated as "no lines yet" rather than a crash; the
     * Blade renders the empty-state cursor regardless.
     *
     * For a daily-rotated log file (typical size: hundreds of KB) a
     * single `file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)`
     * + `array_slice(-N)` is fast enough and significantly simpler
     * than an `SplFileObject::seek(PHP_INT_MAX)` walk (which off-by-one's
     * around final-newline handling). Larger non-daily-rotated files
     * would warrant a streaming tail, but those don't apply here.
     *
     * @return list<string>
     */
    private function resolveLogTail(RedactSecretsProcessor $scrubber): array
    {
        $path = UserDataPathService::dailyLogFile();
        if (! is_file($path)) {
            return [];
        }

        try {
            $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($all)) {
            return [];
        }

        $tail = array_slice($all, -self::TAIL_LINES);
        $scrubbed = [];
        foreach ($tail as $line) {
            $scrubbed[] = $scrubber->scrub($line);
        }

        return $scrubbed;
    }
}
