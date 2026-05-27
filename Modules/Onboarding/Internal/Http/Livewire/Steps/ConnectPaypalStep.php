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
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 3 — collects a single PayPal `Rapport Transactiegegevens`
 * (Transaction Details Report) CSV, idempotently creates the synthetic
 * PayPal `accounts` row via the shared `EnsurePaypalAccountAction`,
 * previews the CSV through the shared `RunsImports` pipeline so the
 * cached preview rows resolve against the just-created account, and
 * stashes the resulting ImportRun id under
 * `wizard_progress.data['paypal_import_run_id']` so the consolidated
 * first-import preview screen can render a PayPal section between the
 * bank and ICS card sections.
 *
 * Rapport Transactiegegevens exports cover an arbitrary user-chosen
 * date range, so the step accepts a single CSV (the standalone
 * `/imports/upload` flow remains the path for additional CSVs later).
 * The leaf format key `paypal-csv` matches `PaypalCsvPaymentTypeHinter`,
 * `PaypalCsvStartingBalanceDetector`, and `ClassifyTransactionType`'s
 * format branch.
 *
 * The account-then-preview order matters: the import pipeline tags
 * every row with `'error'` status when `AccountResolver` returns
 * `UnknownAccount`, and that all-error preview cache becomes the
 * source of truth for the FirstImportStep consolidated review. By
 * creating the synthetic PayPal account FIRST (`PAYPAL` IBAN, kind
 * `'paypal'`, EUR-settled — the literal the `PaypalCsvAdapter` hard-
 * codes on every row), the single subsequent `runFromUpload` call
 * resolves every row to the new account and the cache lands with
 * the `'new'`-status rows the consolidated preview surface counts.
 *
 * Two failure surfaces are handled inline below the drop-zone:
 *
 *  - The shared `RunsImports::runFromUpload()` may throw a Throwable
 *    that bubbles past the pipeline's own catch (file-system errors
 *    on stable-storage copy, hash_file failures, ImportRun insert
 *    clashes). The Throwable catch logs and surfaces a generic
 *    'could not read this file' message.
 *  - The pipeline converts every typed parse-time exception
 *    (`UnsupportedPaypalCsvShapeException`,
 *    `UnsupportedPaypalCsvLanguageException`, `SniffMismatchException`)
 *    into a single ERROR-status PreviewRowDto at rowIndex 0 instead
 *    of re-raising. The wizard step inspects the returned preview
 *    after the try/catch and treats a fatally-parsed preview (zero
 *    committable rows, zero unknown-IBAN naming prompts, at least
 *    one error row) as a non-advancing failure — surfacing the first
 *    error row's message verbatim so the typed-exception hint reaches
 *    the user. Without that check the wizard would stash the
 *    poisoned import-run id and the FirstImportStep consolidated
 *    PayPal section would render `READY · 0 ROWS`.
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
     * The single PayPal Rapport Transactiegegevens CSV the user dropped
     * on the step. Bound to a single-file `<input type="file">` via
     * `wire:model="activityCsv"`; the property stays null until the
     * user picks a file.
     */
    public ?TemporaryUploadedFile $activityCsv = null;

    /**
     * One-shot human-readable parse-time error surfaced inline below
     * the drop-zone when `RunsImports::runFromUpload()` throws OR when
     * the returned preview contains only error rows (a fatally-parsed
     * upload — wrong PayPal export type, unsupported locale, file shape
     * mismatch). Cleared on every fresh submit so a retry with a
     * different file does not keep the stale message.
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
            'activityCsv.required' => 'Drop your PayPal Rapport Transactiegegevens CSV into the box first.',
            'activityCsv.max' => 'That file is too large. PayPal Rapport Transactiegegevens exports are normally well under 10 MB.',
            'activityCsv.extensions' => "That file doesn't look like a PayPal CSV. Download Rapport Transactiegegevens (not the Saldorapport balance report) as CSV from PayPal.",
        ];
    }

    /**
     * Idempotently creates the synthetic PayPal `accounts` row, then
     * runs the dropped Rapport Transactiegegevens CSV through the shared
     * import preview pipeline so the preview cache + `statement_summaries`
     * reflect the now-resolvable account on the very first pass. The
     * ImportRun id is stashed into
     * `wizard_progress.data['paypal_import_run_id']` (single int —
     * mirrors the bank-step pattern) and `wizard.step.completed`
     * dispatches so the SetupWizard parent advances.
     *
     * A fatally-parsed upload (every preview row is `'error'`, no
     * unknown-IBAN naming prompts) short-circuits before the stash:
     * the first error row's message is surfaced inline so the
     * typed-exception hint reaches the user, and no
     * `wizard.step.completed` is dispatched.
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

        // Ensure the synthetic PayPal account exists BEFORE the
        // pipeline runs so `AccountResolver` resolves every row to a
        // KnownAccount on the single preview pass. Inverting this
        // order (preview-then-ensure) caches all-`'error'`-status
        // rows for the first run because the account doesn't exist
        // yet, and the FirstImportStep consolidated section would
        // render `READY · 0 rows`.
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
     * Returns the first error-row message when the preview produced ONLY
     * error rows (no committable signal, no unknown-IBAN naming prompts),
     * otherwise null.
     *
     * The shared `ImportPipeline` converts every parse-time typed
     * exception (`UnsupportedPaypalCsvShapeException`,
     * `UnsupportedPaypalCsvLanguageException`, `SniffMismatchException`,
     * and any other Throwable that escapes the per-row try/catch) into
     * a single ERROR-status PreviewRowDto at rowIndex 0 so the standalone
     * `/imports/upload` preview surface can render the message inline.
     * For the wizard step that contract masquerades as a successful
     * preview: `runFromUpload` returns normally with a non-empty rows
     * list, so without this gate the step would stash the poisoned
     * import-run id and the FirstImportStep consolidated section would
     * render `FROM PAYPAL · 0 ROWS · ✓ READY` because no row carries
     * status `'new'` or `'enriched'`.
     *
     * A preview that contains a mix of error AND committable rows is
     * legitimate (rare per-row failures inside an otherwise-good batch);
     * the gate fires only when EVERY row is an error AND there are no
     * unknown-IBAN naming prompts (a returning user with a non-PayPal
     * IBAN in the file would route through `accountsToName` instead).
     */
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
