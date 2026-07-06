<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;

/**
 * `/migrations/{run}/results` — the wizard's read-only summary step.
 * Surfaces the persisted counts from `migration_runs`' summary-count
 * columns (Plan 06's Rule 2 fix), restates the 5-up stat grid with FINAL
 * persisted counts (not preview-time staged counts, per UI-SPEC), and
 * links out to the modules that actually received a write.
 *
 * `migration_runs`/`MigrationConfirmResult` (Plan 06) carry no persisted
 * "budget months" count — the 5th stat-grid column and the Copywriting
 * Contract's success-banner line both need it. Plan 06 deliberately did
 * NOT truncate staging post-confirm ("to avoid narrowing Plan 07's future
 * design space" — 13.5-06-SUMMARY.md), so `migration_staging_budget_
 * assignments` is still readable here; this component computes the final
 * budget-months count with the exact same DISTINCT-period_start formula
 * `PreviewSummaryBuilder` uses, scoped entirely within this new file
 * (Rule 2 fix — no Plan 05/06/07 file touched).
 *
 * IDOR guard (T-13.5-24): user-scoped `firstOrFail()`.
 */
final class MigrationResults extends Component
{
    public int $runId = 0;

    public function mount(int $id): void
    {
        $this->runId = $id;
    }

    public function render(
        ViewFactory $views,
        CurrentUser $currentUser,
        DatabaseManager $db,
        PreviewSummaryBuilder $builder,
    ): View {
        $user = $currentUser->user();

        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $this->runId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $budgetMonthsCount = $db->connection()->table('migration_staging_budget_assignments')
            ->where('user_id', $user->id)
            ->where('migration_run_id', $this->runId)
            ->distinct()
            ->count('period_start');

        $unmapped = null;
        try {
            $unmapped = $builder->forRun($this->runId, $user)->unmapped;
        } catch (MigrationRunNotParsedException) {
            // Staging was truncated (a discarded run reached this page
            // directly, or a pre-Plan-06 run) — no read-only leftovers to
            // show, the still-unmapped section is simply omitted.
        }

        $stillNeedsAttention = $unmapped === null ? 0 : array_sum(array_column($unmapped, 'count'));

        // isReconciliation is a display-only heuristic: conflicts only ever
        // populate via CheckForUpdates (Plan 07), so a non-empty conflict
        // group is a strong signal this run came from "Check for updates"
        // rather than a first-time import. Not test-driven; a first-time
        // import always has zero conflicts, so the default "Import complete"
        // title is safe either way.
        $isReconciliation = $unmapped !== null && $unmapped['conflict']['count'] > 0;

        return $views->make('migration::livewire.migration-results', [
            'run' => $run,
            'budgetMonthsCount' => $budgetMonthsCount,
            'unmapped' => $unmapped,
            'stillNeedsAttention' => $stillNeedsAttention,
            'isReconciliation' => $isReconciliation,
        ]);
    }
}
