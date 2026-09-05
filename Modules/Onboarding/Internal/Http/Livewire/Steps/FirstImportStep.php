<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Chains\Public\Contracts\DispatchesChainResolution;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Iban;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Dto\ConsolidatedPreviewBatch;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Enums\PreviewSectionStatus;
use Modules\Import\Public\Exceptions\ImportNotConfirmableException;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountWriter;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Onboarding\Internal\Enums\WizardStepStatus;
use Modules\Onboarding\Internal\Services\StartingBalanceRule;
use Modules\Recurring\Public\Contracts\DispatchesRecurringDetection;
use Psr\Log\LoggerInterface;
use Throwable;

final class FirstImportStep extends Component
{
    /** @var array<array-key, mixed> Wire-writable, so both key and shape are checked before use. */
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

    // Recomputed every render() so a run added on a connector step shows up
    // on the way back without a manual refresh.
    /** @var list<int> */
    public array $stashedImportRunIds = [];

    // Absolute per-section row cap, stepping 5 → 30 → 55 as the user clicks
    // "Load more". A missing key means the default 5. Locked because
    // loadMoreRows() is the only writer and this reaches the preview query's
    // row slice, which drew whatever number arrived here into one fragment.
    /** @var array<string, int> */
    #[Locked]
    public array $expandedRowCount = [];

    public function mount(): void
    {
        $this->balanceConfirmations = [];
        $this->commitError = '';
        $this->isCommitting = false;
        $this->stashedImportRunIds = [];
        $this->expandedRowCount = [];
    }

    // accountId comes off a dispatch and is never trusted on the UPDATE:
    // persistCommit() re-filters on user_id so a forgery cannot write elsewhere.
    #[On('starting-balance.confirmed')]
    public function onStartingBalanceConfirmed(int $accountId, int $minor, string $date): void
    {
        $this->balanceConfirmations[$accountId] = [
            'minor' => $minor,
            'date' => $date,
        ];
    }

    public function loadMoreRows(string $sourceFormat): void
    {
        $current = $this->expandedRowCount[$sourceFormat]
            ?? BuildConsolidatedPreviewQuery::SAMPLE_ROW_LIMIT;
        $this->expandedRowCount[$sourceFormat] = $current + 25;
    }

