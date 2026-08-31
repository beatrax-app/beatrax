<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectPaypalStep extends Component
{
    use WithFileUploads;

    public string $selectedFormat = 'paypal-csv';

    public ?TemporaryUploadedFile $activityCsv = null;

    public ?string $uploadError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'activityCsv' => ['required', 'file', 'max:'.UploadLimits::MAX_KB, 'extensions:csv,txt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activityCsv.required' => Lang::get('onboarding::connect_paypal.errors.required'),
            'activityCsv.max' => Lang::get('onboarding::connect_paypal.errors.max'),
            'activityCsv.extensions' => Lang::get('onboarding::connect_paypal.errors.extensions'),
        ];
    }

    public function submit(
        RunsImports $importer,
        EnsurePaypalAccountAction $ensurePaypalAccount,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->activityCsv === null) {
            return;
        }

        $user = $currentUser->user();

        $tmp = $this->activityCsv->getRealPath();
        $originalFilename = UploadFilename::sanitise($this->activityCsv->getClientOriginalName(), '.csv');

        $result = $this->runPreview($importer, $tmp, $user, $originalFilename, $logger, $app);
        if ($result === null) {
            return;
        }

        // A file nothing could read must not leave a durable wallet behind:
        // the account outlives the import, and nothing in the app deletes one.
        if ($this->refuses($result, $originalFilename, $logger)) {
            return;
        }

        // After the preview, because only the export says which currency the
        // wallet holds; opened before it, a euro balance was labelled in yen.
        // The rows the first pass could not resolve are read again once the
        // account exists, so the cache is never the all-error one.
        if (($ensurePaypalAccount)($user, statementCurrency: self::walletCurrencyIn($result))) {
            $result = $this->runPreview($importer, $tmp, $user, $originalFilename, $logger, $app) ?? $result;

            if ($this->refuses($result, $originalFilename, $logger)) {
                return;
            }
        }

        $progress = WizardProgress::query()
            ->where('user_id', $user->id)
            ->where('step_key', 'connect-paypal')
            ->first();

        if ($progress !== null) {
            $data = $progress->data ?? [];
            $data['paypal_import_run_id'] = $result->importRunId;
            $progress->update(['data' => $data]);
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-paypal-step');
    }

    private function runPreview(RunsImports $importer, string $tmp, User $user, string $originalFilename, LoggerInterface $logger, Application $app): ?ImportPreviewResult
    {
        try {
            return $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('ConnectPaypalStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = Lang::get('onboarding::connect_paypal.errors.unreadable');

            return null;
        }
    }

    private function refuses(ImportPreviewResult $result, string $originalFilename, LoggerInterface $logger): bool
    {
        $fatalParseMessage = $this->fatalParseMessage($result);
        if ($fatalParseMessage === null) {
            return false;
        }

        $logger->warning('ConnectPaypalStep: upload produced only error rows.', [
            'source_format' => $this->selectedFormat,
            'filename' => $originalFilename,
            'import_run_id' => $result->importRunId,
            'error_message' => $fatalParseMessage,
        ]);
        $this->uploadError = $fatalParseMessage;

        return true;
    }

    private static function walletCurrencyIn(ImportPreviewResult $result): ?string
    {
        foreach ($result->accountsToName as $unknown) {
            if ($unknown->iban === EnsurePaypalAccountAction::PAYPAL_OWN_IBAN) {
                return $unknown->statementCurrency;
            }
        }

        return null;
    }

    private function fatalParseMessage(ImportPreviewResult $result): ?string
    {
        // A file the parser cannot read at all comes back as a file-level
        // failure carrying no rows, so the all-error-rows walk never sees it.
        // Its detail is the sentence naming the export to fetch.
        if ($result->fileFailureReason !== null) {
            return $result->fileFailureDetail ?? $result->fileFailureReason->label();
        }

        return $this->everyRowFailedMessage($result);
    }

    // A file that parsed but yielded nothing importable, as distinct from one
    // with a bad row in it. An unknown account to name is not that case: those
    // rows are errors only until the account exists.
    private function everyRowFailedMessage(ImportPreviewResult $result): ?string
    {
        if ($result->rows === [] || $result->accountsToName !== []) {
            return null;
        }

        $firstErrorMessage = null;
        foreach ($result->rows as $row) {
            if ($row->status !== PreviewRowStatus::Error) {
                return null;
            }
            if ($firstErrorMessage === null && $row->error !== null && $row->error !== '') {
                $firstErrorMessage = $row->error;
            }
        }

        return $firstErrorMessage ?? Lang::get('onboarding::connect_paypal.errors.unreadable');
    }
}
