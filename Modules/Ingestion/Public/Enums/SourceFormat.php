<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Enums;

// Every format the upload wizard accepts by name. `asn-csv` / `camt053` /
// `mt940` also key the adapter registry; `ing-csv` rides the ASN CSV adapter
// as a layout. CSV presets add further formats at runtime, so a value absent
// from this enum is a preset rather than an error.
enum SourceFormat: string
{
    case AsnCsv = 'asn-csv';

    case IngCsv = 'ing-csv';

    case Camt053 = 'camt053';

    case Mt940 = 'mt940';

    case IcsPdf = 'ics-pdf';

    case PaypalCsv = 'paypal-csv';

    case Eml = 'eml';

    case Mbox = 'mbox';

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
            self::AsnCsv, self::IngCsv, self::PaypalCsv => '.csv',
        };
    }
}
