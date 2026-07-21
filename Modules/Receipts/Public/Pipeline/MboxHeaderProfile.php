<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

// Format profile for an .mbox archive, used by HeaderSniffer::sniff()
// to discriminate it from a single-message .eml. The mboxrd spec
// requires every message to be preceded by a literal "From " at the
// start of a line, so the first byte of a valid mbox is always "From ".
final class MboxHeaderProfile
{
    public const FORMAT = 'mbox';

    public const SOURCE_ENCODING = 'UTF-8';

    public const MBOX_PREFIX = 'From ';
}
