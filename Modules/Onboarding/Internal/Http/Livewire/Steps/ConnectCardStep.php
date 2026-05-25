<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Contracts\RunsImports;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 3 — connect the user's ICS credit card. ICS Cards's
 * consumer portal (Mijn ICS) only exports monthly PDF statements; there
 * is no CSV export. The step therefore renders a single non-interactive
 * "PDF [only format]" chip and locks the file validator to the .pdf
 * extension. Submission delegates to `RunsImports::runFromUpload()` with
 * the leaf format key `ics-pdf` — the same path `Modules/Import`'s
 * IcsPdfAdapter consumes.
 *
 * On success this step dispatches `wizard.step.completed` so the
 * SetupWizard parent advances. Skip is allowed — the user can come back
 * later from Settings to import a card statement.
 *
 * Per the project DI-only rule, every collaborator is method-DI'd on
 * `submit()`; no global helpers are called.
 */
final class ConnectCardStep extends Component
{
    use WithFileUploads;

    /**
     * The single leaf format the ICS step ships. ICS Cards exports
     * PDF only; the property exists to keep the connector-step
     * structure mirror-able to ConnectBankStep, not to offer choice.
     */
    public string $selectedFormat = 'ics-pdf';

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Drop the monthly PDF statement you downloaded from Mijn ICS.',
            'file.max' => 'That file is too large. ICS PDF statements are normally under 1 MB.',
            'file.extensions' => "That file doesn't look like an ICS PDF statement. Mijn ICS only exports PDF — try the latest monthly statement.",
        ];
    }

    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

        try {
            $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('ConnectCardStep: import preview failed.', [
                'source_format' => $this->selectedFormat,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->uploadError = sprintf(
                'Could not read this file (%s). The full error is in /dev/logs.',
                $e::class,
            );

            return;
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-card-step');
    }

    /**
     * Strips path-traversal characters and locks the extension to .pdf
     * (the only format the ICS step accepts).
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.pdf';
    }
}
