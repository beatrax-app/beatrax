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
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Step 1 of the wizard. The user picks an issuer (ASN / ICS / PayPal)
 * and a format for that issuer, then uploads a statement file (CSV,
 * CAMT.053 XML, MT940, ICS PDF, or PayPal Activity Download CSV). On
 * submit, the file is staged via Livewire's temporary upload directory,
 * the importer runs the preview phase (copying the upload to a stable
 * app-owned path on the way through), and the user is redirected to
 * /imports/{id}/preview.
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

    /**
     * Issuer / sourceFormat pairings the wizard accepts. Used inside
     * `rules()` to enforce that the cross-product is meaningful (e.g.
     * `issuer='email-file'` paired with `sourceFormat='asn-csv'`
     * fails the leaf-validator before ParseStage ever runs). New
     * issuer + format pairs land here once.
     *
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

    /**
     * One-shot error surfaced inline below the upload form when the
     * importer raises a parse-time exception (sniff mismatch, unsupported
     * PayPal language, unreadable file, etc.) before the wizard can hand
     * off to the preview screen. Cleared on every fresh submit() so a
     * second attempt with a different file does not retain the stale
     * message.
     *
     * The matching stack trace is also written to the Laravel log via
     * the injected LoggerInterface so the failure is visible on
     * /dev/logs — the user-reported "silent failure" symptom was that
     * the Livewire generic-error toast surfaced no actionable detail
     * AND no log line surfaced the cause. The error message stored here
     * is intentionally human-readable (typed-exception subclasses carry
     * a user-facing message; generic Throwables fall back to a stable
     * "Could not process this file." copy with the exception class
     * appended for triage).
     */
    public ?string $uploadError = null;

    /**
     * Issuer driver for the cascading picker. Mounted with the most
     * common path (ASN); changing the issuer rebuilds `availableFormats()`
     * and resets `$sourceFormat` to that issuer's first leaf via
     * `updatedIssuer()`.
     */
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
            default => 'max:10240',
        };

        return [
            'file' => ['required', 'file', $sizeRule, 'extensions:csv,txt,xml,sta,mt940,940,pdf,eml,mbox'],
            'issuer' => ['required', 'in:asn,ics,paypal,email-file,other-bank'],
            'sourceFormat' => ['required', 'in:asn-csv,camt053,mt940,ics-pdf,paypal-csv,eml,mbox,n26-csv,revolut-csv,ing-nl-csv', $this->issuerFormatRule()],
        ];
    }

    /**
     * Returns the closure that enforces the cross-product validation:
     * `sourceFormat` must be in the issuer's allow-list. Without this
     * rule the validator would accept any leaf with any issuer, so an
     * `issuer='email-file' + sourceFormat='asn-csv'` pair would pass
     * the dumb `in:` rule and only fail downstream at ParseStage.
     */
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

    /**
     * Livewire updated-hook for the issuer property. Resets
     * `$sourceFormat` to the first leaf of the new issuer so the Format
     * select never holds a stale value from the previously-chosen issuer
     * (e.g. switching from ICS back to ASN must move sourceFormat off
     * 'ics-pdf' onto 'asn-csv'). Without this reset, a defensive
     * `in:asn-csv,camt053,mt940,ics-pdf` validator would still
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
