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
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Onboarding\Public\Enums\WizardStepStatus;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;
use Psr\Log\LoggerInterface;
use Throwable;

final class FirstImportStep extends Component
{
    /** @var array<int, array{minor: int, date: string}> */
    public array $balanceConfirmations = [];

    // Private, because Livewire cannot hydrate a Spatie Data DTO across the
    // HTTP boundary; render() repopulates it and hands it to the blade.
    private ?ConsolidatedPreviewBatch $preview = null;

    /** @var list<StartingBalanceCandidate> */
    private array $startingBalances = [];

    /** @var array<int, array{label: string, short: string, currency: string}> */
    private array $accountMeta = [];

    public string $commitError = '';

    public bool $isCommitting = false;

    // Recomputed on every render(), so walking back to a connector step and
    // returning shows the new run without a manual refresh.
    /** @var list<int> */
    public array $stashedImportRunIds = [];

    // Absolute per-section row cap, stepping 5 → 30 → 55 as the user clicks
    // "Load more". A missing key means the default 5.
    /** @var array<string, int> */
    public array $expandedRowCount = [];

    public function mount(): void
    {
        $this->balanceConfirmations = [];
        $this->commitError = '';
        $this->isCommitting = false;
        $this->stashedImportRunIds = [];
        $this->expandedRowCount = [];
    }

    // Empty batch before the first render, so tests can assert on state
    // rather than on rendered HTML.
    public function currentPreview(): ConsolidatedPreviewBatch
    {
        return $this->preview ?? new ConsolidatedPreviewBatch(
            sections: [],
            dedupedTotalCount: 0,
            alreadyImportedCount: 0,
        );
    }

    // accountId arrives from a child component and is never trusted on the
    // UPDATE: persistCommit() re-filters on where('user_id', ...) so a forged
    // dispatch cannot write to another user's row.
    #[On('starting-balance.confirmed')]
    public function onStartingBalanceConfirmed(int $accountId, int $minor, string $date): void
    {
        $this->balanceConfirmations[$accountId] = [
            'minor' => $minor,
            'date' => $date,
        ];
    }

    // Safe past totalRows: the query clamps the slice server-side.
    public function loadMoreRows(string $sourceFormat): void
    {
        $current = $this->expandedRowCount[$sourceFormat]
            ?? BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT;
        $this->expandedRowCount[$sourceFormat] = $current + 25;
    }

