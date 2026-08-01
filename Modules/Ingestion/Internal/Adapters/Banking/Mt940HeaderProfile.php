<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Modules\Ingestion\Public\Enums\SourceFormat;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class Mt940HeaderProfile
{
    public const FORMAT = SourceFormat::Mt940->value;

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['sta', 'mt940', '940', 'txt'];

    // Matches the SWIFT block-4 envelope content; the `-}` terminator may be
    // whitespace-separated since some exporters emit the EOM `-` marker on
    // its own line before closing the envelope on the next.
    public const SWIFT_ENVELOPE_REGEX = '/\{4:\s*([\s\S]+?)\s*-\s*\}/';

    // Matches the :20: Transaction Reference Number tag — the first tag of
    // every statement.
    public const SIGNATURE_REGEX = '/(?:^|[\r\n])\s*:20:/';

    public const SOURCE_ENCODING = 'UTF-8';
}
