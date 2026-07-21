<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\ConfirmMigration;
use Modules\Migration\Public\Actions\DiscardMigrationRun;
use Modules\Migration\Public\Dto\PreviewSummary;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class PreviewMigration extends Component
{
    public int $runId = 0;

    public function mount(int $id): void
    {
        $this->runId = $id;
    }

    public function resolveConflict(int $conflictId, string $choice, DatabaseManager $db, CurrentUser $currentUser): void
    {
        // Scoped to this run + the authenticated user — a forged
        // $conflictId belonging to another run/user matches zero rows (a
        // silent no-op, not a 404). ConfirmMigration reads this column
        // later; nothing is applied to the domain here.
        if (! in_array($choice, ['keep_local', 'take_source'], true)) {
            return;
        }

        $user = $currentUser->user();

        $db->connection()->table('migration_staging_unmapped_items')
            ->where('id', $conflictId)
            ->where('migration_run_id', $this->runId)
            ->where('user_id', $user->id)
            ->where('item_type', 'conflict')
            ->update(['resolution' => $choice]);
    }

    public function confirm(
        ConfirmMigration $confirmer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        // Defence-in-depth guard — a forged runId 404s here even before
        // ConfirmMigration's own identical guard runs.
        MigrationRun::query()
            ->where('id', $this->runId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        ($confirmer)($this->runId, $user);

        $this->redirect(
            $urls->route('migrations.results', ['id' => $this->runId]),
            navigate: false,
        );
    }

    public function discard(
        DiscardMigrationRun $discarder,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        MigrationRun::query()
            ->where('id', $this->runId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        ($discarder)($this->runId, $user);

        $this->redirect($urls->route('migrations.new'), navigate: false);
    }

    public function render(ViewFactory $views, PreviewSummaryBuilder $builder, CurrentUser $currentUser): View
    {
        $user = $currentUser->user();

        /** @var MigrationRun $run */
        $run = MigrationRun::query()
            ->where('id', $this->runId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // A second, independent guard — PreviewSummaryBuilder itself throws
        // ModelNotFoundException for a foreign-owned run.
        $summary = $builder->forRun($this->runId, $user);

        return $views->make('migration::livewire.preview-migration', [
            'run' => $run,
            'summary' => $summary,
        ]);
    }

    public function fullyMapped(PreviewSummary $summary, string $statKey): bool
    {
        // Only Categories/Counterparties have a corresponding unmapped-items
        // group in this schema — Accounts/Transactions/Budget months are not
        // tracked at per-row unmapped granularity, so they never show the
        // "fully mapped" micro-label.
        return match ($statKey) {
            'category' => $summary->unmapped['category']['count'] === 0,
            'payee' => $summary->unmapped['payee']['count'] === 0,
            default => false,
        };
    }
}
