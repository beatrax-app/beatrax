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
 * Minimal read-only sync-health panel (D-07).
 *
 * Shows quarantined-op count (last 7 days) + recent-skip table.
 * Accessible from the Dev Console at /dev/sync-health.
 *
 * No constructor — Livewire components never receive constructor DI
 * per the project's larastan-strict-rules profile; collaborators
 * arrive as method-DI on render().
 *
 * ALWAYS filter op_log_quarantine by user_id (Pitfall 4 — no
 * BelongsToUser global scope on this table in queue/console context).
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

        // Pitfall 4: every query filters by user_id — no global scope here.
        //
        // WR-05 invariant: the 7-day window compares lexicographically-sortable
        // `Y-m-d H:i:s` strings. This holds because EVERY writer of
        // op_log_quarantine.created_at uses `Clock::now()->toDateTimeString()`
        // (Y-m-d H:i:s) and the column default is SQLite CURRENT_TIMESTAMP (same
        // format). Any future writer that inserts a differently-formatted
        // timestamp (e.g. ISO-8601 with a `T` separator or a timezone offset)
        // would silently fall out of this window — so all writers MUST keep
        // emitting `Y-m-d H:i:s`.
        $sevenDaysAgo = $clock->now()->subDays(7)->toDateTimeString();

        $recentCount = $db->connection()
            ->table('op_log_quarantine')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->count();

        // WR-03: apply the SAME 7-day window to the table as the header count,
        // so the header total, the rendered rows, and the "last 7 days"
        // empty-state copy can never disagree (e.g. header 0 vs 50 year-old rows).
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
