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
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class ConnectPaypalStep extends Component
{
    use WithFileUploads;

    public string $selectedFormat = 'paypal-csv';

    public ?TemporaryUploadedFile $activityCsv = null;

    // Cleared on every fresh submit so a retry does not keep the stale
    // message.
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
            'activityCsv.required' => 'Drop your PayPal Rapport Transactiegegevens CSV into the box first.',
            'activityCsv.max' => 'That file is too large. PayPal Rapport Transactiegegevens exports are normally well under 10 MB.',
            'activityCsv.extensions' => "That file doesn't look like a PayPal CSV. Download Rapport Transactiegegevens (not the Saldorapport balance report) as CSV from PayPal.",
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

        // Account-before-preview ordering is load-bearing here — see
        // architecture.md for why inverting it would poison the preview.
        ($ensurePaypalAccount)($user);

        $tmp = $this->activityCsv->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->activityCsv->getClientOriginalName());

        try {
            $result = $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('ConnectPaypalStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = 'Could not read this file. The full error is in /dev/logs.';

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

    // Returns the first error-row message only when EVERY row is an error
    // AND there are no unknown-IBAN naming prompts — see architecture.md
    // for why this gate exists and why a mixed error/committable preview
    // does not trip it.
    private function fatalParseMessage(ImportPreviewResult $result): ?string
    {
        if ($result->rows === []) {
            return null;
        }
        if ($result->accountsToName !== []) {
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

        return $firstErrorMessage ?? 'Could not read this file. The full error is in /dev/logs.';
    }

    // Strips path-traversal characters and locks the extension to .csv,
    // the only format the PayPal Activity export ships in.
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.csv';
    }
}
