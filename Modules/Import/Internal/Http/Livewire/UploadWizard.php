<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Internal\Enums\ImportType;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Receipts\Public\Pipeline\ReceiptFileShape;
use Psr\Log\LoggerInterface;
use Throwable;

final class UploadWizard extends Component
{
    use WithFileUploads;

    // The same format key HeaderSniffer and the adapters dispatch on. This
    // list is what the upload screen offers; a new format also needs its
    // adapter, hinter, detector and registry entries.
    /**
     * @var list<string>
     */
    public const array SUPPORTED_FORMATS = [
        CsvPresetRegistry::ASN,
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

    // Laravel's `max:` counts kilobytes on a file rule. Both of these were
    // written as byte counts, which put the .mbox ceiling at 1 GiB and the
    // .eml ceiling at 20 MiB — above the runtime's own upload_max_filesize,
    // so an oversized upload died as an opaque POST failure.
    private const int MBOX_MAX_KB = 1_024;

    private const int EML_MAX_KB = 20;

    public ?TemporaryUploadedFile $file = null;

    public ?string $uploadError = null;

    public ?string $formatNotice = null;

    // The rule is in rules() only: a #[Validate] argument is a constant
    // expression, and a hand-written copy of the enum's values is a second
    // list to keep in step.
    public string $importType = ImportType::Csv->value;

    public string $sourceFormat = CsvPresetRegistry::ASN;

    private CsvPresetRegistry $presets;

    public function boot(CsvPresetRegistry $presets): void
    {
        $this->presets = $presets;
    }

    /**
     * @return array<string, list<\Closure|Enum|string>>
     */
    public function rules(): array
    {
        // .eml/.mbox have no registered MIME type, so a `mimes:` rule would
        // reject every email upload whatever its contents; `extensions:` is
        // the only rule that works here.
        $sizeRule = match ($this->sourceFormat) {
            SourceFormat::Mbox->value => 'max:'.self::MBOX_MAX_KB,
            SourceFormat::Eml->value => 'max:'.self::EML_MAX_KB,
            default => 'max:'.UploadLimits::MAX_KB,
        };

        return [
            'file' => ['required', 'file', $sizeRule, 'extensions:csv,txt,xml,sta,mt940,940,pdf,eml,mbox'],
            'importType' => ['required', Rule::enum(ImportType::class)],
            'sourceFormat' => ['required', 'in:'.implode(',', self::SUPPORTED_FORMATS), $this->importTypeFormatRule()],
        ];
    }

    // The bare `in:` rule accepts any leaf under any import type; without this
    // the mismatch only surfaces at ParseStage.
    private function importTypeFormatRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $allowed = ImportType::tryFrom($this->importType)?->formats() ?? [];
            if (! in_array($value, $allowed, strict: true)) {
                $fail(Lang::get('import::upload.errors.type_format', [
                    'attribute' => $attribute,
                    'type' => $this->importType,
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

    /**
     * @return list<array{value: string, label: string}>
     */
    public function availableFormats(): array
    {
        $formats = ImportType::tryFrom($this->importType)?->formats() ?? [];

        return array_map(
            fn (string $format): array => ['value' => $format, 'label' => $this->formatLabel($format)],
            $formats,
        );
    }

    // A CSV layout is named by the preset that reads it, so a bank's own name
    // reaches this select as preset data. The rest are written out rather than
    // derived from the format key, so renaming a key cannot silently change
    // the visible option text.
    private function formatLabel(string $format): string
    {
        $label = $this->presets->layout($format)?->label;

        return $label ?? match ($format) {
            SourceFormat::Camt053->value => 'CAMT.053 (XML)',
            SourceFormat::Mt940->value => 'MT940',
            SourceFormat::IcsPdf->value => 'PDF',
            SourceFormat::PaypalCsv->value => Lang::get('import::upload.formats.activity_download'),
            SourceFormat::Eml->value => Lang::get('import::upload.formats.email_message'),
            SourceFormat::Mbox->value => Lang::get('import::upload.formats.mailbox_archive'),
            default => $format,
        };
    }

    // Without the reset the Format select keeps a leaf from the old import
    // type and disagrees with the value actually submitted, which the
    // defensive `in:` rule still accepts.
    public function updatedImportType(): void
    {
        $first = $this->availableFormats()[0] ?? null;
        if ($first !== null) {
            $this->sourceFormat = $first['value'];
        }

        $this->matchFormatToFile();
    }

    public function updatedFile(): void
    {
        $this->matchFormatToFile();
    }

    // The notice describes a pick this screen made. Once the reader makes their
    // own it describes something that is no longer on the screen.
    public function updatedSourceFormat(): void
    {
        $this->formatNotice = null;
    }

    // The email type opens on the single-message format, and an archive read as
    // one message drops every message but the first, silently. The file's own
    // bytes settle it. Only within the email pair: another type's leaf is what
    // importTypeFormatRule() refuses, and rescuing it would hide that refusal.
    private function matchFormatToFile(): void
    {
        $this->formatNotice = null;

        $declared = SourceFormat::tryFrom($this->sourceFormat);
        if ($this->file === null || $declared?->isReceiptFile() !== true) {
            return;
        }

        $found = ReceiptFileShape::of($this->file->getRealPath());
        if ($found === null || $found === $declared) {
            return;
        }

        $this->sourceFormat = $found->value;
        $this->formatNotice = Lang::get('import::upload.format_from_file', [
            'format' => $this->formatLabel($found->value),
        ]);
    }

    public function submit(
        RunsImports $importer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = UploadFilename::sanitise($this->file->getClientOriginalName(), UploadFilename::extensionFor($this->sourceFormat));

        // Redundant with the format id, which already names the dialect. It is
        // still passed because ImportPipeline::preview() refuses asn-csv without
        // it, and that guard cannot go while other modules' tests supply it.
        $formatHint = match ($this->sourceFormat) {
            CsvPresetRegistry::ASN => BankCsvFormatHint::Asn,
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
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
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
