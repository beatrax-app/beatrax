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
 * Step 1 of the wizard. The user picks a source format and uploads a CSV
 * file. On submit, the file is staged via Livewire's temporary upload
 * directory, the importer runs the preview phase, and the user is
 * redirected to /imports/{id}/preview.
 *
 * That file is too large. Drop in an ASN CSV export under 10 MB.
 * (^ this literal copy is surfaced as the messages() value for `max:10240`
 * per UI-SPEC §Error states.)
 */
final class UploadWizard extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $file = null;

    public string $sourceFormat = 'asn-csv';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'sourceFormat' => ['required', 'in:asn-csv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large. Drop in an ASN CSV export under 10 MB.',
            'file.mimes' => "That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal.",
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
     * Sanitises an uploaded filename to a safe `[A-Za-z0-9_-]+\.csv` shape.
     * The user-supplied original name is NEVER used to construct disk paths
     * (T-05-01 — path traversal mitigation).
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);

        return ($safe === null || $safe === '' ? 'upload' : $safe).'.csv';
    }
}
