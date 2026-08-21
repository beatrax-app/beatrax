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
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Logging\RecentLogEntriesReader;

#[Layout('dev::layouts.dev-shell')]
final class DevOverviewPage extends Component
{
    private const int RECENT_LOG_ENTRIES_LIMIT = 5;

    private const int RECENT_RUNS_LIMIT = 5;

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        CacheRepository $cache,
        Clock $clock,
        SystemAlertQuery $alerts,
        DatabaseManager $db,
        RecentLogEntriesReader $logEntries,
    ): View {
        $user = $currentUser->user();
        $now = $clock->now();

        return $views->make('dev::livewire.dev-overview-page', [
            'workerHeartbeat' => $this->resolveWorkerHeartbeat($cache, $now),
            'queueCounts' => $this->resolveQueueCounts($db),
            'lastCommand' => $this->resolveLastCommand($db),
            'recentRuns' => $this->resolveRecentRuns($db, $user),
            'openAlerts' => $alerts->active($user)->take(self::RECENT_RUNS_LIMIT),
            'recentLogEntries' => $logEntries->recent(self::RECENT_LOG_ENTRIES_LIMIT),
            'recentRunsEmptyCopy' => Lang::get('dev::overview.recent_runs_empty'),
            'openAlertsEmptyCopy' => Lang::get('dev::overview.open_alerts_empty'),
        ]);
    }

    /**
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
}
