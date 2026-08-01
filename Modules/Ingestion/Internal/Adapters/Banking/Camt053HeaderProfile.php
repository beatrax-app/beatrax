<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Enums\SourceFormat;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class Camt053HeaderProfile
{
    public const FORMAT = SourceFormat::Camt053->value;

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['xml'];

    // Anchors on the CAMT.053 family, not a specific sub-version — any
    // urn:iso:std:iso:20022:tech:xsd:camt.053.001.NN URI passes the sniffer,
    // tolerating an optional xmlns prefix; unknown sub-versions fail at
    // parse time instead, so a future bank upgrade won't fail at the door.
    public const XML_NAMESPACE_REGEX = '#xmlns(?::\w+)?\s*=\s*"urn:iso:std:iso:20022:tech:xsd:camt\.053\.001\.(\d{2})"#';

    public const SOURCE_ENCODING = 'UTF-8';
}
