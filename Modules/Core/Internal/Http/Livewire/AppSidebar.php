<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;

/**
 * Persistent left sidebar. Rendered from
 * `layouts/app.blade.php` within an `@auth` guard so unauthenticated
 * pages (login, error pages) do not see it.
 *
 * Replaces the previous TopNav horizontal row with a sectioned,
 * fixed-width sidebar (248px in the main app). Section labels group
 * sibling nav items into THIS MONTH / MONEY / INGESTION / SETTINGS so
 * navigation is scannable at a glance.
 *
 * The Dev block at the foot is gated server-side on
 * `users.is_developer` — non-developers do NOT receive the dashed
 * container in the rendered HTML. The block carries a pulsing
 * emerald `.dot-live` dot, an "Open Dev Console" link with a `⌘.`
 * kbd hint, and a "Queue {N} · Worker {N}s ago" live row driven by
 * a wire:poll.5s sub-tree.
 *
 * Queue-count choice: jobs.count() (pending only). Failed jobs
 * already surface in the dashboard toast and the dedicated
 * /dev/queue/failed tab, so "Queue {N}" in the sidebar reads as
 * "work waiting to be done". The composite count
 * jobs + failed_jobs would conflate two distinct operational
 * signals (work-in-progress vs work-that-failed).
 *
 * The account caption reads "developer · local" for developers and
 * "local" otherwise — the only place in the chrome that reveals the
 * caller's developer status to themselves.
 *
 * Active-link highlighting reads the current path from the injected
 * `Request` rather than the `request()` helper so the component stays
 * clean under the project's DI-only invariant.
 */
final class AppSidebar extends Component
{
    /** Cache key the queue-worker heartbeat producer writes. */
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
            // jobs.count() — see class docblock for the
            // pending-only-not-composite rationale.
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

        return $views->make('core::livewire.app-sidebar', [
            'currentPath' => '/'.ltrim($request->path(), '/'),
            'username' => $user->username,
            'userInitial' => $this->initialFor($user->username),
            'isDeveloper' => $isDeveloper,
            'accountCaption' => $isDeveloper ? 'developer · local' : 'local',
            'queueCount' => $queueCount,
            'workerSecondsAgo' => $workerSecondsAgo,
            'unknownCount' => $triage->unknownCountForUser($user),
            'navCounts' => $navCounts->forUser($user->id),
        ]);
    }

    /**
     * First alphanumeric character of the username, uppercased. Falls
     * back to "?" when the username is empty (defensive — schema
     * forbids empty usernames). Drives the gradient `.avatar` initial.
     */
    private function initialFor(string $username): string
    {
        $trimmed = ltrim($username);

        if ($trimmed === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
