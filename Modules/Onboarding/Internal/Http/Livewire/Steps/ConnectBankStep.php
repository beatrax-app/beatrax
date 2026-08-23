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
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectBankStep extends Component
{
    use WithFileUploads;

    // Every value here is an adapter key the ingestion registry binds, so the
    // format the user picks is the format that reaches the pipeline. Only the
    // CSV variants need a follow-on bank pick; CAMT.053 and MT940 are
    // self-describing.
    /** @var list<string> */
    private const array SUPPORTED_FORMATS = [
        SourceFormat::Camt053->value,
        SourceFormat::Mt940->value,
        SourceFormat::AsnCsv->value,
        CsvPresetRegistry::ING_NL,
    ];

    /** @var list<string> */
    private const array CSV_BANK_FORMATS = [SourceFormat::AsnCsv->value, CsvPresetRegistry::ING_NL];

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    public string $selectedFormat = SourceFormat::Camt053->value;

    // The CSV chip has to land on some CSV format, so $selectedFormat alone
    // cannot say whether the user has named their bank or is looking at that
    // landing default. This carries only that, and never the bank's identity.
    public bool $csvBankPicked = false;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.UploadLimits::MAX_KB, 'extensions:csv,txt,xml,sta,mt940,940'],
            'selectedFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => Lang::get('onboarding::connect_bank.errors.file_required'),
            'file.max' => Lang::get('onboarding::connect_bank.errors.file_max'),
            'file.extensions' => Lang::get('onboarding::connect_bank.errors.file_extensions'),
        ];
    }

    public function setFormat(string $format): void
    {
        if (! in_array($format, self::SUPPORTED_FORMATS, strict: true)) {
            return;
        }

        $this->selectedFormat = $format;
        $this->csvBankPicked = false;
    }

    public function setCsvBank(string $format): void
    {
        if (! in_array($format, self::CSV_BANK_FORMATS, strict: true)) {
            return;
        }

        $this->selectedFormat = $format;
        $this->csvBankPicked = true;
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

        if ($this->file === null) {
            return;
        }

        // The chip row lands on a CSV format before the user has named a bank,
        // so submitting from that state would import the landing default under
        // the user's file.
        if ($this->isCsvFormat($this->selectedFormat) && ! $this->csvBankPicked) {
            $this->addError('csvBankPicked', Lang::get('onboarding::connect_bank.errors.pick_bank'));

            return;
        }

        $user = $currentUser->user();
        $originalFilename = UploadFilename::sanitise($this->file->getClientOriginalName(), UploadFilename::extensionFor($this->selectedFormat));

        $formatHint = $this->formatHint();

        $result = $this->runPreview($importer, $this->file->getRealPath(), $user, $originalFilename, $formatHint, $logger, $app);
        if ($result === null) {
            return;
        }

        if ($this->createMissingBankAccounts($result, $user, $db, $slugs, $baseCurrency) > 0) {
            $this->repreview($result->importRunId, $user, $importer, $formatHint, $logger, $app);
        }

        $this->stashRunId($user, $result->importRunId);

        $this->dispatch('wizard.step.completed');
    }

    private function runPreview(RunsImports $importer, string $tmp, User $user, string $originalFilename, ?BankCsvFormatHint $formatHint, LoggerInterface $logger, Application $app): ?ImportPreviewResult
    {
        try {
            return $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename, $formatHint);
        } catch (Throwable $e) {
            $logger->error('ConnectBankStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = Lang::get('onboarding::connect_bank.errors.unreadable');

            return null;
        }
    }

    // A preset names its own dialect in its format id; only the built-in ASN
    // CSV has to declare one separately.
    private function formatHint(): ?BankCsvFormatHint
    {
        return match ($this->selectedFormat) {
            SourceFormat::AsnCsv->value => BankCsvFormatHint::Asn,
            default => null,
        };
    }

    /**
     * @return int how many accounts the preview needed that did not exist yet
     */
    private function createMissingBankAccounts(ImportPreviewResult $result, User $user, DatabaseManager $db, AccountSlugResolver $slugs, BaseCurrency $baseCurrency): int
    {
        $bankLabel = $this->bankLabel();

        $created = 0;
        foreach ($result->accountsToName as $unknown) {
            if ($this->createBankAccount($unknown, $bankLabel, $user, $db, $slugs, $baseCurrency)) {
                $created++;
            }
        }

        return $created;
    }

    private function createBankAccount(UnknownIban $unknown, string $bankLabel, User $user, DatabaseManager $db, AccountSlugResolver $slugs, BaseCurrency $baseCurrency): bool
    {
        $exists = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $unknown->iban)
            ->exists();
        if ($exists) {
            return false;
        }

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $bankLabel,
            'slug' => $slugs->resolveUnique($user->id, $bankLabel),
            'kind' => AccountKind::Bank->value,
            'iban' => $unknown->iban,
            'default_currency' => $baseCurrency->code(),
        ]);

        return true;
    }

    // Also repopulates statement_summaries, which the first-import step's
    // starting-balance detector reads.
    private function repreview(int $importRunId, User $user, RunsImports $importer, ?BankCsvFormatHint $formatHint, LoggerInterface $logger, Application $app): void
    {
        /** @var ImportRun|null $run */
        $run = ImportRun::query()
            ->where('id', $importRunId)
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
                $formatHint,
            );
        } catch (Throwable $e) {
            $logger->warning('ConnectBankStep: re-preview after bank account creation failed.', [
                'import_run_id' => $importRunId,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
        }
    }

    private function stashRunId(User $user, int $importRunId): void
    {
        $progress = WizardProgress::query()
            ->where('user_id', $user->id)
            ->where('step_key', 'connect-bank')
            ->first();
        if ($progress === null) {
            return;
        }

        $data = $progress->data ?? [];
        $data['bank_import_run_id'] = $importRunId;
        $progress->update(['data' => $data]);
    }

    private function bankLabel(): string
    {
        // Concatenated into "We detected your {label} account started at",
        // so the label must never itself contain the word "account".
        return match ($this->selectedFormat) {
            SourceFormat::Camt053->value, SourceFormat::Mt940->value, SourceFormat::AsnCsv->value => 'ASN bank',
            CsvPresetRegistry::ING_NL => 'ING bank',
            default => AccountKind::Bank->value,
        };
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-bank-step');
    }

    private function isCsvFormat(string $format): bool
    {
        return in_array($format, self::CSV_BANK_FORMATS, strict: true);
    }
}
