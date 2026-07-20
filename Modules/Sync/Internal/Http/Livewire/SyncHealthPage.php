<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
#[Layout('dev::layouts.dev-shell')]
final class SyncHealthPage extends Component
{
    public function render(
        ViewFactory $views,
        DatabaseManager $db,
        CurrentUser $currentUser,
        Clock $clock,
    ): View {
        $userId = $currentUser->user()->id;

        // The 7-day window compares lexicographically-sortable `Y-m-d H:i:s`
        // strings. This holds because every writer of
        // op_log_quarantine.created_at uses Clock::now()->toDateTimeString()
        // and the column default is SQLite CURRENT_TIMESTAMP (same format).
        $sevenDaysAgo = $clock->now()->subDays(7)->toDateTimeString();

        $recentCount = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        // Applies the SAME 7-day window as the header count, so the header
        // total, the rendered rows, and the empty-state copy never disagree.
        $recentSkips = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $views->make('sync::livewire.sync-health-page', [
            'recentCount' => $recentCount,
            'recentSkips' => $recentSkips,
        ]);
    }
}
