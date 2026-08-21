<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\RunsImports;
use Psr\Log\LoggerInterface;
use Throwable;

// Kept apart from the live connection it sits beside: this stores no
// credentials and routes a dropped PDF at the existing ics-pdf SourceAdapter.
trait ManagesGuidedIcsImport
{
    // ICS Cards's consumer portal only exports monthly PDF statements, so the
    // guided drop pre-selects this one format.
    private const ICS_SOURCE_FORMAT = 'ics-pdf';

    public ?TemporaryUploadedFile $icsStatement = null;

    public ?string $icsImportError = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'icsStatement' => ['required', 'file', 'max:1024', 'mimetypes:application/pdf', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'icsStatement.required' => Lang::get('openbanking::messages.ics.validation.required'),
            'icsStatement.max' => Lang::get('openbanking::messages.ics.validation.max'),
            'icsStatement.extensions' => Lang::get('openbanking::messages.ics.validation.extensions'),
        ];
    }

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
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->icsImportError = Lang::get('openbanking::messages.ics.could_not_read', ['filename' => $originalFilename]);

            return;
        }

        $this->redirectRoute('imports.preview', ['id' => $result->importRunId], navigate: false);
    }

    private static function sanitiseIcsFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.pdf';
    }
}
