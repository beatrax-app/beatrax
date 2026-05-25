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
 * Wizard step 2 — connect the user's bank (ASN). Renders the connector
 * card with the four-tile mini-step row, the format-chip selector
 * (CAMT.053 recommended / CSV / MT940), and the drop-zone that wraps
 * the Livewire file input.
 *
 * Submission delegates to `Modules\Import\Public\Contracts\RunsImports`
 * — the same pipeline the standalone `/imports` UploadWizard uses. The
 * pipeline parses + fingerprints the file in memory, persists an
 * `ImportRun` row, and returns the preview id. On success this step
 * dispatches `wizard.step.completed` so the SetupWizard parent advances
 * the wizard_progress row to `done` without a page redirect (the
 * preview row stays available at /imports/{id}/preview for the user to
 * confirm later).
 *
 * Skipping marks the row `skipped` via `wizard.step.skipped`. Skip is
 * allowed because the bank connector is a "connector" step under
 * WizardStepRegistry::SKIPPABLE — the user can come back to import
 * later from Settings.
 *
 * Per the project DI-only rule, every collaborator is method-DI'd onto
 * `submit()` (Livewire forbids constructor DI on Component subclasses);
 * no `redirect()` / `now()` / `event()` global helpers are called.
 */
final class ConnectBankStep extends Component
{
    use WithFileUploads;

    /**
     * Allowed format leaves the user can announce having downloaded
     * from mijn.asnbank.nl. The recommended format is CAMT.053; CSV +
     * MT940 are equally accepted because some user exports default to
     * them. The validator pairs this with the extensions allow-list.
     *
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['asn-camt053', 'asn-csv', 'asn-mt940'];

    /**
     * The temporary upload reference Livewire stages on disk. Null
     * until the user picks a file via the drop-zone; the validator
     * required-rule guards `submit()` against null.
     */
    public ?TemporaryUploadedFile $file = null;

    /**
     * One-shot human-readable parse-time error surfaced inline below
     * the drop-zone when `RunsImports::runFromUpload()` throws. Cleared
     * on every fresh submit so a retry with a different file does not
     * keep the stale message.
     */
    public ?string $uploadError = null;

    /**
     * The leaf format key the wizard ships to the importer. Mounted on
     * the recommended CAMT.053 path; the format-chip row updates this
     * via `wire:click="setFormat('asn-csv')"` etc.
     */
    public string $selectedFormat = 'asn-camt053';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xml,sta,mt940,940'],
            'selectedFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Drop your ASN statement file into the box first.',
            'file.max' => 'That file is too large. Drop an ASN statement under 10 MB.',
            'file.extensions' => "That file doesn't look like an ASN statement. Drop a CAMT.053 XML, CSV, or MT940 file.",
        ];
    }

    /**
     * Switches the active format chip. Bound via `wire:click` from the
     * format-chip row; the allowed values are enforced by the in:
     * validator so a client-side property tamper still fails submit.
     */
    public function setFormat(string $format): void
    {
        if (in_array($format, self::SUPPORTED_FORMATS, strict: true)) {
            $this->selectedFormat = $format;
        }
    }

    /**
     * Runs the ASN statement through the shared import preview pipeline.
     * Mirrors UploadWizard::submit() — same validator shape, same
     * Throwable catch-all, same logger payload — but on success
     * dispatches `wizard.step.completed` so the SetupWizard parent
     * advances the wizard state. The preview row itself remains
     * available for the user to confirm via /imports/{id}/preview at
     * their leisure.
     */
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
            $logger->error('ConnectBankStep: import preview failed.', [
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

    /**
     * Marks this step `skipped` at the parent. The user can still come
     * back to /setup-wizard and pick up the bank connector — the
     * wizard_progress row stays in place; only its status changes.
     */
    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-bank-step');
    }

    /**
     * Strips path-traversal characters out of the user-supplied filename
     * before it touches any filesystem path. Mirrors UploadWizard's
     * sanitiseFilename verbatim so the connector step's stable-storage
     * filenames look like the standalone wizard's.
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        $extension = match ($this->selectedFormat) {
            'asn-camt053' => '.xml',
            'asn-mt940' => '.sta',
            default => '.csv',
        };

        return $stemPart.$extension;
    }
}
