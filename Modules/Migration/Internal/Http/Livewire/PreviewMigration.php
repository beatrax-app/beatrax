<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\DiscardMigrationRun;
use Modules\Migration\Internal\Dto\PreviewSummary;
use Modules\Migration\Internal\Enums\ConflictResolution;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Pipeline\PreviewSummaryBuilder;
use Modules\Migration\Models\MigrationRun;

final class PreviewMigration extends Component
{
    public int $runId = 0;

    public function mount(int $id): void
    {
        $this->runId = $id;
    }

    public function resolveConflict(int $conflictId, string $choice, DatabaseManager $db, CurrentUser $currentUser): void
    {
        // Scoped to this run and user, so a forged $conflictId matches zero rows
        // and no-ops. Nothing is applied to the domain until ConfirmMigration.
        $resolution = ConflictResolution::tryFrom($choice);
        if ($resolution === null) {
            return;
        }

        $user = $currentUser->user();

        $db->connection()->table('migration_staging_unmapped_items')
            ->where('id', $conflictId)
            ->where('migration_run_id', $this->runId)
            ->where('user_id', $user->id)
            ->where('item_type', UnmappedItemType::Conflict->value)
            ->update(['resolution' => $resolution->value]);
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

        $summary = $builder->forRun($this->runId, $user);

        return $views->make('migration::livewire.preview-migration', [
            'run' => $run,
            'summary' => $summary,
        ]);
    }

    public function fullyMapped(PreviewSummary $summary, string $statKey): bool
    {
        // Only categories and counterparties have an unmapped-items group in this
        // schema, so nothing else can show the "fully mapped" micro-label.
        return match ($statKey) {
            'category' => $summary->unmapped['category']['count'] === 0,
            'payee' => $summary->unmapped['payee']['count'] === 0,
            default => false,
        };
    }
}
