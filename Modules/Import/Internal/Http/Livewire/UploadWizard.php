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
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
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
        'asn-csv',
        'camt053',
        'mt940',
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
        'asn' => ['asn-csv', 'camt053', 'mt940'],
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

    public string $sourceFormat = 'asn-csv';

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
                $fail(sprintf(
                    'The %s value is not valid for the %s source.',
                    $attribute,
                    $this->issuer,
                ));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'That file is too large. Drop in a statement export under the size limit for the chosen format.',
            'file.extensions' => "That file doesn't look like a supported statement export. Drop in a bank CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, a card-statement PDF, an email message (.eml), or a mailbox archive (.mbox).",
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
                ['value' => 'asn-csv', 'label' => 'CSV'],
                ['value' => 'camt053', 'label' => 'CAMT.053 (XML)'],
                ['value' => 'mt940', 'label' => 'MT940'],
            ],
            'ics' => [
                ['value' => 'ics-pdf', 'label' => 'PDF'],
            ],
            'paypal' => [
                ['value' => 'paypal-csv', 'label' => 'Activity Download (CSV)'],
            ],
            'email-file' => [
                ['value' => 'eml', 'label' => 'Email message (.eml)'],
                ['value' => 'mbox', 'label' => 'Mailbox archive (.mbox)'],
            ],
            'other-bank' => [
                ['value' => 'n26-csv', 'label' => 'N26 (CSV)'],
                ['value' => 'revolut-csv', 'label' => 'Revolut (CSV)'],
                ['value' => 'ing-nl-csv', 'label' => 'ING Netherlands (CSV)'],
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
            'asn-csv' => BankCsvFormatHint::Asn,
            'ing-csv' => BankCsvFormatHint::Ing,
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
            $this->uploadError = sprintf(
                'Could not process this file (%s). The full error is in /dev/logs.',
                $e::class,
            );

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
            'camt053' => '.xml',
            'mt940' => '.sta',
            'ics-pdf' => '.pdf',
            'paypal-csv' => '.csv',
            'eml' => '.eml',
            'mbox' => '.mbox',
            default => '.csv',
        };

        return $stemPart.$extension;
    }
}
