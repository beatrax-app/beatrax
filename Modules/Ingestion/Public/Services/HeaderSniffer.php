<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Banking\Camt053HeaderProfile;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940HeaderProfile;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Internal\Exceptions\UnsupportedPaypalCsvShapeException;
use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Receipts\Public\Pipeline\EmlHeaderProfile;
use Modules\Receipts\Public\Pipeline\MboxHeaderProfile;

final class HeaderSniffer
{
    private const EMPTY_FILE_MESSAGE = 'The file is empty.';

    private const CSV_EXTENSION_REGEX = '/\.csv$/i';

    private const HEAD_BYTES = 8192;

    private const UTF8_BOM = "\xEF\xBB\xBF";

    // The default arg lets a hand-constructed sniffer resolve presets without the
    // container; presets are static, so it behaves identically to the singleton.
    public function __construct(
        private readonly CsvPresetRegistry $presets = new CsvPresetRegistry,
    ) {}

    public function sniff(string $localPath, string $declaredFormat): SniffResult
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new SniffMismatchException(sprintf('File not readable: %s', $localPath));
        }

        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new SniffMismatchException(sprintf('Could not open file: %s', $localPath));
        }

        try {
            $head = (string) fread($handle, self::HEAD_BYTES);
        } finally {
            fclose($handle);
        }

        if (str_starts_with($head, self::UTF8_BOM)) {
            $head = substr($head, strlen(self::UTF8_BOM));
        }

        return match ($declaredFormat) {
            AsnCsvHeaderProfile::FORMAT => $this->sniffAsnCsv($localPath, $head),
            Camt053HeaderProfile::FORMAT => $this->sniffCamt053($localPath, $head),
            Mt940HeaderProfile::FORMAT => $this->sniffMt940($localPath, $head),
            IcsPdfHeaderProfile::FORMAT => $this->sniffIcsPdf($localPath, $head),
            PaypalCsvLanguageProfile::FORMAT => $this->sniffPaypalCsv($localPath, $head),
            EmlHeaderProfile::FORMAT => $this->sniffEml($localPath, $head),
            MboxHeaderProfile::FORMAT => $this->sniffMbox($localPath, $head),
            default => $this->sniffByPresetFormat($declaredFormat, $localPath, $head),
        };
    }

    private function sniffByPresetFormat(string $declaredFormat, string $localPath, string $head): SniffResult
    {
        $preset = $this->presets->get($declaredFormat);
        if ($preset === null) {
            throw new SniffMismatchException(sprintf('Unsupported sniff target: %s', $declaredFormat));
        }

        return $this->sniffPresetCsv($preset, $localPath, $head);
    }

    // The header-signature check is order-independent, so a reordered export still sniffs.
    private function sniffPresetCsv(CsvPreset $preset, string $path, string $head): SniffResult
    {
        if (preg_match(self::CSV_EXTENSION_REGEX, $path) !== 1) {
            throw new SniffMismatchException(sprintf(
                "That file doesn't look like a CSV. Drop in the %s CSV export.",
                $preset->label,
            ));
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException(self::EMPTY_FILE_MESSAGE);
        }

        $columns = str_getcsv($firstLine, $preset->delimiter, '"', '');
        $present = array_map(static fn (?string $c): string => CsvPreset::normaliseHeader((string) $c), $columns);

        foreach ($preset->headerSignature as $expected) {
            if (! in_array(CsvPreset::normaliseHeader($expected), $present, strict: true)) {
                throw new SniffMismatchException(sprintf(
                    "This CSV doesn't match the %s layout (missing the '%s' column). Make sure you picked the right bank.",
                    $preset->label,
                    $expected,
                ));
            }
        }

        return new SniffResult(
            format: $preset->format,
            delimiter: $preset->delimiter,
            hasHeader: true,
            encoding: $preset->encoding,
            columnCount: count($columns),
        );
    }

    // The header-anchor check rejects a renamed .eml before the zbateson parser is invoked.
    private function sniffEml(string $path, string $head): SniffResult
    {
        if (preg_match('/\.eml$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an email message. Drop in a .eml file."
            );
        }

        if (preg_match(EmlHeaderProfile::SIGNATURE_REGEX, $head) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an email message. Drop in a .eml file."
            );
        }

        return new SniffResult(
            format: EmlHeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: EmlHeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    // The literal "From " envelope prefix rejects a single-message .eml renamed to .mbox.
    private function sniffMbox(string $path, string $head): SniffResult
    {
        if (preg_match('/\.mbox$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a mailbox archive. Drop in a .mbox file."
            );
        }

        if (! str_starts_with($head, MboxHeaderProfile::MBOX_PREFIX)) {
            throw new SniffMismatchException(
                "That file doesn't look like a mailbox archive. Drop in a .mbox file."
            );
        }

        return new SniffResult(
            format: MboxHeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: MboxHeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    // No fixed column count, unlike sniffAsnCsv(): PayPal varies it across language
    // and format revisions.
    private function sniffPaypalCsv(string $path, string $head): SniffResult
    {
        if (preg_match(self::CSV_EXTENSION_REGEX, $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a CSV. In the PayPal portal, open the custom statements view, switch to the Betalingen tab, and download Rapport Transactiegegevens as CSV."
            );
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException(self::EMPTY_FILE_MESSAGE);
        }

        $columns = str_getcsv($firstLine, PaypalCsvLanguageProfile::DELIMITER, '"', '');

        // Checked before the language profile: an RH/RD/RF record-type prefix never appears
        // as a column name in Rapport Transactiegegevens, so the wrong-export hint is precise.
        $firstCell = trim($columns[0] ?? '');
        if (in_array($firstCell, ['RH', 'RD', 'RF'], strict: true)) {
            throw new UnsupportedPaypalCsvShapeException(
                'This looks like a PayPal Saldorapport (balance reconciliation report). '
                .'We need the per-transaction Rapport Transactiegegevens instead — in the PayPal portal, '
                .'open the custom statements view, switch to the Betalingen tab, and pick Rapport Transactiegegevens.'
            );
        }
        // The NL export ships some headers with a trailing space inside the quoted cell.
        $columns = array_map(static fn (?string $c): string => trim($c ?? ''), $columns);

        $profile = PaypalCsvLanguageProfile::detect($columns);
        if ($profile === null) {
            throw new UnsupportedPaypalCsvLanguageException(
                'PayPal CSV header tokens did not match any registered language profile. '
                .'Supported locales: '.implode(', ', PaypalCsvLanguageProfile::supported())
                .'. If your account exports in a different language, file an issue with the redacted CSV.'
            );
        }

        return new SniffResult(
            format: PaypalCsvLanguageProfile::FORMAT,
            delimiter: PaypalCsvLanguageProfile::DELIMITER,
            hasHeader: PaypalCsvLanguageProfile::HAS_HEADER,
            encoding: PaypalCsvLanguageProfile::SOURCE_ENCODING,
            columnCount: count($columns),
        );
    }

    // The magic-byte check rejects a renamed .pdf before pdftotext is invoked.
    private function sniffIcsPdf(string $path, string $head): SniffResult
    {
        if (preg_match('/\.pdf$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a PDF. Drop in the ICS PDF export you downloaded from the Mijn ICS portal."
            );
        }

        if (! str_starts_with($head, IcsPdfHeaderProfile::MIME_MAGIC)) {
            throw new SniffMismatchException(
                'This file does not start with %PDF-. If you exported a different file format from ICS by mistake, re-download the monthly statement PDF.'
            );
        }

        return new SniffResult(
            format: IcsPdfHeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: IcsPdfHeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    private function sniffMt940(string $path, string $head): SniffResult
    {
        if (preg_match('/\.(sta|mt940|940|txt)$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an MT940 export. Drop in the ASN MT940 file (.sta / .mt940 / .txt)."
            );
        }

        $body = $this->stripSwiftEnvelope($head);

        if (preg_match(Mt940HeaderProfile::SIGNATURE_REGEX, $body) !== 1) {
            throw new SniffMismatchException(
                'This file does not look like MT940 (no :20: tag at the start). If ASN changed their export format, file an issue.'
            );
        }

        return new SniffResult(
            format: Mt940HeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: Mt940HeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    private function stripSwiftEnvelope(string $head): string
    {
        if (preg_match(Mt940HeaderProfile::SWIFT_ENVELOPE_REGEX, $head, $matches) === 1) {
            return $matches[1];
        }

        return $head;
    }

    // The CAMT.052/054 sister families fail here with a "wrong family" message rather
    // than a cryptic parser error deep into the file.
    private function sniffCamt053(string $path, string $head): SniffResult
    {
        if (preg_match('/\.xml$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an XML file. Drop in the ASN CAMT.053 XML export."
            );
        }

        if (preg_match(Camt053HeaderProfile::XML_NAMESPACE_REGEX, $head) !== 1) {
            throw new SniffMismatchException(
                'This XML file does not declare an ISO 20022 CAMT.053 namespace. '
                .'If you uploaded a CAMT.052 or CAMT.054 file by mistake, re-download '
                .'the CAMT.053 statement from the ASN portal.'
            );
        }

        return new SniffResult(
            format: Camt053HeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: Camt053HeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    private function sniffAsnCsv(string $path, string $head): SniffResult
    {
        if (preg_match(self::CSV_EXTENSION_REGEX, $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal."
            );
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException(self::EMPTY_FILE_MESSAGE);
        }

        $delim = AsnCsvHeaderProfile::DELIMITER;
        $columns = str_getcsv($firstLine, $delim, '"', '');

        if (! in_array(count($columns), AsnCsvHeaderProfile::ACCEPTED_COLUMN_COUNTS, strict: true)) {
            throw new SniffMismatchException(sprintf(
                'Expected %s columns, got %d. This file does not match the ASN CSV layout.',
                implode(' or ', AsnCsvHeaderProfile::ACCEPTED_COLUMN_COUNTS),
                count($columns),
            ));
        }

        $expected = AsnCsvHeaderProfile::HEADER_SIGNATURE;
        if ($columns[0] !== $expected[0] || $columns[1] !== $expected[1]) {
            throw new SniffMismatchException(sprintf(
                "This CSV doesn't match the expected ASN column layout (header starts with '%s,%s', got '%s,%s'). If ASN changed their export format, file an issue.",
                $expected[0],
                $expected[1],
                $columns[0],
                $columns[1],
            ));
        }

        return new SniffResult(
            format: AsnCsvHeaderProfile::FORMAT,
            delimiter: $delim,
            hasHeader: AsnCsvHeaderProfile::HAS_HEADER,
            encoding: AsnCsvHeaderProfile::SOURCE_ENCODING,
            columnCount: count($columns),
        );
    }
}
