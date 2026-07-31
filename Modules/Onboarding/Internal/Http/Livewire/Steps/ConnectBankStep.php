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
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class ConnectBankStep extends Component
{
    use WithFileUploads;

    // Only the CSV variants need a follow-on bank-format pick; CAMT.053
    // and MT940 are self-describing.
    /** @var list<string> */
    private const SUPPORTED_FORMATS = ['camt053', 'mt940', 'asn-csv', 'ing-csv'];

    /** @var list<string> */
    private const SUPPORTED_BANK_FORMAT_HINTS = ['asn-csv', 'ing-csv'];

    public ?TemporaryUploadedFile $file = null;

    // Cleared on every fresh submit so a retry does not keep a stale
    // message.
    public ?string $uploadError = null;

    public string $selectedFormat = 'camt053';

    // Stays null until the user picks a CSV bank; submit() refuses to
    // proceed while null and selectedFormat is a CSV variant.
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
            'file.required' => 'Drop your statement file into the box first.',
            'file.max' => 'That file is too large. Drop a statement under 10 MB.',
            'file.extensions' => "That file doesn't look like a bank statement. Drop a CAMT.053 XML, CSV, or MT940 file.",
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

    // True only on the CSV branch with no bank-format pick yet — the UX
    // surface of the server-side guard below.
    public function isDropZoneGated(): bool
    {
        return $this->isCsvFormat($this->selectedFormat) && $this->selectedBankFormatHint === null;
    }

    // Mirrors UploadWizard::submit() (validator shape, Throwable
    // catch-all, logger payload); on success stashes the ImportRun id
    // into wizard_progress.data and dispatches wizard.step.completed.
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

        // Load-bearing check: the validator's in: rule alone can permit a
        // (CSV, null) combination, so this catches a tampered request
        // before any pipeline call.
        if ($this->isCsvFormat($this->selectedFormat) && $this->selectedBankFormatHint === null) {
            $this->addError('selectedBankFormatHint', 'Pick which bank exported your CSV before continuing.');

            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

        $formatHint = $this->selectedBankFormatHint === null
            ? null
            : BankCsvFormatHint::from($this->selectedBankFormatHint);

        try {
            $result = $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename, $formatHint);
        } catch (Throwable $e) {
            $logger->error('ConnectBankStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = 'Could not read this file. The full error is in /dev/logs.';

            return;
        }

        // Auto-creates accounts for any unknown IBAN and re-previews —
        // see architecture.md for why this is necessary.
        if ($result->accountsToName !== []) {
            $bankLabel = $this->bankLabelFor($this->selectedFormat, $this->selectedBankFormatHint);

            $created = 0;
            foreach ($result->accountsToName as $unknown) {
                $exists = $db->connection()
                    ->table('accounts')
                    ->where('user_id', $user->id)
                    ->where('iban', $unknown->iban)
                    ->exists();
                if ($exists) {
                    continue;
                }
                Account::query()->create([
                    'user_id' => $user->id,
                    'name' => $bankLabel,
                    'slug' => $this->slugFor($bankLabel, $unknown->iban),
                    'kind' => 'bank',
                    'iban' => $unknown->iban,
                    'default_currency' => 'EUR',
                ]);
                $created++;
            }

            if ($created > 0) {
                // Re-preview the run from its stable stored path so the
                // preview cache reflects the now-resolvable account and
                // statement_summaries is populated for the starting-balance
                // detector on step 5.
                /** @var ImportRun|null $run */
                $run = ImportRun::query()
                    ->where('id', $result->importRunId)
                    ->where('user_id', $user->id)
                    ->first();
                if ($run !== null && file_exists($run->raw_file_path)) {
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
                            'import_run_id' => $result->importRunId,
                            'exception_class' => $e::class,
                            'exception_message' => $e->getMessage(),
                            'exception_trace' => SafeTrace::cap($e, $app->basePath()),
                        ]);
                    }
                }
            }
        }

        $progress = WizardProgress::query()
            ->where('user_id', $user->id)
            ->where('step_key', 'connect-bank')
            ->first();

        if ($progress !== null) {
            $data = $progress->data ?? [];
            $data['bank_import_run_id'] = $result->importRunId;
            $progress->update(['data' => $data]);
        }

        $this->dispatch('wizard.step.completed');
    }

    private function bankLabelFor(string $sourceFormat, ?string $bankFormatHint): string
    {
        // Concatenated later into "We detected your {label} account
        // started at" — keep "bank"/"ASN"/"ING" only, never the literal
        // word "account", to avoid duplication.
        return match ($sourceFormat) {
            'camt053', 'mt940', 'asn-csv' => 'ASN bank',
            'ing-csv' => 'ING bank',
            default => match ($bankFormatHint) {
                'asn-csv' => 'ASN bank',
                'ing-csv' => 'ING bank',
                default => 'bank',
            },
        };
    }

    // Adding the IBAN tail keeps multiple bank accounts under one user
    // from colliding on the unique(user_id, slug) constraint.
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

    // Strips path-traversal characters before the filename touches any
    // filesystem path; the extension follows the declared source format
    // so the stored copy round-trips through the format-specific
    // HeaderSniffer on re-read.
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
