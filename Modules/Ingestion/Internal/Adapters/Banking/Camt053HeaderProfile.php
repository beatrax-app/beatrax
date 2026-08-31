<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class Camt053HeaderProfile
{
    public const FORMAT = SourceFormat::Camt053->value;

    /** @var list<string> */
    public const array FILE_EXTENSIONS = ['xml'];

    // Any camt.053.001.NN sub-version passes the sniffer; an unsupported one
    // fails at parse time instead, so a bank upgrade doesn't fail at the door.
    // Either quote character, because XML allows both and pinning the double
    // quote refused a conformant export while blaming its namespace for it.
    public const string XML_NAMESPACE_REGEX = '#xmlns(?::\w+)?\s*=\s*(["\'])urn:iso:std:iso:20022:tech:xsd:camt\.053\.001\.\d{2}\1#';

    public const string SOURCE_ENCODING = 'UTF-8';
}
