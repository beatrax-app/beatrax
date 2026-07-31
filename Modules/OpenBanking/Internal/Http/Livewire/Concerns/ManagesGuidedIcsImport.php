<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\RunsImports;
use Psr\Log\LoggerInterface;
use Throwable;

// The B7 guided ICS file-import affordance, kept apart from the live Open
// Banking connection it sits beside: it stores no credentials and routes a
// dropped PDF statement straight to the existing ics-pdf SourceAdapter. Its
// own validation rules, upload property, and filename hardening travel here.
/**
 * @link ../../../../../../.docs/features/open-banking/architecture.md
 */
trait ManagesGuidedIcsImport
{
    // The leaf format key the existing ICS SourceAdapter consumes. ICS
    // Cards's consumer portal only ever exports monthly PDF statements, so
    // the guided drop pre-selects this single format.
    private const ICS_SOURCE_FORMAT = 'ics-pdf';

    public ?TemporaryUploadedFile $icsStatement = null;

    public ?string $icsImportError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // mimetypes checks the actual sniffed content type rather than
            // trusting the client-supplied extension alone.
            'icsStatement' => ['required', 'file', 'max:1024', 'mimetypes:application/pdf', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'icsStatement.required' => 'Drop the ICS statement you downloaded from Mijn ICS.',
            'icsStatement.max' => 'That file is too large. ICS PDF statements are normally under 1 MB each.',
            'icsStatement.extensions' => "That isn't a PDF. Mijn ICS only exports PDF statements.",
        ];
    }

    /**
     * @link ../../../../../../.docs/features/open-banking/architecture.md
     */
    public function importIcsStatement(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->icsImportError = null;
        $this->validate();

        if ($this->icsStatement === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->icsStatement->getRealPath();
        $originalFilename = self::sanitiseIcsFilename($this->icsStatement->getClientOriginalName());

        try {
            $result = $importer->runFromUpload($tmp, self::ICS_SOURCE_FORMAT, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('OpenBankingSettingsPage: guided ICS import preview failed.', [
                'source_format' => self::ICS_SOURCE_FORMAT,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->icsImportError = "Could not read {$originalFilename}. The full error is in /dev/logs.";

            return;
        }

        $this->redirectRoute('imports.preview', ['id' => $result->importRunId], navigate: false);
    }

    // Strips path-traversal characters and locks the extension to .pdf,
    // since both feed the same ics-pdf adapter.
    private static function sanitiseIcsFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.pdf';
    }
}
