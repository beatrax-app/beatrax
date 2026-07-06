<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
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
 * Conflict rows (Req 10/D-14): `CheckForUpdates` (Plan 07) already applies
 * the keep-local default AND advances the baseline to the local value
 * SYNCHRONOUSLY, before this page is ever rendered — a reconciliation run
 * arrives here already in its final `needs_attention`/`confirmed` state
 * with every non-conflicting change written and every conflicted row
 * byte-for-byte untouched. `$conflictResolutions` renders the UI-SPEC's
 * keep-local/take-source segmented toggle (default keep-local) and is
 * fully interactive client-side, but — since Plan 07's backend contract
 * commits the keep-local decision unconditionally at `CheckForUpdates`
 * time, not deferred to a resolvable-before-confirm step — selecting
 * "Take source" has no live backend effect in this plan's scope. This is a
 * documented, inherited scope limitation (see 13.5-07-SUMMARY.md's "Next
 * Phase Readiness" note to Plan 08), not a bug: the plan's own acceptance
 * criteria only require conflicted rows to remain untouched after confirm,
 * which already holds regardless of toggle state. Flagged in this plan's
 * own Summary for a future backlog item.
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

    /**
     * Conflict-row resolution toggle state, keyed by the conflict's index
     * within `$summary->unmapped['conflict']['items']`. Defaults to
     * 'keep_local' for every row on first render (D-14). See class
     * docblock for why this does not currently drive a backend action.
     *
     * @var array<string, string>
     */
    public array $conflictResolutions = [];

    public function mount(int $id): void
    {
        $this->runId = $id;
    }

    /**
     * Client-side-only toggle (see class docblock's documented scope
     * limitation) — kept as a real, wired Livewire action so the UI-SPEC's
     * segmented control is genuinely interactive, not decorative markup.
     */
    public function resolveConflict(string $key, string $choice): void
    {
        if (! in_array($choice, ['keep_local', 'take_source'], true)) {
            return;
        }

        $this->conflictResolutions[$key] = $choice;
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

        $this->seedConflictResolutionDefaults($summary);

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

    /**
     * Seeds a 'keep_local' default (D-14) for every conflict row not
     * already present in `$conflictResolutions` — idempotent across
     * repeated renders (e.g. after a resolveConflict() call already set a
     * different value, that value survives).
     */
    private function seedConflictResolutionDefaults(PreviewSummary $summary): void
    {
        foreach (array_keys($summary->unmapped['conflict']['items']) as $index) {
            // Prefixed so the key is never a "decimal-int-string" — a bare
            // numeric string key silently casts back to an int array key at
            // the PHP engine level, breaking the declared
            // `array<string, string>` property type under PHPStan L10.
            $key = 'c'.$index;
            if (! isset($this->conflictResolutions[$key])) {
                $this->conflictResolutions[$key] = 'keep_local';
            }
        }
    }
}
