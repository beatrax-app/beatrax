<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Migration\Internal\Exceptions\MigrationRunNotParsedException;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;

final class MigrationResults extends Component
{
    // Locked for the reason its Import twin is: render() re-checks ownership
    // so a foreign id 404s, but unlocked the client still picks which of
    // its own runs this page reports on.
    #[Locked]
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
            // A discarded run reached this page directly, so staging is already
            // truncated and the still-unmapped section is omitted.
        }

        $stillNeedsAttention = $unmapped === null ? 0 : array_sum(array_column($unmapped, 'count'));

        // Display-only heuristic: only CheckForUpdates ever populates conflicts,
        // and a first-time import always has zero.
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
