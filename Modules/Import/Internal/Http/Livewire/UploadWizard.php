<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Contracts\RunsImports;

/**
 * Step 1 of the wizard. The user picks a source format and uploads a
 * statement file (CSV or CAMT.053 XML). On submit, the file is staged via
 * Livewire's temporary upload directory, the importer runs the preview
 * phase (copying the upload to a stable app-owned path on the way through),
 * and the user is redirected to /imports/{id}/preview.
 *
 * The 10 MB ceiling matches the typical maximum ASN export size; the
 * `messages()` overrides surface user-readable strings for the validation
 * failures.
 */
final class UploadWizard extends Component
{
    use WithFileUploads;

    /**
     * Stable format identifiers offered in the dropdown. The same list drives
     * the `in:` validator and the Blade option rendering so a new format only
     * needs to be added in one place.
     *
     * @var list<string>
     */
    public const SUPPORTED_FORMATS = [
        'asn-csv',
        'asn-camt053',
    ];

    public ?TemporaryUploadedFile $file = null;

    public string $sourceFormat = 'asn-csv';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml'],
            'sourceFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large. Drop in an ASN statement export under 10 MB.',
            'file.mimes' => "That file doesn't look like an ASN export. Drop in the CSV or CAMT.053 XML you downloaded from the ASN portal.",
        ];
    }

    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

        $preview = $importer->runFromUpload(
            $tmp,
            $this->sourceFormat,
            $user,
            $originalFilename,
        );

        $this->redirect(
            $urls->route('imports.preview', ['id' => $preview->importRunId]),
            navigate: false,
        );
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('import::livewire.upload-wizard');
    }

    /**
     * Sanitises an uploaded filename to a safe `[A-Za-z0-9_-]+\.<ext>` shape.
     * The user-supplied original name is never used to construct disk paths
     * directly — path traversal characters are stripped here before any
     * filesystem operation sees the value. The extension is picked from the
     * declared source format so a CAMT XML upload is not silently renamed to
     * `<name>.csv` on its way into stable storage.
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        $extension = match ($this->sourceFormat) {
            'asn-camt053' => '.xml',
            default => '.csv',
        };

        return $stemPart.$extension;
    }
}
