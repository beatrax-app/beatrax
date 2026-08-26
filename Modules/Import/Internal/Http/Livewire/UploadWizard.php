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
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

final class UploadWizard extends Component
{
    use WithFileUploads;

    // The same format key HeaderSniffer and the adapters dispatch on, so a
    // new format is added here only.
    /**
     * @var list<string>
     */
    public const SUPPORTED_FORMATS = [
        SourceFormat::AsnCsv->value,
        SourceFormat::Camt053->value,
        SourceFormat::Mt940->value,
        SourceFormat::IcsPdf->value,
        SourceFormat::PaypalCsv->value,
        SourceFormat::Eml->value,
        SourceFormat::Mbox->value,
        CsvPresetRegistry::N26,
        CsvPresetRegistry::REVOLUT,
        CsvPresetRegistry::ING_NL,
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ISSUER_FORMAT_MAP = [
        'asn' => [SourceFormat::AsnCsv->value, SourceFormat::Camt053->value, SourceFormat::Mt940->value],
        'ics' => [SourceFormat::IcsPdf->value],
        'paypal' => [SourceFormat::PaypalCsv->value],
        'email-file' => [SourceFormat::Eml->value, SourceFormat::Mbox->value],
        CsvPresetRegistry::ISSUER => [CsvPresetRegistry::N26, CsvPresetRegistry::REVOLUT, CsvPresetRegistry::ING_NL],
    ];

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    #[Validate('required|in:asn,ics,paypal,email-file,other-bank')]
    public string $issuer = 'asn';

    public string $sourceFormat = SourceFormat::AsnCsv->value;

    /**
     * @return array<string, list<\Closure|string>>
     */
    public function rules(): array
    {
        // .eml/.mbox have no registered MIME type, so a `mimes:` rule would
        // reject every email upload whatever its contents; `extensions:` is
        // the only rule that works here.
        $sizeRule = match ($this->sourceFormat) {
            SourceFormat::Mbox->value => 'max:1048576',
            SourceFormat::Eml->value => 'max:20480',
            default => 'max:'.UploadLimits::MAX_KB,
        };

        return [
            'file' => ['required', 'file', $sizeRule, 'extensions:csv,txt,xml,sta,mt940,940,pdf,eml,mbox'],
            'issuer' => ['required', 'in:asn,ics,paypal,email-file,other-bank'],
            'sourceFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS), $this->issuerFormatRule()],
        ];
    }

    // The bare `in:` rule accepts any leaf under any issuer; without this
    // the mismatch only surfaces at ParseStage.
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

    // Labels are written out rather than derived from the leaf key, so
    // renaming a format key cannot silently change the visible option text.
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
                ['value' => SourceFormat::IcsPdf->value, 'label' => 'PDF'],
            ],
            'paypal' => [
                ['value' => SourceFormat::PaypalCsv->value, 'label' => Lang::get('import::upload.formats.activity_download')],
            ],
            'email-file' => [
                ['value' => SourceFormat::Eml->value, 'label' => Lang::get('import::upload.formats.email_message')],
                ['value' => SourceFormat::Mbox->value, 'label' => Lang::get('import::upload.formats.mailbox_archive')],
            ],
            CsvPresetRegistry::ISSUER => [
                ['value' => CsvPresetRegistry::N26, 'label' => 'N26 (CSV)'],
                ['value' => CsvPresetRegistry::REVOLUT, 'label' => 'Revolut (CSV)'],
                ['value' => CsvPresetRegistry::ING_NL, 'label' => Lang::get('import::upload.formats.ing_nl')],
            ],
            default => [],
        };
    }

    // Without the reset the Format select keeps a leaf from the old issuer
    // and disagrees with the value actually submitted, which the defensive
    // `in:` rule still accepts.
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
        $originalFilename = UploadFilename::sanitise($this->file->getClientOriginalName(), UploadFilename::extensionFor($this->sourceFormat));

        // Only the built-in ASN CSV is ambiguous; a preset names its dialect in
        // its own format id, and every other format is self-describing.
        $formatHint = match ($this->sourceFormat) {
            SourceFormat::AsnCsv->value => BankCsvFormatHint::Asn,
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
            // Failures that bubble out before the pipeline's own try/catch
            // reaches the parse loop: filesystem errors, hash_file(), an
            // ImportRun insert clash.
            $logger->error('UploadWizard: import preview failed.', [
                'source_format' => $this->sourceFormat,
                'filename' => $originalFilename,
                ...SafeExceptionContext::describe($e),
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
}
