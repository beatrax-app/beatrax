<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
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
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class ConnectCardStep extends Component
{
    use WithFileUploads;

    // Mirrors IcsPdfAdapter::ICS_OWN_IBAN — kept in sync by the arch test
    // ics_pdf_adapter_own_iban_matches_connect_card_step.
    private const ICS_OWN_IBAN = 'ICS-CARD';

    public string $selectedFormat = 'ics-pdf';

    /** @var array<int, TemporaryUploadedFile> */
    public array $statements = [];

    // Surfaced only when every queued file failed; a mixed-result submit
    // still dispatches wizard.step.completed.
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

    public function removeStatement(int $index): void
    {
        if (! array_key_exists($index, $this->statements)) {
            return;
        }

        unset($this->statements[$index]);
        $this->statements = array_values($this->statements);
    }

    // Per-file errors are logged but don't abort the submit — the loop
    // continues so a single bad statement doesn't waste the rest.
    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
        DatabaseManager $db,
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

        // Auto-creates the ICS account + re-previews when missing — see
        // architecture.md for why. Raw query builder (not
        // Account::query()->exists()) to satisfy PHPStan strict-rules
        // staticMethod.dynamicCall.
        $hasIcsAccount = $db->connection()
            ->table('accounts')
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

                if ($run !== null && file_exists($run->raw_file_path)) {
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

    // Strips path-traversal characters and locks the extension to .pdf,
    // the only format the ICS step accepts.
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.pdf';
    }
}
