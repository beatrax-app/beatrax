<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Contracts\RunsImports;

/**
 * Step 1 of the wizard. The user picks an issuer (ASN / ICS) and a format
 * for that issuer, then uploads a statement file (CSV, CAMT.053 XML, MT940,
 * or ICS PDF). On submit, the file is staged via Livewire's temporary
 * upload directory, the importer runs the preview phase (copying the
 * upload to a stable app-owned path on the way through), and the user is
 * redirected to /imports/{id}/preview.
 *
 * Two cascading selects drive the page: changing `$issuer` rebuilds the
 * Format select options via `availableFormats()` and resets
 * `$sourceFormat` to the first valid leaf for the new issuer. The leaf
 * `sourceFormat` value is the wire format consumed by HeaderSniffer,
 * SourceAdapterRegistry, and the per-source-format adapters; the issuer
 * field exists only to drive the picker UX.
 *
 * The 10 MB ceiling matches the typical maximum ASN statement export size
 * and is well above a Mijn ICS monthly PDF (~100 KB); the `messages()`
 * overrides surface user-readable strings for the validation failures.
 */
final class UploadWizard extends Component
{
    use WithFileUploads;

    /**
     * Stable format identifiers offered in the cascade's Format select.
     * The same list drives the `in:` validator and is the wire-level
     * format key that downstream adapters and the HeaderSniffer dispatch
     * on. A new format only needs to be added in one place.
     *
     * @var list<string>
     */
    public const SUPPORTED_FORMATS = [
        'asn-csv',
        'asn-camt053',
        'asn-mt940',
        'ics-pdf',
    ];

    public ?TemporaryUploadedFile $file = null;

    /**
     * Issuer driver for the cascading picker. Mounted with the most
     * common path (ASN); changing the issuer rebuilds `availableFormats()`
     * and resets `$sourceFormat` to that issuer's first leaf via
     * `updatedIssuer()`.
     */
    #[Validate('required|in:asn,ics')]
    public string $issuer = 'asn';

    public string $sourceFormat = 'asn-csv';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xml,sta,mt940,940,pdf'],
            'issuer' => ['required', 'in:asn,ics'],
            'sourceFormat' => ['required', 'in:asn-csv,asn-camt053,asn-mt940,ics-pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large. Drop in a statement export under 10 MB.',
            'file.mimes' => "That file doesn't look like a supported statement export. Drop in an ASN CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, or ICS PDF.",
        ];
    }

    /**
     * Returns the Format select options for the current issuer. Labels are
     * locked by the UI design contract and not derived from the leaf key
     * so a future format-key rename does not silently drift the visible
     * option text.
     *
     * @return list<array{value: string, label: string}>
     */
    public function availableFormats(): array
    {
        return match ($this->issuer) {
            'asn' => [
                ['value' => 'asn-csv', 'label' => 'CSV'],
                ['value' => 'asn-camt053', 'label' => 'CAMT.053 (XML)'],
                ['value' => 'asn-mt940', 'label' => 'MT940'],
            ],
            'ics' => [
                ['value' => 'ics-pdf', 'label' => 'PDF'],
            ],
            default => [],
        };
    }

    /**
     * Livewire updated-hook for the issuer property. Resets
     * `$sourceFormat` to the first leaf of the new issuer so the Format
     * select never holds a stale value from the previously-chosen issuer
     * (e.g. switching from ICS back to ASN must move sourceFormat off
     * 'ics-pdf' onto 'asn-csv'). Without this reset, a defensive
     * `in:asn-csv,asn-camt053,asn-mt940,ics-pdf` validator would still
     * accept the stale composite but the picker would visually disagree
     * with the submitted value.
     */
    public function updatedIssuer(): void
    {
        $first = $this->availableFormats()[0] ?? null;
        if ($first !== null) {
            $this->sourceFormat = $first['value'];
        }
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
            'asn-mt940' => '.sta',
            'ics-pdf' => '.pdf',
            default => '.csv',
        };

        return $stemPart.$extension;
    }
}
