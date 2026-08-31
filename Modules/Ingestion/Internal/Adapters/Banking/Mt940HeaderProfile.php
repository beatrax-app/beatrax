<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class Mt940HeaderProfile
{
    public const FORMAT = SourceFormat::Mt940->value;

    /** @var list<string> */
    public const array FILE_EXTENSIONS = ['sta', 'mt940', '940', 'txt'];

    // SWIFT block-4 envelope. The `-}` terminator tolerates whitespace: some
    // exporters put the EOM `-` on its own line and `}` on the next.
    public const string SWIFT_ENVELOPE_REGEX = '/\{4:\s*([\s\S]+?)\s*-\s*\}/';

    public const string SIGNATURE_REGEX = '/(?:^|[\r\n])\s*:20:/';

    public const string SOURCE_ENCODING = 'UTF-8';
}
