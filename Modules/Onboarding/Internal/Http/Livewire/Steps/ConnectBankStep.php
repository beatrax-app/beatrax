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
use Modules\Import\Public\Services\AccountDenomination;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectBankStep extends Component
{
    use WithFileUploads;

    // The two formats that describe themselves. Every other format this step
    // accepts is a CSV layout read from the preset registry, not written out
    // here: the written-out list went stale the moment a preset was added, and
    // offered two Dutch banks while the app could already read four layouts.
    /** @var list<string> */
    private const array STATEMENT_FORMATS = [
        SourceFormat::Camt053->value,
        SourceFormat::Mt940->value,
    ];

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    public string $selectedFormat = SourceFormat::Camt053->value;

    // The CSV chip has to land on some CSV layout, so $selectedFormat alone
    // cannot say whether the reader has named a layout or is looking at that
    // landing default. This carries only that, and never the layout itself.
    public bool $csvLayoutPicked = false;

    private CsvPresetRegistry $csvPresets;

    public function boot(CsvPresetRegistry $csvPresets): void
    {
        $this->csvPresets = $csvPresets;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.UploadLimits::MAX_KB, 'extensions:csv,txt,xml,sta,mt940,940'],
            'selectedFormat' => ['required', 'in:'.implode(',', $this->supportedFormats())],
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
        if (! in_array($format, $this->supportedFormats(), strict: true)) {
            return;
        }

        $this->selectedFormat = $format;
        $this->csvLayoutPicked = false;
    }

    public function setCsvLayout(string $format): void
    {
        if (! $this->isCsvFormat($format)) {
            return;
        }

        $this->selectedFormat = $format;
        $this->csvLayoutPicked = true;
    }

    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
        DatabaseManager $db,
        AccountSlugResolver $slugs,
        AccountDenomination $denomination,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        // The chip row lands on a CSV layout before the reader has named one,
        // so submitting from that state would import the landing default under
        // the reader's file.
        if ($this->isCsvFormat($this->selectedFormat) && ! $this->csvLayoutPicked) {
            $this->addError('csvLayoutPicked', Lang::get('onboarding::connect_bank.errors.pick_bank'));

            return;
        }

        $user = $currentUser->user();
        $originalFilename = UploadFilename::sanitise($this->file->getClientOriginalName(), UploadFilename::extensionFor($this->selectedFormat));

        $formatHint = $this->formatHint();

        $result = $this->runPreview($importer, $this->file->getRealPath(), $user, $originalFilename, $formatHint, $logger, $app);
        if ($result === null) {
            return;
        }

        if ($this->createMissingBankAccounts($result, $user, $db, $slugs, $denomination) > 0) {
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
            CsvPresetRegistry::ASN => BankCsvFormatHint::Asn,
            default => null,
        };
    }

    /**
     * @return int how many accounts the preview needed that did not exist yet
     */
    private function createMissingBankAccounts(ImportPreviewResult $result, User $user, DatabaseManager $db, AccountSlugResolver $slugs, AccountDenomination $denomination): int
    {
        $accountName = $this->accountName();

        $created = 0;
        foreach ($result->accountsToName as $unknown) {
            if ($this->createBankAccount($unknown, $accountName, $user, $db, $slugs, $denomination)) {
                $created++;
            }
        }

        return $created;
    }

    private function createBankAccount(UnknownIban $unknown, string $accountName, User $user, DatabaseManager $db, AccountSlugResolver $slugs, AccountDenomination $denomination): bool
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
            'name' => $accountName,
            'slug' => $slugs->resolveUnique($user->id, $accountName),
            'kind' => AccountKind::Bank->value,
            'iban' => $unknown->iban,
            'default_currency' => $denomination->forStatement($unknown->statementCurrency),
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

    // Becomes accounts.name, which the starting-balance card reads back as
    // "We detected your {name} started at". CAMT.053 and MT940 carry no issuer,
    // so a reader who picked one of them named a file type and not a bank, and
    // the account may not claim otherwise.
    private function accountName(): string
    {
        foreach ($this->csvLayouts() as $layout) {
            if ($layout['format'] === $this->selectedFormat) {
                return Lang::get('onboarding::connect_bank.account_name_layout', ['layout' => $layout['label']]);
            }
        }

        return Lang::get('onboarding::connect_bank.account_name_default');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-bank-step', [
            'csvLayouts' => $this->csvLayouts(),
        ]);
    }

    private function isCsvFormat(string $format): bool
    {
        return in_array($format, array_column($this->csvLayouts(), 'format'), strict: true);
    }

    // Every layout the registry holds, which is every bank CSV an adapter is
    // bound for: the registry carries only bank presets, so nothing here has to
    // exclude PayPal's own CSV. Adding a preset offers it in the wizard.
    /**
     * @return list<array{format: string, label: string}>
     */
    private function csvLayouts(): array
    {
        $layouts = [];
        foreach ($this->csvPresets->allLayouts() as $preset) {
            $layouts[] = ['format' => $preset->format, 'label' => $preset->label];
        }

        usort($layouts, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $layouts;
    }

    /**
     * @return list<string> every format id this step may submit
     */
    private function supportedFormats(): array
    {
        return [...self::STATEMENT_FORMATS, ...array_column($this->csvLayouts(), 'format')];
    }
}
