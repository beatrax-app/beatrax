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
 * @link ../../../../../.docs/features/migration/architecture.md
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
            // directly) — no read-only leftovers to show, the
            // still-unmapped section is simply omitted.
        }

        $stillNeedsAttention = $unmapped === null ? 0 : array_sum(array_column($unmapped, 'count'));

        // Display-only heuristic: conflicts only ever populate via
        // CheckForUpdates, so a non-empty conflict group is a strong signal
        // this run came from "Check for updates" rather than a first-time
        // import (which always has zero conflicts).
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
