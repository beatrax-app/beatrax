<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectCardStep extends Component
{
    use WithFileUploads;

    // Mirrors IcsPdfAdapter::ICS_OWN_IBAN; the arch test
    // ics_pdf_adapter_own_iban_matches_connect_card_step holds the two together.
    private const ICS_OWN_IBAN = 'ICS-CARD';

    private const ICS_ACCOUNT_NAME = 'ICS card';

    public string $selectedFormat = 'ics-pdf';

    /** @var array<int, TemporaryUploadedFile> */
    public array $statements = [];

    // Surfaced only when every queued file failed; a mixed result still
    // dispatches wizard.step.completed.
    public ?string $uploadError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'statements' => ['array', 'min:1'],
            'statements.*' => ['file', 'max:'.UploadLimits::MAX_KB, 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statements.required' => Lang::get('onboarding::connect_card.errors.required'),
            'statements.min' => Lang::get('onboarding::connect_card.errors.min'),
            'statements.*.required' => Lang::get('onboarding::connect_card.errors.each_required'),
            'statements.*.max' => Lang::get('onboarding::connect_card.errors.each_max'),
            'statements.*.extensions' => Lang::get('onboarding::connect_card.errors.each_extensions'),
        ];
    }

    public function removeStatement(int $index): void
    {
        if (! array_key_exists($index, $this->statements)) {
            return;
        }

        unset($this->statements[$index]);
        $this->statements = array_values($this->statements);
    }

    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
        DatabaseManager $db,
        AccountSlugResolver $slugs,
        BaseCurrency $baseCurrency,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->statements === []) {
            return;
        }

        $user = $currentUser->user();

        $preview = $this->runPreviews($importer, $user, $logger, $app);
        $newRunIds = $preview['ids'];

        if ($newRunIds === []) {
            $this->uploadError = Lang::get('onboarding::connect_card.errors.none_readable', [
                'detail' => $preview['firstError'] ?? Lang::get('onboarding::connect_card.errors.full_error_in_logs'),
            ]);

            return;
        }

        $this->ensureIcsAccount($newRunIds, $user, $db, $importer, $logger, $app, $slugs, $baseCurrency);
        $this->stashRunIds($user, $newRunIds);

        $this->dispatch('wizard.step.completed');
    }

    // A per-file failure is logged and skipped rather than raised: users
    // download a month per PDF, so one bad file must not waste the batch.
    /**
     * @return array{ids: list<int>, firstError: ?string}
     */
    private function runPreviews(RunsImports $importer, User $user, LoggerInterface $logger, Application $app): array
    {
        $ids = [];
        $firstError = null;
        foreach ($this->statements as $statement) {
            $originalFilename = UploadFilename::sanitise($statement->getClientOriginalName(), '.pdf');
            try {
                $ids[] = $importer->runFromUpload($statement->getRealPath(), $this->selectedFormat, $user, $originalFilename)->importRunId;
            } catch (Throwable $e) {
                $logger->error('ConnectCardStep: import preview failed.', [
                    'source_format' => $this->selectedFormat,
                    'filename' => $originalFilename,
                    ...SafeExceptionContext::describe($e),
                    'exception_trace' => SafeTrace::cap($e, $app->basePath()),
                ]);
                $firstError ??= Lang::get('onboarding::connect_card.errors.file_unreadable', ['filename' => $originalFilename]);
            }
        }

        return ['ids' => $ids, 'firstError' => $firstError];
    }

    // Raw query builder rather than Account::query()->exists(), which trips
    // PHPStan strict-rules staticMethod.dynamicCall.
    /**
     * @param  list<int>  $newRunIds
     */
    private function ensureIcsAccount(array $newRunIds, User $user, DatabaseManager $db, RunsImports $importer, LoggerInterface $logger, Application $app, AccountSlugResolver $slugs, BaseCurrency $baseCurrency): void
    {
        $hasIcsAccount = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', self::ICS_OWN_IBAN)
            ->exists();
        if ($hasIcsAccount) {
            return;
        }

        Account::query()->create([
            'user_id' => $user->id,
            'name' => self::ICS_ACCOUNT_NAME,
            'slug' => $slugs->resolveUnique($user->id, self::ICS_ACCOUNT_NAME),
            'kind' => AccountKind::IcsCard->value,
            'iban' => self::ICS_OWN_IBAN,
            'default_currency' => $baseCurrency->code(),
        ]);

        foreach ($newRunIds as $runId) {
            $this->repreview($runId, $user, $importer, $logger, $app);
        }
    }

    // Also repopulates statement_summaries, which the first-import step's
    // starting-balance detector reads.
    private function repreview(int $runId, User $user, RunsImports $importer, LoggerInterface $logger, Application $app): void
    {
        /** @var ImportRun|null $run */
        $run = ImportRun::query()
            ->where('id', $runId)
            ->where('user_id', $user->id)
            ->first();
        if ($run === null || ! file_exists($run->raw_file_path)) {
            return;
        }

        try {
            $importer->runFromUpload(
                $run->raw_file_path,
                $this->selectedFormat,
                $user,
                basename($run->raw_file_path),
            );
        } catch (Throwable $e) {
            $logger->warning('ConnectCardStep: re-preview after ICS account creation failed.', [
                'import_run_id' => $runId,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
        }
    }

    /**
     * @param  list<int>  $newRunIds
     */
    private function stashRunIds(User $user, array $newRunIds): void
    {
        $progress = WizardProgress::query()
            ->where('user_id', $user->id)
            ->where('step_key', 'connect-card')
            ->first();
        if ($progress === null) {
            return;
        }

        $data = $progress->data ?? [];
        $existingRaw = $data['card_import_run_ids'] ?? [];
        $existing = [];
        if (is_array($existingRaw)) {
            foreach ($existingRaw as $value) {
                if (is_int($value) && $value > 0) {
                    $existing[] = $value;
                }
            }
        }
        $merged = array_values(array_unique([...$existing, ...$newRunIds], SORT_NUMERIC));
        $data['card_import_run_ids'] = $merged;
        $progress->update(['data' => $data]);
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-card-step');
    }
}
