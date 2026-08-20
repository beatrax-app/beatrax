<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// The review state of a discovered_senders row: a `candidate` the scan
// surfaced, `added` to the known-senders list, or `dismissed`. The column
// stays string (enforced by a trigger); this enum is the one canonical
// spelling callers map through.
enum DiscoveredSenderState: string
{
    case Candidate = 'candidate';

    case Added = 'added';

    case Dismissed = 'dismissed';
}
