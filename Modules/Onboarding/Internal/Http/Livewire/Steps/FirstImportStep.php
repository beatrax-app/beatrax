<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 5 — the consolidated commit surface.
 *
 * This step replaces the earlier upload-wizard wrapper with a single
 * "review everything we found, then commit" page. On mount it reads
 * the ImportRun ids the connector steps stashed into
 * `wizard_progress.data` (the bank step writes one int, the card step
 * writes an int array; PayPal will land alongside when that connector
 * ships) and feeds the union into Plan 07's
 * `BuildConsolidatedPreviewQuery`, which applies the user-id boundary
 * + stale + already-confirmed filters and groups survivors by source
 * format. The step also feeds the same id list into Plan 05b's
 * `DetectStartingBalancesQuery` to surface a `StartingBalanceCandidate`
 * per detected account.
 *
 * The body renders:
 *
 *   1. One `consolidated-preview-section` partial per
 *      `ConsolidatedPreviewSection` in the batch.
 *   2. One `StartingBalanceCard` child Livewire component per
 *      detected account (and one per detected-but-no-candidate
 *      account so the user can manually enter a starting balance for
 *      a PayPal wallet that auto-detect can't reach).
 *   3. One commit footer with the live deduplicated counter + a
 *      single "Commit everything (N transactions) →" primary CTA.
 *
 * `commit()` wraps every `ConfirmImport(..., dispatchChain: false)`
 * call AND every `accounts.starting_balance_*` UPDATE in a single
 * `DB::transaction()`. On success the step dispatches chain
 * resolution + recurring detection ONCE post-transaction (mirroring
 * the standalone ConfirmImport action) and dispatches
 * `wizard.step.completed` so the SetupWizard parent advances. On
 * failure the rose inline error surfaces and nothing was changed
 * (rollback is automatic).
 *
 * The `wiz-card--wide` class on the rendered card preserves the
 * locked 1120px width exception for this step (`FirstImportStepWidthTest`).
 *
 * Per the project DI-only rule, every collaborator is method-DI'd —
 * Livewire forbids constructor DI on Component subclasses.
 */
final class FirstImportStep extends Component
{
    /**
     * Per-account user confirmations the child cards have aggregated
     * via the `starting-balance.confirmed` event listener. Keyed by
     * `accountId`. The commit() action writes each entry to
     * `accounts.starting_balance_minor` + `accounts.starting_balance_date`
     * inside the outer transaction.
     *
     * @var array<int, array{minor: int, date: string}>
     */
    public array $balanceConfirmations = [];

    /**
     * In-memory consolidated preview batch produced by
     * `BuildConsolidatedPreviewQuery::build()` on every render(). Not
     * a Livewire-serialised property — Livewire 4 cannot hydrate the
     * Spatie Data DTO across HTTP boundaries — so the batch is held
     * as a plain property the render-pass repopulates and the blade
     * reads via `View::with()`. The same constraint applies to the
     * starting-balance candidate list and the per-account meta.
     */
    private ?ConsolidatedPreviewBatch $preview = null;

    /**
     * @var list<StartingBalanceCandidate>
     */
    private array $startingBalances = [];

    /**
     * @var array<int, array{label: string, short: string, currency: string}>
     */
    private array $accountMeta = [];

    /**
     * Inline rose error surfaced beneath the commit button when the
     * outer transaction rolls back. Empty string when no error.
     */
    public string $commitError = '';

    /**
     * True while the commit() action is executing — disables the
     * primary CTA and swaps its label to "Committing…" per UI-SPEC
     * line 342.
     */
    public bool $isCommitting = false;

    /**
     * Cached union of every stashed ImportRun id (bank + cards +
     * PayPal). Recomputed on every render() so a back-and-forth
     * through earlier wizard steps (which mutate the stash) reflects
     * fresh on this page without a manual refresh.
     *
     * @var list<int>
     */
    public array $stashedImportRunIds = [];

    public function mount(): void
    {
        $this->balanceConfirmations = [];
        $this->commitError = '';
        $this->isCommitting = false;
        $this->stashedImportRunIds = [];
    }

    /**
     * Public read accessor — the consolidated preview batch built by
     * the current render pass. Returns an empty batch when called
     * before the first render. The blade reads the same batch via the
     * `$preview` view variable injected through `View::with()`, but
     * tests prefer this accessor over an HTML-shape assertion.
     */
    public function currentPreview(): ConsolidatedPreviewBatch
    {
        return $this->preview ?? new ConsolidatedPreviewBatch(
            sections: [],
            dedupedTotalCount: 0,
            alreadyImportedCount: 0,
        );
    }

    /**
     * Listener — the StartingBalanceCard child component dispatches
     * `starting-balance.confirmed` whenever the user accepts a value
     * (Confirm pill, Save inside the editor, or a conflict-resolution
     * pick). The payload's `accountId` is the load-bearing
     * scope-write — we never trust the caller's accountId on UPDATE;
     * the `commit()` action carries the `where('user_id', $user->id)`
     * filter so a tampered child dispatch cannot leak a write to a
     * sibling user's account row.
     */
    #[On('starting-balance.confirmed')]
    public function onStartingBalanceConfirmed(int $accountId, int $minor, string $date): void
    {
        $this->balanceConfirmations[$accountId] = [
            'minor' => $minor,
            'date' => $date,
        ];
    }

    /**
     * Atomic commit — wraps every stashed ImportRun's
     * `ConfirmImport(..., dispatchChain: false)` call AND every
     * `accounts.starting_balance_*` UPDATE in a single DB
     * transaction. After the transaction returns successfully, the
     * chain resolver + recurring detection dispatchers are invoked
     * ONCE (not per-run) to keep the queue worker from racing the
     * SQLite commit.
     *
     * On any throwable the transaction rolls back, the rose error
     * surfaces inline, and the step stays where it is (no state
     * change to wizard_progress, no chain dispatch).
     */
    public function commit(
        DatabaseManager $db,
        ConfirmsImports $confirmImport,
        CurrentUser $currentUser,
        Clock $clock,
        DispatchesChainResolution $chainDispatcher,
        DispatchesRecurringDetection $recurringDispatcher,
        BuildConsolidatedPreviewQuery $buildPreview,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->commitError = '';
        $this->isCommitting = true;

        try {
            $user = $currentUser->user();

            // Rebuild the consolidated preview at commit time. Livewire
            // calls action methods directly without running render()
            // first, so we cannot rely on the property the render
            // pass populates. Re-running the query also drops any
            // run-ids the user may have removed from a connector step
            // since the page loaded.
            $stashedIds = $this->resolveStashedImportRunIds($user->id, $db);
            $preview = $buildPreview->build($stashedIds, $user);

            // The query already groups by source format AND filters
            // out empty / errored sections. Only `ready` sections feed
            // the commit loop so a half-broken upload doesn't take the
            // whole batch down with it.
            $runIdsToCommit = [];
            foreach ($preview->sections as $section) {
                if ($section->status !== 'ready') {
                    continue;
                }
                foreach ($section->importRunIds as $runId) {
                    $runIdsToCommit[] = $runId;
                }
            }

            if ($runIdsToCommit === []) {
                $this->commitError = 'Nothing to commit.';

                return;
            }

            $now = $clock->now()->toDateTimeString();
            $balanceConfirmations = $this->balanceConfirmations;

            $db->connection()->transaction(function () use (
                $runIdsToCommit,
                $balanceConfirmations,
                $confirmImport,
                $user,
                $db,
                $now,
            ): void {
                foreach ($runIdsToCommit as $runId) {
                    ($confirmImport)($runId, $user, dispatchChain: false);
                }
                foreach ($balanceConfirmations as $accountId => $confirmation) {
                    $db->connection()
                        ->table('accounts')
                        ->where('id', $accountId)
                        ->where('user_id', $user->id)
                        ->update([
                            'starting_balance_minor' => $confirmation['minor'],
                            'starting_balance_date' => $confirmation['date'],
                            'updated_at' => $now,
                        ]);
                }
            });

            // Dispatch chain resolution + recurring detection ONCE
            // after the outer commit. Per the standalone ConfirmImport
            // action: inside the transaction would let the queue
            // worker read stale state; once per inner ImportRun would
            // multiply the worker hits. One post-commit dispatch
            // collapses both pitfalls.
            $chainDispatcher->dispatchForUser($user->id);
            $recurringDispatcher->dispatchForUser($user->id);

            // Mark the wizard_progress row done + advance the wizard
            // to the DoneStep. The SetupWizard listens for
            // `wizard.step.completed` and runs the same
            // `progress.update(...)` we mirror here for robustness
            // when the dispatch path is stubbed in a test harness.
            $db->connection()
                ->table('wizard_progress')
                ->where('user_id', $user->id)
                ->where('step_key', 'first-import')
                ->update([
                    'status' => 'done',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->dispatch('wizard.step.completed');
        } catch (Throwable $e) {
            $logger->error('FirstImportStep: commit-everything failed.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->commitError = sprintf(
                "We couldn't commit your statements: %s. Nothing was changed.",
                $e->getMessage(),
            );
        } finally {
            $this->isCommitting = false;
        }
    }

    public function render(
        ViewFactory $views,
        BuildConsolidatedPreviewQuery $buildPreview,
        DetectStartingBalancesQuery $detectBalances,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): View {
        $user = $currentUser->user();

        $this->stashedImportRunIds = $this->resolveStashedImportRunIds($user->id, $db);
        $this->preview = $buildPreview->build($this->stashedImportRunIds, $user);

        // The detector aggregator returns a flat list with one entry per
        // (account, winning candidate) pair. When the aggregator surfaces
        // two candidates for the same account (conflict), both appear in
        // this list — the blade groups by accountId and switches the
        // card into the conflict variant.
        $this->startingBalances = $detectBalances->collect($this->stashedImportRunIds, $user);

        // Resolve the per-card props (label, short-id, currency) from
        // the accounts table now so the blade stays free of
        // cross-module queries.
        $this->accountMeta = $this->loadAccountMeta($user->id, $db, $this->startingBalances);

        return $views->make('onboarding::livewire.steps.first-import-step', [
            'preview' => $this->preview,
            'startingBalances' => $this->startingBalances,
            'accountMeta' => $this->accountMeta,
        ]);
    }

    /**
     * Read every connector step's stash payload out of
     * `wizard_progress.data` for the current user, union the int +
     * int-array values into a single deduplicated list of ImportRun
     * ids, and preserve insertion order so the consolidated preview
     * orders bank → ICS card → PayPal sections deterministically.
     *
     * Uses raw `DatabaseManager::table()` rather than
     * `WizardProgress::query()` so the explicit user-scope filter is
     * the single load-bearing user-id check — never relying on the
     * BelongsToUser global scope, which falls through under non-HTTP
     * contexts (queue workers, listener tests).
     *
     * @return list<int>
     */
    private function resolveStashedImportRunIds(int $userId, DatabaseManager $db): array
    {
        $rows = $db->connection()
            ->table('wizard_progress')
            ->where('user_id', $userId)
            ->whereIn('step_key', ['connect-bank', 'connect-card', 'connect-email'])
            ->orderByRaw("CASE step_key WHEN 'connect-bank' THEN 1 WHEN 'connect-card' THEN 2 ELSE 3 END")
            ->get(['step_key', 'data']);

        $ids = [];
        foreach ($rows as $row) {
            $dataRaw = $row->data;
            if (! is_string($dataRaw) || $dataRaw === '') {
                continue;
            }
            $decoded = json_decode($dataRaw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $bankId = $decoded['bank_import_run_id'] ?? null;
            if (is_int($bankId) && $bankId > 0) {
                $ids[] = $bankId;
            }

            $cardIds = $decoded['card_import_run_ids'] ?? null;
            if (is_array($cardIds)) {
                foreach ($cardIds as $cardId) {
                    if (is_int($cardId) && $cardId > 0) {
                        $ids[] = $cardId;
                    }
                }
            }
        }

        // Deduplicate while preserving first-occurrence order.
        $seen = [];
        $out = [];
        foreach ($ids as $id) {
            if (array_key_exists($id, $seen)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * Look up the account metadata the StartingBalanceCard children
     * need to render (label, short-id, currency). Keyed by accountId
     * so the blade can mount each card without an additional query.
     *
     * @param  list<StartingBalanceCandidate>  $candidates
     * @return array<int, array{label: string, short: string, currency: string}>
     */
    private function loadAccountMeta(int $userId, DatabaseManager $db, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $accountIds = [];
        foreach ($candidates as $candidate) {
            $accountIds[$candidate->accountId] = true;
        }

        $rows = $db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->whereIn('id', array_keys($accountIds))
            ->get(['id', 'name', 'iban', 'default_currency', 'kind']);

        $meta = [];
        foreach ($rows as $row) {
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            $name = is_string($row->name) ? $row->name : '';
            $iban = is_string($row->iban) ? $row->iban : '';
            $currency = is_string($row->default_currency) ? $row->default_currency : 'EUR';
            $kind = is_string($row->kind) ? $row->kind : '';

            $shortTail = strlen($iban) >= 4 ? substr($iban, -4) : $iban;
            $kindLabel = match ($kind) {
                'asn' => 'ASNB',
                'ics_card' => 'ICS',
                'paypal' => 'PAYPAL',
                default => strtoupper($kind),
            };

            $meta[$id] = [
                'label' => $name,
                'short' => sprintf('%s · %s', $kindLabel, $shortTail),
                'currency' => $currency,
            ];
        }

        return $meta;
    }
}
