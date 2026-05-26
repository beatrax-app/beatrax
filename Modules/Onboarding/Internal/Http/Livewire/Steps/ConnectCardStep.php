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
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Onboarding\Models\WizardProgress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wizard step 3 — connect the user's ICS credit card. ICS Cards's
 * consumer portal (Mijn ICS) only exports monthly PDF statements; the
 * user typically downloads several months at once so the step accepts
 * an array of PDF uploads and runs each through the importer in a
 * single submit pass.
 *
 * Submission delegates per-file to `RunsImports::runFromUpload()` with
 * the leaf format key `ics-pdf` — the same path `Modules/Import`'s
 * IcsPdfAdapter consumes. Each successful preview lands one
 * `ImportRun` row; the resulting ids are merged into
 * `wizard_progress.data['card_import_run_ids']` as a deduplicated
 * array so the consolidated preview screen can read them back.
 *
 * A per-file parse failure is logged (with capped stack trace) but
 * does not abort the submit — the loop continues to the next file so
 * one bad PDF doesn't waste the rest of the upload. Once every file
 * has been attempted, the step dispatches `wizard.step.completed` if
 * at least one file landed an ImportRun; otherwise it surfaces an
 * inline error and stays on the step.
 *
 * Per the project DI-only rule, every collaborator is method-DI'd on
 * `submit()`; no global helpers are called.
 */
final class ConnectCardStep extends Component
{
    use WithFileUploads;

    /**
     * Synthetic IBAN literal IcsPdfAdapter uses for all ICS card rows.
     * Mirrors `IcsPdfAdapter::ICS_OWN_IBAN` — kept in sync by the
     * architecture test `ics_pdf_adapter_own_iban_matches_connect_card_step`.
     */
    private const ICS_OWN_IBAN = 'ICS-CARD';

    /**
     * The leaf format key the ICS step ships. Constant — ICS Cards
     * exports PDF only.
     */
    public string $selectedFormat = 'ics-pdf';

    /**
     * The PDF uploads queued for this submit. Bound to a
     * `<input type="file" multiple>` via `wire:model="statements"`;
     * each entry is a Livewire-staged TemporaryUploadedFile.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $statements = [];

    /**
     * One-shot human-readable parse-time error surfaced inline below
     * the drop-zone when EVERY queued file failed to land an
     * ImportRun. A mixed-result submit (some succeeded, some failed)
     * still dispatches `wizard.step.completed`.
     */
    public ?string $uploadError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'statements' => ['array', 'min:1'],
            'statements.*' => ['file', 'max:10240', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statements.required' => 'Drop the monthly PDF statements you downloaded from Mijn ICS.',
            'statements.min' => 'Drop at least one ICS PDF statement before continuing.',
            'statements.*.required' => 'Drop the monthly PDF statement you downloaded from Mijn ICS.',
            'statements.*.max' => 'One of your files is too large. ICS PDF statements are normally under 1 MB each.',
            'statements.*.extensions' => "One of your files isn't a PDF. Mijn ICS only exports PDF — try the latest monthly statement.",
        ];
    }

    /**
     * Removes one statement from the upload queue without re-uploading
     * the rest. Bound from the per-file chip's `×` button.
     */
    public function removeStatement(int $index): void
    {
        if (! array_key_exists($index, $this->statements)) {
            return;
        }

        unset($this->statements[$index]);
        $this->statements = array_values($this->statements);
    }

    /**
     * Runs every queued PDF through the shared import preview
     * pipeline. Per-file errors are logged (with capped stack traces)
     * but do not abort the submit — the loop continues to the next
     * file so a single bad statement doesn't waste the upload.
     *
     * On at least one success the step stashes the resulting ImportRun
     * ids into `wizard_progress.data['card_import_run_ids']` and
     * dispatches `wizard.step.completed`. On total failure (every file
     * threw) the step surfaces the first failure's message inline.
     */
    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->statements === []) {
            return;
        }

        $user = $currentUser->user();

        $newRunIds = [];
        $firstError = null;

        foreach ($this->statements as $statement) {
            $tmp = $statement->getRealPath();
            $originalFilename = $this->sanitiseFilename($statement->getClientOriginalName());

            try {
                $result = $importer->runFromUpload($tmp, $this->selectedFormat, $user, $originalFilename);
                $newRunIds[] = $result->importRunId;
            } catch (Throwable $e) {
                $logger->error('ConnectCardStep: import preview failed.', [
                    'source_format' => $this->selectedFormat,
                    'filename' => $originalFilename,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => SafeTrace::cap($e, $app->basePath()),
                ]);
                if ($firstError === null) {
                    $firstError = sprintf('Could not read %s. The full error is in /dev/logs.', $originalFilename);
                }
            }
        }

        if ($newRunIds === []) {
            $this->uploadError = sprintf(
                "We couldn't read any of your ICS PDFs. %s",
                $firstError ?? 'The full error is in /dev/logs.',
            );

            return;
        }

        // If the user has no ICS card account yet, auto-create one so
        // the ICS PDF rows resolve against a known account. The
        // IcsPdfAdapter uses the synthetic IBAN 'ICS-CARD' for every
        // row; without a matching account the import preview treats every
        // row as an unknown-IBAN error and the statement_summaries writer
        // never fires — which means the starting-balance detector can find
        // nothing on step 5. After creation, re-preview each ImportRun
        // from its stable stored path so the preview cache and
        // statement_summaries rows are populated against the new account.
        $hasIcsAccount = Account::query()
            ->where('user_id', $user->id)
            ->where('iban', self::ICS_OWN_IBAN)
            ->exists();

        if (! $hasIcsAccount) {
            Account::query()->create([
                'user_id' => $user->id,
                'name' => 'ICS card',
                'slug' => 'ics-card-ics-card',
                'kind' => 'ics_card',
                'iban' => self::ICS_OWN_IBAN,
                'default_currency' => 'EUR',
            ]);

            // Re-preview the runs from their stable stored paths so
            // statement_summaries is populated and the preview cache
            // reflects the now-resolvable account.
            foreach ($newRunIds as $runId) {
                /** @var ImportRun|null $run */
                $run = ImportRun::query()
                    ->where('id', $runId)
                    ->where('user_id', $user->id)
                    ->first();

                if ($run !== null && $run->raw_file_path !== null && file_exists($run->raw_file_path)) {
                    try {
                        $importer->runFromUpload(
                            $run->raw_file_path,
                            $this->selectedFormat,
                            $user,
                            basename($run->raw_file_path),
                        );
                    } catch (Throwable $e) {
                        $logger->warning('ConnectCardStep: re-preview after ICS account creation failed.', [
                            'import_run_id' => $runId,
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
            ->where('step_key', 'connect-card')
            ->first();

        if ($progress !== null) {
            $data = $progress->data ?? [];
            $existingRaw = $data['card_import_run_ids'] ?? [];
            $existing = [];
            if (is_array($existingRaw)) {
                foreach ($existingRaw as $value) {
                    if (is_int($value) && $value > 0) {
                        $existing[] = $value;
                    }
                }
            }
            $merged = array_values(array_unique([...$existing, ...$newRunIds], SORT_NUMERIC));
            $data['card_import_run_ids'] = $merged;
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
