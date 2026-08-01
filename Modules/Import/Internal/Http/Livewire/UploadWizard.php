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
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/import/architecture.md#upload-wizard
 */
final class UploadWizard extends Component
{
    use WithFileUploads;

    // Drives the `in:` validator; the same wire-level format key the
    // HeaderSniffer and downstream adapters dispatch on. A new format
    // only needs to be added in one place.
    /**
     * @var list<string>
     */
    public const SUPPORTED_FORMATS = [
        SourceFormat::AsnCsv->value,
        SourceFormat::Camt053->value,
        SourceFormat::Mt940->value,
        'ics-pdf',
        'paypal-csv',
        'eml',
        'mbox',
        // Bundled bank/fintech CSV presets (GenericCsvAdapter); mirrored from
        // CsvPresetRegistry. Grouped under the "other-bank" issuer.
        'n26-csv',
        'revolut-csv',
        'ing-nl-csv',
    ];

    // Enforces the issuer/sourceFormat cross-product inside rules() (a
    // mismatched pair fails the leaf-validator before ParseStage runs).
    /**
     * @var array<string, list<string>>
     */
    private const ISSUER_FORMAT_MAP = [
        'asn' => [SourceFormat::AsnCsv->value, SourceFormat::Camt053->value, SourceFormat::Mt940->value],
        'ics' => ['ics-pdf'],
        'paypal' => ['paypal-csv'],
        'email-file' => ['eml', 'mbox'],
        'other-bank' => ['n26-csv', 'revolut-csv', 'ing-nl-csv'],
    ];

    public ?TemporaryUploadedFile $file = null;

    // One-shot parse-time error surfaced below the upload form; cleared
    // on every fresh submit(). See architecture.md#upload-wizard for the
    // human-readable-message + full-trace-logging contract.
    public ?string $uploadError = null;

    // Mounted with the most common path (ASN); changing the issuer
    // rebuilds availableFormats() and resets $sourceFormat via
    // updatedIssuer().
    #[Validate('required|in:asn,ics,paypal,email-file,other-bank')]
    public string $issuer = 'asn';

    public string $sourceFormat = SourceFormat::AsnCsv->value;

    /**
     * @return array<string, list<\Closure|string>>
     */
    public function rules(): array
    {
        // .eml/.mbox validate via `extensions:` not `mimes:` — neither
        // extension has a native MIME-type registration, so `mimes:`
        // would silently reject every email-file upload regardless of
        // the actual file contents.
        $sizeRule = match ($this->sourceFormat) {
            'mbox' => 'max:1048576',
            'eml' => 'max:20480',
            default => 'max:'.UploadLimits::MAX_KB,
        };

        return [
            'file' => ['required', 'file', $sizeRule, 'extensions:csv,txt,xml,sta,mt940,940,pdf,eml,mbox'],
            'issuer' => ['required', 'in:asn,ics,paypal,email-file,other-bank'],
            'sourceFormat' => ['required', 'in:asn-csv,camt053,mt940,ics-pdf,paypal-csv,eml,mbox,n26-csv,revolut-csv,ing-nl-csv', $this->issuerFormatRule()],
        ];
    }

    // Enforces sourceFormat is in the issuer's allow-list — without it,
    // the bare `in:` rule would accept any leaf with any issuer and only
    // fail downstream at ParseStage.
    private function issuerFormatRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $allowed = self::ISSUER_FORMAT_MAP[$this->issuer] ?? [];
            if (! in_array($value, $allowed, strict: true)) {
                $fail(Lang::get('import::upload.errors.issuer_format', [
                    'attribute' => $attribute,
                    'source' => $this->issuer,
                ]));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => Lang::get('import::upload.errors.file_max'),
            'file.extensions' => Lang::get('import::upload.errors.file_extensions'),
        ];
    }

    // Labels are locked by the UI design contract and not derived from
    // the leaf key, so a future format-key rename can't silently drift
    // the visible option text.
    /**
     * @return list<array{value: string, label: string}>
     */
    public function availableFormats(): array
    {
        return match ($this->issuer) {
            'asn' => [
                ['value' => SourceFormat::AsnCsv->value, 'label' => 'CSV'],
                ['value' => SourceFormat::Camt053->value, 'label' => 'CAMT.053 (XML)'],
                ['value' => SourceFormat::Mt940->value, 'label' => 'MT940'],
            ],
            'ics' => [
                ['value' => 'ics-pdf', 'label' => 'PDF'],
            ],
            'paypal' => [
                ['value' => 'paypal-csv', 'label' => Lang::get('import::upload.formats.activity_download')],
            ],
            'email-file' => [
                ['value' => 'eml', 'label' => Lang::get('import::upload.formats.email_message')],
                ['value' => 'mbox', 'label' => Lang::get('import::upload.formats.mailbox_archive')],
            ],
            'other-bank' => [
                ['value' => 'n26-csv', 'label' => 'N26 (CSV)'],
                ['value' => 'revolut-csv', 'label' => 'Revolut (CSV)'],
                ['value' => 'ing-nl-csv', 'label' => Lang::get('import::upload.formats.ing_nl')],
            ],
            default => [],
        };
    }

    // Resets $sourceFormat to the new issuer's first leaf so the Format
    // select never holds a stale value (e.g. ICS -> ASN must move off
    // 'ics-pdf') — otherwise the picker would visually disagree with
    // the submitted value even though a defensive `in:` rule still passes.
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
        LoggerInterface $logger,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

        // The two ambiguous CSV dialects need an explicit bank-format
        // hint to bypass content-sniffing; every other format is
        // self-describing, so the hint is null. The picker already
        // commits the user to a specific bank format here.
        $formatHint = match ($this->sourceFormat) {
            SourceFormat::AsnCsv->value => BankCsvFormatHint::Asn,
            SourceFormat::IngCsv->value => BankCsvFormatHint::Ing,
            default => null,
        };

        try {
            $preview = $importer->runFromUpload(
                $tmp,
                $this->sourceFormat,
                $user,
                $originalFilename,
                $formatHint,
            );
        } catch (Throwable $e) {
            // Catch-all for failures that bubble out of runFromUpload()
            // before the pipeline's own try/catch wraps the parse loop
            // (filesystem errors, hash_file() failures, ImportRun insert
            // clashes) — logged in full for triage via /dev/logs.
            $logger->error('UploadWizard: import preview failed.', [
                'source_format' => $this->sourceFormat,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->uploadError = Lang::get('import::upload.errors.process_failed', [
                'class' => $e::class,
            ]);

            return;
        }

        $this->redirect(
            $urls->route('imports.preview', ['id' => $preview->importRunId]),
            navigate: false,
        );
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('import::livewire.upload-wizard');
    }

    // Sanitises to a safe `[A-Za-z0-9_-]+\.<ext>` shape — the original
    // name is never used to construct disk paths directly, so path-
    // traversal characters are stripped before any filesystem op sees
    // it. Extension comes from the declared source format, not the name.
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        $extension = match ($this->sourceFormat) {
            SourceFormat::Camt053->value => '.xml',
            SourceFormat::Mt940->value => '.sta',
            'ics-pdf' => '.pdf',
            'paypal-csv' => '.csv',
            'eml' => '.eml',
            'mbox' => '.mbox',
            default => '.csv',
        };

        return $stemPart.$extension;
    }
}
