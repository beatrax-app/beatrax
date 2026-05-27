<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053HeaderProfile;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Asn\AsnMt940HeaderProfile;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfHeaderProfile;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvLanguageException;
use Modules\Ingestion\Public\Exceptions\UnsupportedPaypalCsvShapeException;
use Modules\Receipts\Public\Pipeline\EmlHeaderProfile;
use Modules\Receipts\Public\Pipeline\MboxHeaderProfile;

/**
 * Validates that a local file matches a declared source format before any
 * adapter starts parsing. Inspects the first 8 KB of bytes, the file
 * extension, the CSV header row, and (for the XML formats) the document
 * namespace URI.
 *
 * A leading UTF-8 BOM is stripped before parsing so files exported through
 * tools that prepend one (Excel, some browser downloads) sniff cleanly
 * rather than failing the header-signature compare with the BOM bytes
 * silently glued to the first column name.
 *
 * The exception messages are user-facing — the upload wizard renders them
 * verbatim.
 */
final class HeaderSniffer
{
    private const HEAD_BYTES = 8192;

    private const UTF8_BOM = "\xEF\xBB\xBF";

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
            AsnCamt053HeaderProfile::FORMAT => $this->sniffAsnCamt053($localPath, $head),
            AsnMt940HeaderProfile::FORMAT => $this->sniffAsnMt940($localPath, $head),
            IcsPdfHeaderProfile::FORMAT => $this->sniffIcsPdf($localPath, $head),
            PaypalCsvLanguageProfile::FORMAT => $this->sniffPaypalCsv($localPath, $head),
            EmlHeaderProfile::FORMAT => $this->sniffEml($localPath, $head),
            MboxHeaderProfile::FORMAT => $this->sniffMbox($localPath, $head),
            default => throw new SniffMismatchException(sprintf(
                'Unsupported sniff target: %s',
                $declaredFormat,
            )),
        };
    }

    /**
     * Validates that the path looks like a single RFC 822 `.eml`
     * message — `.eml` extension AND a recognised header anchor
     * (`Return-Path:`, `Received:`, `From:`, or `Message-ID:`) in the
     * first 8 KB. The header check rejects a renamed `.eml` upload of
     * an unrelated text file before the zbateson parser is invoked.
     */
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

    /**
     * Validates that the path looks like an mboxrd archive — `.mbox`
     * extension AND a literal `From ` (note trailing space) at the
     * very start of the file. The prefix check rejects a renamed
     * `.mbox` upload of a single-message `.eml` (which would lack the
     * `From ` envelope prefix) before MboxIterator is invoked.
     */
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

    /**
     * Validates that the path looks like a PayPal `Rapport Transactiegegevens`
     * (Transaction Details Report) CSV — `.csv` extension AND a header
     * row whose token set matches one of the registered language profiles.
     *
     * Unlike `sniffAsnCsv()`, this method does NOT enforce a fixed
     * column count: PayPal exports vary in column count across language
     * profiles and across export-format revisions. The header-token
     * signature (a discriminator subset of the locale's expected
     * columns) is sufficient to distinguish a per-event PayPal CSV
     * from any other CSV.
     *
     * Two failure modes carry typed exceptions so the wizard can render
     * a specific user-facing message rather than a generic sniff
     * mismatch:
     *
     *  - `UnsupportedPaypalCsvShapeException` when the first cell is one
     *    of the `Saldorapport` (Balance Reconciliation Report) record-
     *    type tokens (`RH`, `RD`, `RF`). That export is in scope as a
     *    PayPal CSV but has a completely different column shape, so the
     *    user gets an actionable hint to re-download the per-transaction
     *    Rapport Transactiegegevens instead.
     *  - `UnsupportedPaypalCsvLanguageException` when the header tokens
     *    match the per-event shape but no registered language profile
     *    recognises them ("supported locales: nl").
     */
    private function sniffPaypalCsv(string $path, string $head): SniffResult
    {
        if (preg_match('/\.csv$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a CSV. In the PayPal portal, open the custom statements view, switch to the Betalingen tab, and download Rapport Transactiegegevens as CSV."
            );
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException('The file is empty.');
        }

        $columns = str_getcsv($firstLine, PaypalCsvLanguageProfile::DELIMITER, '"', '');

        // Reject the Saldorapport (Balance Reconciliation Report) export
        // before the language-profile check. Saldorapport rows are
        // prefixed by record-type tokens (`RH` = Report Header,
        // `RD` = Report Data header, `RF` = Report Footer) which never
        // appear as a column name in Rapport Transactiegegevens.
        // Detecting that prefix lets us surface a precise "download the
        // right export" hint instead of the confusing "language profile
        // not supported" fallback.
        $firstCell = trim($columns[0] ?? '');
        if (in_array($firstCell, ['RH', 'RD', 'RF'], strict: true)) {
            throw new UnsupportedPaypalCsvShapeException(
                'This looks like a PayPal Saldorapport (balance reconciliation report). '
                .'We need the per-transaction Rapport Transactiegegevens instead — in the PayPal portal, '
                .'open the custom statements view, switch to the Betalingen tab, and pick Rapport Transactiegegevens.'
            );
        }
        // Trim each token so the language-profile detection compares
        // against the verbatim header tokens regardless of stray
        // whitespace (`"Bruto "` / `"Kosten "` ship with a trailing
        // space inside the quoted cell in the empirical NL export; the
        // language signature lists them without the trailing space and
        // tolerates either shape).
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

    /**
     * Validates that the path looks like an ICS PDF export — `.pdf`
     * extension AND a literal `%PDF-` prefix in the first five bytes of
     * the file. The magic-byte check rejects a renamed `.pdf` upload of
     * a completely different file type before pdftotext is invoked.
     */
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

    /**
     * Validates that the path looks like an MT940 export — `.sta`, `.mt940`,
     * `.940`, or `.txt` extension AND a `:20:` Transaction Reference Number
     * tag at the start of the body (after stripping any optional SWIFT
     * block-1 envelope `{1:...}{2:...}{4: ... -}`).
     */
    private function sniffAsnMt940(string $path, string $head): SniffResult
    {
        if (preg_match('/\.(sta|mt940|940|txt)$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an MT940 export. Drop in the ASN MT940 file (.sta / .mt940 / .txt)."
            );
        }

        $body = $this->stripSwiftEnvelope($head);

        if (preg_match(AsnMt940HeaderProfile::SIGNATURE_REGEX, $body) !== 1) {
            throw new SniffMismatchException(
                'This file does not look like MT940 (no :20: tag at the start). If ASN changed their export format, file an issue.'
            );
        }

        return new SniffResult(
            format: AsnMt940HeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: AsnMt940HeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    /**
     * Returns the contents of an MT940 SWIFT block-4 envelope when present,
     * otherwise the head text unchanged. Used by the sniffer to look past
     * the wrapper for the `:20:` signature tag.
     */
    private function stripSwiftEnvelope(string $head): string
    {
        if (preg_match(AsnMt940HeaderProfile::SWIFT_ENVELOPE_REGEX, $head, $matches) === 1) {
            return $matches[1];
        }

        return $head;
    }

    /**
     * Validates that the path looks like a CAMT.053 XML export — `.xml`
     * extension AND a CAMT.053 family namespace URI inside the document
     * head. The CAMT.052 / 054 sister families fail loudly so the user
     * gets a clear "wrong family" message rather than a cryptic parser
     * error 50 KB into the file.
     */
    private function sniffAsnCamt053(string $path, string $head): SniffResult
    {
        if (preg_match('/\.xml$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like an XML file. Drop in the ASN CAMT.053 XML export."
            );
        }

        if (preg_match(AsnCamt053HeaderProfile::XML_NAMESPACE_REGEX, $head) !== 1) {
            throw new SniffMismatchException(
                'This XML file does not declare an ISO 20022 CAMT.053 namespace. '
                .'If you uploaded a CAMT.052 or CAMT.054 file by mistake, re-download '
                .'the CAMT.053 statement from the ASN portal.'
            );
        }

        return new SniffResult(
            format: AsnCamt053HeaderProfile::FORMAT,
            delimiter: '',
            hasHeader: false,
            encoding: AsnCamt053HeaderProfile::SOURCE_ENCODING,
            columnCount: 0,
        );
    }

    private function sniffAsnCsv(string $path, string $head): SniffResult
    {
        if (preg_match('/\.csv$/i', $path) !== 1) {
            throw new SniffMismatchException(
                "That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal."
            );
        }

        $firstLine = strtok($head, "\r\n");
        if ($firstLine === false) {
            throw new SniffMismatchException('The file is empty.');
        }

        $delim = AsnCsvHeaderProfile::DELIMITER;
        $columns = str_getcsv($firstLine, $delim, '"', '');

        if (! in_array(count($columns), AsnCsvHeaderProfile::ACCEPTED_COLUMN_COUNTS, strict: true)) {
            // ASN ships two CSV shapes: 20 columns (with trailing
            // `Categorie`) and 19 columns (without). Both are valid;
            // the adapter only reads columns 0..17. Anything else is
            // a different bank's export or a malformed file.
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
