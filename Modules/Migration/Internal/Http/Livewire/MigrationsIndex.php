<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/migrations` — a plain list of the user's past migration runs (UI-SPEC
 * Component Contract: no equivalent surface exists in `Modules\Import`,
 * which has no index at all; this is net-new, one row per run).
 *
 * Empty state (no runs yet) renders the "Start new migration" primary CTA.
 * Populated rows show a source-format chip, run date, status badge, and a
 * right-aligned action: "Resume preview" for a not-yet-confirmed run
 * ('parsed' or 'needs_attention' — the latter still has unresolved
 * conflicts to review) or "Check for updates" for a confirmed run (Req 10
 * entry point, `/migrations/new?reconcile_of={run}`).
 *
 * Discarded runs are excluded from the list entirely: `DiscardMigrationRun`
 * truncates their staging (D-07), so neither action ("Resume preview" would
 * hit `MigrationRunNotParsedException`; "Check for updates" requires a
 * `'confirmed'` status) is meaningful for one.
 *
 * Every query is scoped to `$currentUser->user()->id` (T-13.5-24) — a
 * partner never sees the owner's rows (proven by
 * `MigrationWizardTest`/`CrossUserIsolationTest`).
 */
final class MigrationsIndex extends Component
{
    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db): View
    {
        $userId = $currentUser->user()->id;

        // Raw DatabaseManager read (never a chained dynamic Eloquent
        // ->orderByDesc() call) — mirrors PreviewSummaryBuilder/
        // GoalProgressQuery's established discipline against
        // phpstan-strict-rules' staticMethod.dynamicCall false positive.
        $runs = $db->connection()->table('migration_runs')
            ->where('user_id', $userId)
            ->where('status', '!=', 'discarded')
            ->orderByDesc('id')
            ->get(['id', 'source_product', 'status', 'created_at']);

        return $views->make('migration::livewire.migrations-index', [
            'runs' => $runs,
        ]);
    }

    public function formatLabel(string $sourceProduct): string
    {
        return match ($sourceProduct) {
            'ynab4' => 'YNAB4',
            'nynab' => 'New YNAB',
            'actual' => 'Actual Budget',
            default => $sourceProduct,
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'parsed' => 'Parsed',
            'confirmed' => 'Confirmed',
            'needs_attention' => 'Needs attention',
            default => $status,
        };
    }
}