    // Not named commit(): Livewire reserves $commit as a magic state-sync
    // action and a method by that name never reaches user code.
    /**
     * @link ../../../../../../.docs/features/onboarding/architecture.md#firstimportstep--the-consolidated-commit-surface
     */
    public function commitEverything(
        DatabaseManager $db,
        ConfirmsImports $confirmImport,
        CurrentUser $currentUser,
        Clock $clock,
        BuildConsolidatedPreviewQuery $buildPreview,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->commitError = '';
        $this->isCommitting = true;

        try {
            $user = $currentUser->user();

            $logger->info('FirstImportStep: commitEverything() invoked.', [
                'user_id' => $user->id,
                'balance_confirmations' => count($this->balanceConfirmations),
            ]);

            // Livewire invokes action methods without running render() first.
            $stashedIds = $this->resolveStashedImportRunIds($user->id, $db);
            $runIdsToCommit = $this->readyRunIds($buildPreview->build($stashedIds, $user));

            if ($runIdsToCommit === []) {
                $this->commitError = Lang::get('onboarding::first_import.errors.nothing_to_commit');

                return;
            }

            $now = $clock->now()->toDateTimeString();
            $balanceConfirmations = $this->balanceConfirmations;

            $db->connection()->transaction(fn () => $this->persistCommit($db, $confirmImport, $user, $now, $runIdsToCommit, $balanceConfirmations));

            $this->dispatchPostCommit($app, $logger, $user->id);

            $this->dispatch('wizard.step.completed');
        } catch (Throwable $e) {
            $logger->error('FirstImportStep: commit-everything failed.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->commitError = Lang::get('onboarding::first_import.errors.commit_failed');
        } finally {
            $this->isCommitting = false;
        }
    }

    // Someone who skipped every connector has no ready section and so no
    // enabled commit button; without skip they could never reach budgets,
    // tax-country or done.
    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    // Only ready sections commit, so one broken upload cannot sink the batch.
    /**
     * @return list<int>
     */
    private function readyRunIds(ConsolidatedPreviewBatch $preview): array
    {
        $runIds = [];
        foreach ($preview->sections as $section) {
            if ($section->status !== 'ready') {
                continue;
            }
            foreach ($section->importRunIds as $runId) {
                $runIds[] = $runId;
            }
        }

        return $runIds;
    }

    /**
     * @param  list<int>  $runIdsToCommit
     * @param  array<int, array{minor: int, date: string}>  $balanceConfirmations
     *
     * @link ../../../../../../.docs/features/onboarding/architecture.md#firstimportstep--the-consolidated-commit-surface
     */
    private function persistCommit(DatabaseManager $db, ConfirmsImports $confirmImport, User $user, string $now, array $runIdsToCommit, array $balanceConfirmations): void
    {
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
        $db->connection()
            ->table('wizard_progress')
            ->where('user_id', $user->id)
            ->where('step_key', 'first-import')
            ->update([
                'status' => WizardStepStatus::Done->value,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    // Runs after the transaction commits, so a failure here cannot undo the
    // import: swallowed and logged, and the next scheduled sweep catches up.
    private function dispatchPostCommit(Application $app, LoggerInterface $logger, int $userId): void
    {
        try {
            $app->make(DispatchesChainResolution::class)->dispatchForUser($userId);
            $app->make(DispatchesRecurringDetection::class)->dispatchForUser($userId);
        } catch (Throwable $dispatchException) {
            $logger->error('FirstImportStep: post-commit dispatch failed (data already committed).', [
                'exception_class' => $dispatchException::class,
                'exception_message' => $dispatchException->getMessage(),
                'exception_trace' => SafeTrace::cap($dispatchException, $app->basePath()),
            ]);
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
        $this->preview = $buildPreview->build(
            $this->stashedImportRunIds,
            $user,
            sectionLimitOverrides: $this->expandedRowCount,
        );

        // Two candidates for one account is the conflict case; the blade
        // groups this flat list by accountId to spot it.
        $this->startingBalances = $detectBalances->collect($this->stashedImportRunIds, $user);

        $this->accountMeta = $this->loadAccountMeta($user->id, $db, $this->startingBalances);

        return $views->make('onboarding::livewire.steps.first-import-step', [
            'preview' => $this->preview,
            'startingBalances' => $this->startingBalances,
            'accountMeta' => $this->accountMeta,
        ]);
    }

    // Raw table() rather than WizardProgress::query(), so the user-scope
    // filter below is the real one: BelongsToUser's global scope falls
    // through outside an HTTP request (queue workers, listener tests).
    /**
     * @return list<int>
     */
    private function resolveStashedImportRunIds(int $userId, DatabaseManager $db): array
    {
        $rows = $db->connection()
            ->table('wizard_progress')
            ->where('user_id', $userId)
            ->whereIn('step_key', ['connect-bank', 'connect-paypal', 'connect-card', 'connect-email'])
            ->orderByRaw("CASE step_key WHEN 'connect-bank' THEN 1 WHEN 'connect-paypal' THEN 2 WHEN 'connect-card' THEN 3 ELSE 4 END")
            ->get(['step_key', 'data']);

        $ids = [];
        foreach ($rows as $row) {
            $dataRaw = $row->data;
            if (! is_string($dataRaw) || $dataRaw === '') {
                continue;
            }
            $decoded = json_decode($dataRaw, true);
            if (is_array($decoded)) {
                $ids = array_merge($ids, $this->runIdsFromStashRow($decoded));
            }
        }

        // First-occurrence order survives, which the orderByRaw above has
        // already fixed as bank → PayPal → ICS card → email.
        return array_values(array_unique($ids));
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     * @return list<int>
     */
    private function runIdsFromStashRow(array $decoded): array
    {
        $ids = [];
        foreach (['bank_import_run_id', 'paypal_import_run_id'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_int($value) && $value > 0) {
                $ids[] = $value;
            }
        }

        $cardIds = $decoded['card_import_run_ids'] ?? null;
        if (is_array($cardIds)) {
            foreach ($cardIds as $cardId) {
                if (is_int($cardId) && $cardId > 0) {
                    $ids[] = $cardId;
                }
            }
        }

        return $ids;
    }

    /**
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
