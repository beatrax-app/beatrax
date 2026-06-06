<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * CAMT.053 (ISO 20022 bank-to-customer statement) profile for the ASN Online
 * Bankieren XML export.
 *
 * The `genkgo/camt` parser library covers every CAMT.053 sub-version ASN
 * emits (001.02 / 001.03 / 001.04 / 001.08); the FORMAT constant is the
 * stable identifier the SourceAdapterRegistry, the upload wizard dropdown,
 * and HeaderSniffer reference.
 *
 * The namespace regex anchors on the CAMT.053 family, not a specific
 * sub-version: any `urn:iso:std:iso:20022:tech:xsd:camt.053.001.NN` URI
 * (NN = two digits) passes the sniffer. Unknown sub-versions surface a
 * clear error at parse time rather than at sniff time so a future ASN
 * upgrade does not silently fail at the front door.
 */
final class AsnCamt053HeaderProfile
{
    public const FORMAT = 'camt053';

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['xml'];

    /**
     * ISO 20022 CAMT.053 family namespace pattern. The full URI is
     *   urn:iso:std:iso:20022:tech:xsd:camt.053.001.NN
     * with NN being the two-digit sub-version. The regex tolerates an
     * optional prefix on the `xmlns` attribute so non-default-namespace
     * documents (`xmlns:camt="…"`) still match.
     */
    public const XML_NAMESPACE_REGEX = '#xmlns(?::\w+)?\s*=\s*"urn:iso:std:iso:20022:tech:xsd:camt\.053\.001\.(\d{2})"#';

    public const SOURCE_ENCODING = 'UTF-8';
}
