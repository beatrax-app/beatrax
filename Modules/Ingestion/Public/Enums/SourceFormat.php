<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Enums;

// One case per PARSE — the file's own shape, never a bank picked from a list.
// A bank whose CSV differs only in column mapping is a CsvPresetRegistry preset,
// so a value absent from here is a preset rather than an error. IcsPdf and
// PaypalCsv stay because each export needs a parser no mapping can express.
enum SourceFormat: string
{
    case Camt053 = 'camt053';

    case Mt940 = 'mt940';

    case IcsPdf = 'ics-pdf';

    case PaypalCsv = 'paypal-csv';

    case Eml = 'eml';

    case Mbox = 'mbox';

    // Routed to the parse stage's receipt arm rather than to an adapter, which
    // is why neither is bound in SourceAdapterRegistry. Call sites each carried
    // their own copy of this pair and they had drifted apart.
    /** @var list<self> */
    private const array RECEIPT_FILES = [self::Eml, self::Mbox];

    /**
     * @return list<string> the transports a receipt arrives on
     */
    public static function receiptFormats(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::RECEIPT_FILES);
    }

    public function isReceiptFile(): bool
    {
        return in_array($this, self::RECEIPT_FILES, true);
    }

    // The extension the stored copy is written under, taken from the declared
    // format rather than from the uploaded name so a later re-read still
    // sniffs as the format it claims to be.
    public function fileExtension(): string
    {
        return match ($this) {
            self::Camt053 => '.xml',
            self::Mt940 => '.sta',
            self::IcsPdf => '.pdf',
            self::Eml => '.eml',
            self::Mbox => '.mbox',
            self::PaypalCsv => '.csv',
        };
    }
}
