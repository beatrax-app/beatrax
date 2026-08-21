<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
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

        // Before the preview, never after: the pipeline tags every row
        // 'error' while AccountResolver still returns UnknownAccount, and
        // that all-error preview is what gets cached.
        ($ensurePaypalAccount)($user);

        $tmp = $this->activityCsv->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->activityCsv->getClientOriginalName());

        try {
            $result = $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('ConnectPaypalStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = Lang::get('onboarding::connect_paypal.errors.unreadable');

            return;
        }

        $fatalParseMessage = $this->fatalParseMessage($result);
        if ($fatalParseMessage !== null) {
            $logger->warning('ConnectPaypalStep: upload produced only error rows.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                'import_run_id' => $result->importRunId,
                'error_message' => $fatalParseMessage,
            ]);
            $this->uploadError = $fatalParseMessage;

            return;
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

    private function fatalParseMessage(ImportPreviewResult $result): ?string
    {
        if ($result->rows === [] || $result->accountsToName !== []) {
            return null;
        }

        $firstErrorMessage = null;
        foreach ($result->rows as $row) {
            if ($row->status !== 'error') {
                return null;
            }
            if ($firstErrorMessage === null && $row->error !== null && $row->error !== '') {
                $firstErrorMessage = $row->error;
            }
        }

        return $firstErrorMessage ?? Lang::get('onboarding::connect_paypal.errors.unreadable');
    }

    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.csv';
    }
}
