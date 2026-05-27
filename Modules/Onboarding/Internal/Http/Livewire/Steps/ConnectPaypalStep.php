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
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 3 — collects a single PayPal Activity Download CSV,
 * previews it through the shared `RunsImports` pipeline, idempotently
 * creates the synthetic PayPal `accounts` row via the shared
 * `EnsurePaypalAccountAction`, and stashes the resulting ImportRun id
 * under `wizard_progress.data['paypal_import_run_id']` so the
 * consolidated first-import preview screen can render a PayPal section
 * between the bank and ICS card sections.
 *
 * PayPal Activity Downloads cover an arbitrary user-chosen date range,
 * so the step accepts a single CSV (the standalone `/imports/upload`
 * flow remains the path for additional CSVs later). The leaf format
 * key `paypal-csv` matches `PaypalCsvPaymentTypeHinter`,
 * `PaypalCsvStartingBalanceDetector`, and `ClassifyTransactionType`'s
 * format branch.
 *
 * The first successful preview triggers an automatic re-preview from
 * the stable stored path so the preview cache and `statement_summaries`
 * writer reflect the now-resolvable synthetic account. Subsequent
 * submits skip the re-preview because the action reports no INSERT.
 *
 * A parse-time failure surfaces via the inline `$uploadError` band —
 * the same shape `ConnectCardStep` uses for per-file failures. The
 * `UnsupportedPaypalCsvShapeException` raised when the user drops a
 * Balance Reconciliation Report instead of an Activity Download
 * bubbles through the same Throwable catch.
 *
 * Per the project DI-only rule, every collaborator is method-DI'd onto
 * `submit()` — Livewire forbids constructor DI on Component subclasses.
 */
final class ConnectPaypalStep extends Component
{
    use WithFileUploads;

    /**
     * Locked PayPal leaf format key (matches `PaypalCsvPaymentTypeHinter`,
     * `PaypalCsvStartingBalanceDetector`, and the format branch in
     * `ClassifyTransactionType`).
     */
    public string $selectedFormat = 'paypal-csv';

    /**
     * The single PayPal Activity Download CSV the user dropped on the
     * step. Bound to a single-file `<input type="file">` via
     * `wire:model="activityCsv"`; the property stays null until the
     * user picks a file.
     */
    public ?TemporaryUploadedFile $activityCsv = null;

    /**
     * One-shot human-readable parse-time error surfaced inline below
     * the drop-zone when `RunsImports::runFromUpload()` throws. Cleared
     * on every fresh submit so a retry with a different file does not
     * keep the stale message.
     */
    public ?string $uploadError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'activityCsv' => ['required', 'file', 'max:10240', 'extensions:csv,txt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activityCsv.required' => 'Drop your PayPal Activity Download CSV into the box first.',
            'activityCsv.max' => 'That file is too large. PayPal Activity exports are normally well under 10 MB.',
            'activityCsv.extensions' => "That file doesn't look like a PayPal CSV. Download Activity (not the Balance Reconciliation Report) as CSV from PayPal.",
        ];
    }

    /**
     * Runs the PayPal Activity CSV through the shared import preview
     * pipeline. On the first successful preview the synthetic PayPal
     * `accounts` row is auto-created and the run is re-previewed from
     * the stable stored path so the preview cache + `statement_summaries`
     * reflect the now-resolvable account. The ImportRun id is then
     * stashed into `wizard_progress.data['paypal_import_run_id']` (single
     * int — mirrors the bank-step pattern) and `wizard.step.completed`
     * dispatches so the SetupWizard parent advances.
     */
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

        // Idempotent PayPal account create. The action returns true iff
        // it actually INSERTed; only re-preview on a real create so a
        // user with an existing PayPal account does not pay for a
        // second pipeline pass on every submit.
        $created = ($ensurePaypalAccount)($user);
        if ($created) {
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
                    );
                } catch (Throwable $e) {
                    $logger->warning('ConnectPaypalStep: re-preview after PayPal account creation failed.', [
                        'import_run_id' => $result->importRunId,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                        'exception_trace' => SafeTrace::cap($e, $app->basePath()),
                    ]);
                }
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

    /**
     * Marks this step `skipped` at the parent. The user can come back
     * later via Settings; the wizard_progress row stays in place — only
     * its status changes — and no `paypal_import_run_id` is stashed so
     * the consolidated preview screen omits the PayPal section.
     */
    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-paypal-step');
    }

    /**
     * Strips path-traversal characters and locks the extension to .csv
     * (the only format the PayPal Activity export ships in).
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.csv';
    }
}
