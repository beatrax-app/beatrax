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
 * `/migrations/{run}/preview` — the wizard's confirm/discard step (Req
 * 11/12). Renders the 5-up mapped-counts stat grid + the grouped
 * unmapped-items disclosure list (Categories/Payees/Extras/Conflicts) built
 * purely from `PreviewSummaryBuilder::forRun()` — a read model over
 * `migration_staging_*`, never a domain table (D-06/D-07). No domain write
 * happens anywhere in this component; the ONLY writing action is
 * `confirm()` -> `ConfirmMigration`.
 *
 * IDOR guard (T-13.5-24): a user-scoped `MigrationRun::query()->where('id',
 * ...)->where('user_id', ...)->firstOrFail()` runs at the top of `render()`
 * AND every action (`confirm()`/`discard()`) — `PreviewSummaryBuilder`
 * itself also throws a `ModelNotFoundException` for a foreign run as a
 * second, independent guard (defense in depth, mirrors
 * `PreviewWizard`/`ConfirmMigration`'s identical discipline). Either guard
 * alone is sufficient; both are present.
 *
 * Conflict rows (Req 10/D-14, 13.5-HUMAN-UAT.md Test 3c gap-fix):
 * `CheckForUpdates` records every conflict with its resolution left NULL
 * (default keep-local) — it is deliberately NOT finalized at reconciliation
 * time. `resolveConflict()` below persists the user's actual toggle choice
 * directly onto that conflict's `migration_staging_unmapped_items` row,
 * scoped to this run + the authenticated user; `render()` reads the
 * CURRENT resolution back from `PreviewSummaryBuilder` on every render, so
 * the toggle reflects the real, persisted state rather than a client-side-
 * only copy. `ConfirmMigration` is the ONLY place a resolution is actually
 * APPLIED (source value written to the domain, or left alone for
 * keep-local) — see that class's docblock for the full apply/baseline-
 * advance sequence.
 *
 * `confirm()` is safe to call uniformly for BOTH a first-time run
 * ('parsed' -> promotes + flips to 'confirmed') and a reconciliation run
 * ('needs_attention' -> `ConfirmMigration`'s promote() call re-visits an
 * already-resolve-gated batch, a safe no-op, and flips the run to
 * 'confirmed' as a review acknowledgement; 'confirmed' already ->
 * `ConfirmMigration`'s own already-confirmed short-circuit returns the
 * persisted counts without re-promoting).
 *
 * Migration has no "name this account/category" naming flow analogous to
 * Import's ICS/PayPal/unfamiliar-IBAN prompts — every migrated account
 * gets a deterministic synthetic pseudo-IBAN (`SourceMapWriter`/
 * `PromoteStagingToDomain`, Plan 06) with no user input required, so there
 * is no `$canConfirmImport`-equivalent precondition to gate Confirm on.
 */
final class PreviewMigration extends Component
{
    public int $runId = 0;

    public function mount(int $id): void
    {
        $this->runId = $id;
    }

    /**
     * Persists the user's keep-local/take-source choice for ONE conflict
     * row, scoped to this run + the authenticated user (IDOR-safe — a
     * forged `$conflictId` belonging to another run/user matches zero
     * rows, a silent no-op rather than a 404, mirroring this component's
     * existing defense-in-depth style). `ConfirmMigration` reads this
     * column later; nothing is applied to the domain here.
     */
    public function resolveConflict(int $conflictId, string $choice, DatabaseManager $db, CurrentUser $currentUser): void
    {
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

        // Defense-in-depth IDOR guard — a forged runId 404s here even
        // before ConfirmMigration's own identical guard runs.
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

        // A second, independent IDOR guard (T-13.5-24) — PreviewSummaryBuilder
        // itself throws ModelNotFoundException for a foreign-owned run.
        $summary = $builder->forRun($this->runId, $user);

        return $views->make('migration::livewire.preview-migration', [
            'run' => $run,
            'summary' => $summary,
        ]);
    }

    /**
     * Every count in the 5-up stat grid that has a directly corresponding
     * unmapped-items group shows the "✓ fully mapped" micro-label when that
     * group is empty. Only Categories (vs `unmapped.category`) and
     * Counterparties (vs `unmapped.payee`) have such a group in this
     * schema — Accounts/Transactions/Budget months are not tracked at
     * per-row unmapped granularity, so they never show the micro-label.
     */
    public function fullyMapped(PreviewSummary $summary, string $statKey): bool
    {
        return match ($statKey) {
            'category' => $summary->unmapped['category']['count'] === 0,
            'payee' => $summary->unmapped['payee']['count'] === 0,
            default => false,
        };
    }
}
