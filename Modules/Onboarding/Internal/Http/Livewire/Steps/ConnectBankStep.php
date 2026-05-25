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
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 2 — connect the user's bank statement.
 *
 * Renders three format chips (CAMT.053 recommended, MT940, CSV) as a
 * single radio group above a drop zone. CAMT.053 and MT940 are
 * self-describing — picking either one keeps the drop zone interactive.
 * CSV is ambiguous (every bank exports its own dialect), so picking
 * the CSV chip reveals a follow-on chip row {ASN, ING} and disables the
 * drop zone until the user names the bank. The disabled state is the
 * user-visible surface of the server-side validation guard that
 * refuses a CSV submit without an accompanying bank-format hint.
 *
 * Submission delegates to `Modules\Import\Public\Contracts\RunsImports`
 * — the same pipeline the standalone `/imports` UploadWizard uses. The
 * CSV branch carries the picked bank-format hint through the
 * `runFromUpload` 5th parameter so the pipeline dispatches the right
 * adapter without re-sniffing the file. On success this step stashes
 * the returned ImportRun id into `wizard_progress.data['bank_import_run_id']`
 * so the consolidated preview screen can read it back, then dispatches
 * `wizard.step.completed` so the SetupWizard parent advances.
 *
 * Skipping marks the row `skipped` via `wizard.step.skipped`. The user
 * can come back to import later from Settings.
 *
 * Per the project DI-only rule, every collaborator is method-DI'd onto
 * `submit()` (Livewire forbids constructor DI on Component subclasses);
 * no `redirect()` / `now()` / `event()` global helpers are called.
 */
final class ConnectBankStep extends Component
{
    use WithFileUploads;

    /**
     * The format leaves the wizard ships to the importer. The CSV
     * branch is the only one that needs a follow-on bank-format pick
     * — the other two formats are self-describing.
     *
     * @var list<string>
     */
    private const SUPPORTED_FORMATS = ['asn-camt053', 'asn-mt940', 'asn-csv', 'ing-csv'];

    /**
     * Allowed values for the follow-on bank-format chip row that only
     * renders when the user picks the CSV format chip.
     *
     * @var list<string>
     */
    private const SUPPORTED_BANK_FORMAT_HINTS = ['asn-csv', 'ing-csv'];

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
     * CAMT.053 — the recommended default — and updated by the chip-row
     * `wire:click` handlers. The validator's `in:` rule guards against
     * a property-tampered client value.
     */
    public string $selectedFormat = 'asn-camt053';

    /**
     * Bank-format pick the follow-on row stores when the user is on
     * the CSV branch. Stays null until the user picks one of
     * {ASN, ING}; submit() refuses to proceed while it is null AND
     * `selectedFormat` is a CSV variant.
     */
    public ?string $selectedBankFormatHint = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt,xml,sta,mt940,940'],
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

    /**
     * Server-side handler invoked by the format-chip row. Clears the
     * follow-on bank-format pick whenever the user switches away from
     * a CSV format so the gated drop zone state resets cleanly on a
     * back-and-forth chip selection.
     */
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

    /**
     * Whether the drop zone should be marked `aria-disabled="true"`.
     * True only on the CSV branch with no bank-format pick yet — the
     * UX surface of the server-side guard that refuses a CSV submit
     * without a bank-format hint.
     */
    public function isDropZoneGated(): bool
    {
        return $this->isCsvFormat($this->selectedFormat) && $this->selectedBankFormatHint === null;
    }

    /**
     * Runs the statement through the shared import preview pipeline.
     * Mirrors UploadWizard::submit() — same validator shape, same
     * Throwable catch-all, same logger payload — but on success stashes
     * the resulting ImportRun id into `wizard_progress.data` for the
     * consolidated preview screen and dispatches
     * `wizard.step.completed` so the SetupWizard parent advances the
     * wizard state.
     *
     * The CSV branch carries the follow-on bank-format pick into the
     * importer; the pipeline rejects a CSV import without one at the
     * public-contract boundary as a backstop guard. Picking a CSV
     * format without naming the bank is also rejected by this method's
     * server-side validator before any pipeline call.
     */
    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        // Server-side guard: CSV imports must be accompanied by a
        // bank-format pick. The validator's `in:` rule on
        // `selectedFormat` and `selectedBankFormatHint` can permit a
        // (CSV, null) combination on its own — this is the load-bearing
        // check that catches a tampered request before any pipeline
        // call.
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
            $this->uploadError = sprintf(
                'Could not read this file (%s). The full error is in /dev/logs.',
                $e::class,
            );

            return;
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
     * before it touches any filesystem path. The extension follows the
     * declared source format so the stored copy round-trips through the
     * format-specific HeaderSniffer on re-read.
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

    /**
     * Whether the given format key is one of the ambiguous CSV
     * dialects that needs a follow-on bank-format pick before the
     * drop zone unlocks.
     */
    private function isCsvFormat(string $format): bool
    {
        return in_array($format, self::SUPPORTED_BANK_FORMAT_HINTS, strict: true);
    }
}