    // Not named commit(): Livewire reserves $commit as a magic state-sync
    // action and a method by that name never reaches user code.
    public function commitEverything(
        DatabaseManager $db,
        ConfirmsImports $confirmImport,
        CurrentUser $currentUser,
        Clock $clock,
        BuildConsolidatedPreviewQuery $buildPreview,
        LoggerInterface $logger,
        Application $app,
        StartingBalanceRule $balanceRule,
    ): void {
        $this->commitError = '';
        $this->isCommitting = true;

        try {
            $user = $currentUser->user();

            $logger->info('FirstImportStep: commitEverything() invoked.', [
                'user_id' => $user->id,
                'balance_confirmations' => count($this->balanceConfirmations),
            ]);

            $stashedIds = $this->resolveStashedImportRunIds($user->id, $db);
            $runIdsToCommit = $this->readyRunIds($buildPreview->build($stashedIds, $user));

            if ($runIdsToCommit === []) {
                $this->commitError = Lang::get('onboarding::first_import.errors.nothing_to_commit');

                return;
            }

            $now = $clock->now()->toDateTimeString();
            $balanceConfirmations = $this->acceptedBalanceConfirmations($balanceRule);

            $db->connection()->transaction(function () use ($db, $confirmImport, $user, $now, $runIdsToCommit, $logger): void {
                $this->confirmEachStagedRun($confirmImport, $user, $runIdsToCommit, $logger);
                $this->persistCommit($db, $user, $now);
            });

            // After the commit, and after the confirm inside it: the confirm
            // captures each account as a whole row, so an anchor written before
            // it travelled and one written inside it would be announced from
            // within an outer transaction.
            $this->anchorConfirmedBalances($app->make(AccountWriter::class), $user->id, $balanceConfirmations);

            $this->dispatchPostCommit($app, $logger, $user->id);

            $this->dispatch('wizard.step.completed');
        } catch (Throwable $e) {
            $logger->error('FirstImportStep: commit-everything failed.', [
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->commitError = Lang::get('onboarding::first_import.errors.commit_failed');
        } finally {
            $this->isCommitting = false;
        }
    }

    // Someone who skipped every connector has no ready section and so no enabled
    // commit button; without skip they could never reach budgets or tax-country.
    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    /**
     * @return list<int>
     */
    private function readyRunIds(ConsolidatedPreviewBatch $preview): array
    {
        $runIds = [];
        foreach ($preview->sections as $section) {
            if ($section->status !== PreviewSectionStatus::Ready) {
                continue;
            }
            foreach ($section->importRunIds as $runId) {
                $runIds[] = $runId;
            }
        }

        return $runIds;
    }

    // The map is a public property and onStartingBalanceConfirmed() is wire-
    // callable, so a row reaching the write has been through the same rule the
    // card applies to its own field — shape first, then range and date.
    /**
     * @return array<int, array{minor: int, date: string}>
     */
    private function acceptedBalanceConfirmations(StartingBalanceRule $balanceRule): array
    {
        $accepted = [];
        foreach ($this->balanceConfirmations as $accountId => $confirmation) {
            $confirmed = $balanceRule->confirmed($confirmation);
            if ($confirmed !== null && is_numeric($accountId)) {
                $accepted[(int) $accountId] = $confirmed;
            }
        }

        return $accepted;
    }

    // Every run here was offered because the consolidated preview said the
    // confirm would take it, so a refusal this late means that stopped being
    // true in between -- a preview cache that expired mid-review. One run is
    // then all that is lost, rather than the whole staged set behind it.
    /**
     * @param  list<int>  $runIdsToCommit
     */
    private function confirmEachStagedRun(ConfirmsImports $confirmImport, User $user, array $runIdsToCommit, LoggerInterface $logger): void
    {
        foreach ($runIdsToCommit as $runId) {
            try {
                ($confirmImport)($runId, $user, dispatchChain: false);
            } catch (ImportNotConfirmableException|PreviewExpiredException $refused) {
                $logger->warning('FirstImportStep: a staged run was refused at commit and left for a re-upload.', [
                    'import_run_id' => $runId,
                    ...SafeExceptionContext::describe($refused),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{minor: int, date: string}>  $balanceConfirmations
     */
    private function anchorConfirmedBalances(AccountWriter $accounts, int $userId, array $balanceConfirmations): void
    {
        foreach ($balanceConfirmations as $accountId => $confirmation) {
            $accounts->write($userId, $accountId, [
                'starting_balance_minor' => $confirmation['minor'],
                'starting_balance_date' => $confirmation['date'],
            ]);
        }
    }

    private function persistCommit(DatabaseManager $db, User $user, string $now): void
    {
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
                ...SafeExceptionContext::describe($dispatchException),
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
        BaseCurrency $baseCurrency,
        CsvPresetRegistry $csvPresets,
    ): View {
        $user = $currentUser->user();

        $this->stashedImportRunIds = $this->resolveStashedImportRunIds($user->id, $db);
        $this->preview = $buildPreview->build(
            $this->stashedImportRunIds,
            $user,
            sectionLimitOverrides: $this->expandedRowCount,
        );

        // Two candidates for one account is the conflict case; the blade groups
        // this flat list by accountId to spot it.
        $this->startingBalances = $detectBalances->collect($this->stashedImportRunIds, $user);

        $this->accountMeta = $this->loadAccountMeta($user->id, $db, $this->startingBalances, $baseCurrency);

        return $views->make('onboarding::livewire.steps.first-import-step', [
            'preview' => $this->preview,
            'startingBalances' => $this->startingBalances,
            'accountMeta' => $this->accountMeta,
            'sourceFormatLabels' => $this->sourceFormatLabels($csvPresets),
        ]);
    }

    // Names the file type, never the institution: a starting-balance conflict
    // asks which SOURCE a figure came from, and the reader picked a format. A
    // CSV layout lends its own registered label, which is the one place a
    // bank's name is data rather than a literal written into this app.
    /**
     * @return array<string, string> source format id => the label the reader sees
     */
    private function sourceFormatLabels(CsvPresetRegistry $csvPresets): array
    {
        $labels = [
            SourceFormat::Camt053->value => 'CAMT.053',
            SourceFormat::Mt940->value => 'MT940',
            SourceFormat::IcsPdf->value => 'PDF',
            SourceFormat::PaypalCsv->value => 'CSV',
        ];

        foreach ($csvPresets->allLayouts() as $preset) {
            $labels[$preset->format] = $preset->label.' CSV';
        }

        return $labels;
    }

    // Raw table(), not WizardProgress::query(): BelongsToUser's global scope falls
    // through outside an HTTP request, so the explicit user_id filter is the real one.
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

        // array_unique keeps first-occurrence order, which the orderByRaw fixed
        // as bank → PayPal → ICS card → email.
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
    private function loadAccountMeta(int $userId, DatabaseManager $db, array $candidates, BaseCurrency $baseCurrency): array
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
            $currency = is_string($row->default_currency) ? $row->default_currency : $baseCurrency->code();
            $kind = is_string($row->kind) ? $row->kind : '';

            $kindLabel = match ($kind) {
                AccountKind::IcsCard->value => 'ICS',
                AccountKind::Paypal->value => 'PAYPAL',
                default => strtoupper($kind),
            };

            // The tail is the last four of an IBAN, so an account standing in
            // for one has none to give and the kind alone is the whole badge.
            // Taken anyway, a wallet's read "PAYPAL · YPAL".
            $meta[$id] = [
                'label' => $name,
                'short' => Iban::isIban($iban)
                    ? sprintf('%s · %s', $kindLabel, substr($iban, -4))
                    : $kindLabel,
                'currency' => $currency,
            ];
        }

        return $meta;
    }
}
