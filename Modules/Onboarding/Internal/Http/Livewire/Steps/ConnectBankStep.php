<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
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
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectBankStep extends Component
{
    use WithFileUploads;

    // Only the CSV variants need a follow-on bank pick; CAMT.053 and MT940
    // are self-describing.
    /** @var list<string> */
    private const SUPPORTED_FORMATS = ['camt053', 'mt940', 'asn-csv', 'ing-csv'];

    /** @var list<string> */
    private const SUPPORTED_BANK_FORMAT_HINTS = ['asn-csv', 'ing-csv'];

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    public string $selectedFormat = 'camt053';

    public ?string $selectedBankFormatHint = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.UploadLimits::MAX_KB, 'extensions:csv,txt,xml,sta,mt940,940'],
            'selectedFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS)],
            'selectedBankFormatHint' => ['nullable', 'in:'.implode(',', self::SUPPORTED_BANK_FORMAT_HINTS)],
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

        if (! $this->isCsvFormat($format)) {
            $this->selectedBankFormatHint = null;
        }
    }

    public function isDropZoneGated(): bool
    {
        return $this->isCsvFormat($this->selectedFormat) && $this->selectedBankFormatHint === null;
    }

    // Mirrors UploadWizard::submit(); the two must not drift apart.
    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
        DatabaseManager $db,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        // The validator's in: rule still admits a (CSV, null) pair, so a
        // tampered request is caught here rather than inside the pipeline.
        if ($this->isCsvFormat($this->selectedFormat) && $this->selectedBankFormatHint === null) {
            $this->addError('selectedBankFormatHint', Lang::get('onboarding::connect_bank.errors.pick_bank'));

            return;
        }

        $user = $currentUser->user();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

        $formatHint = $this->selectedBankFormatHint === null
            ? null
            : BankCsvFormatHint::from($this->selectedBankFormatHint);

        $result = $this->runPreview($importer, $this->file->getRealPath(), $user, $originalFilename, $formatHint, $logger, $app);
        if ($result === null) {
            return;
        }

        if ($result->accountsToName !== []) {
            $this->ensureBankAccounts($result, $user, $db, $importer, $formatHint, $logger, $app);
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

    private function ensureBankAccounts(ImportPreviewResult $result, User $user, DatabaseManager $db, RunsImports $importer, ?BankCsvFormatHint $formatHint, LoggerInterface $logger, Application $app): void
    {
        $bankLabel = $this->bankLabelFor($this->selectedFormat, $this->selectedBankFormatHint);

        $created = 0;
        foreach ($result->accountsToName as $unknown) {
            if ($this->createBankAccount($unknown, $bankLabel, $user, $db)) {
                $created++;
            }
        }

        if ($created > 0) {
            $this->repreview($result->importRunId, $user, $importer, $formatHint, $logger, $app);
        }
    }

    private function createBankAccount(UnknownIban $unknown, string $bankLabel, User $user, DatabaseManager $db): bool
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
            'slug' => $this->slugFor($bankLabel, $unknown->iban),
            'kind' => AccountKind::Bank->value,
            'iban' => $unknown->iban,
            'default_currency' => 'EUR',
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

    private function bankLabelFor(string $sourceFormat, ?string $bankFormatHint): string
    {
        // Concatenated into "We detected your {label} account started at",
        // so the label must never itself contain the word "account".
        return match ($sourceFormat) {
            'camt053', 'mt940', 'asn-csv' => 'ASN bank',
            'ing-csv' => 'ING bank',
            default => match ($bankFormatHint) {
                'asn-csv' => 'ASN bank',
                'ing-csv' => 'ING bank',
                default => AccountKind::Bank->value,
            },
        };
    }

    // The IBAN tail keeps two bank accounts under one user off the same
    // unique(user_id, slug) row.
    private function slugFor(string $label, string $iban): string
    {
        $tail = strtolower(substr($iban, -6));

        return Str::slug($label).'-'.$tail;
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-bank-step');
    }

    // Path-traversal characters go before the name reaches a filesystem path; the
    // extension follows the declared format so the stored copy still sniffs
    // correctly when repreview() re-reads it.
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        $extension = match ($this->selectedFormat) {
            'camt053' => '.xml',
            'mt940' => '.sta',
            default => '.csv',
        };

        return $stemPart.$extension;
    }

    private function isCsvFormat(string $format): bool
    {
        return in_array($format, self::SUPPORTED_BANK_FORMAT_HINTS, strict: true);
    }
}
