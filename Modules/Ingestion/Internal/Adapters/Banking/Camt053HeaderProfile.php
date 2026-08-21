<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class Camt053HeaderProfile
{
    public const FORMAT = SourceFormat::Camt053->value;

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['xml'];

    // Any camt.053.001.NN sub-version passes the sniffer; an unsupported one
    // fails at parse time instead, so a bank upgrade doesn't fail at the door.
    public const XML_NAMESPACE_REGEX = '#xmlns(?::\w+)?\s*=\s*"urn:iso:std:iso:20022:tech:xsd:camt\.053\.001\.(\d{2})"#';

    public const SOURCE_ENCODING = 'UTF-8';
}
