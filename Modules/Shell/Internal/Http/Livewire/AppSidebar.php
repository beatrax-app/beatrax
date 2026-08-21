<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;

final class AppSidebar extends Component
{
    private const HEARTBEAT_CACHE_KEY = 'dev_mode.queue_worker_heartbeat';

    public function render(
        CurrentUser $currentUser,
        Request $request,
        ViewFactory $views,
        CacheRepository $cache,
        DatabaseManager $db,
        Clock $clock,
        CounterpartyTriageQueue $triage,
        NavCountsService $navCounts,
    ): View {
        $user = $currentUser->user();
        $isDeveloper = $user->is_developer === true;

        // Live Dev-block reads — only materialise for developers so
        // a non-dev render does not pay the cache + jobs-count cost
        // (and so the placeholder copy never appears in their DOM).
        $queueCount = 0;
        $workerSecondsAgo = null;
        if ($isDeveloper) {
            // Pending-only — see architecture.md for why not jobs+failed_jobs,
            // the count deliberately excludes already-failed rows.
            $queueCount = $db->connection()->table('jobs')->count();

            $heartbeatRaw = $cache->get(self::HEARTBEAT_CACHE_KEY);
            if (is_int($heartbeatRaw)) {
                $delta = $clock->now()->getTimestamp() - $heartbeatRaw;
                // Clamp negative deltas (a clock skew between cache
                // writer and reader) so the UI never reads
                // "Worker -3s ago".
                $workerSecondsAgo = max(0, $delta);
            }
        }

        return $views->make('shell::livewire.app-sidebar', [
            'currentPath' => '/'.ltrim($request->path(), '/'),
            'username' => $user->username,
            'userInitial' => $this->initialFor($user->username),
            'isDeveloper' => $isDeveloper,
            'accountCaption' => $isDeveloper ? Lang::get('core::sidebar.account.developer_local') : Lang::get('core::sidebar.account.local'),
            'queueCount' => $queueCount,
            'workerSecondsAgo' => $workerSecondsAgo,
            'pollsLiveData' => $isDeveloper && ! UserDataPathService::isMobileRuntime(),
            'unknownCount' => $triage->unknownCountForUser($user),
            'navCounts' => $navCounts->forUser($user->id),
        ]);
    }

    // Falls back to "?" when empty (defensive — schema forbids empty
    // usernames). Drives the gradient .avatar initial.
    private function initialFor(string $username): string
    {
        $trimmed = ltrim($username);

        if ($trimmed === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
