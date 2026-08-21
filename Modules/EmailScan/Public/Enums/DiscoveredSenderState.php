<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// The column stays a string, enforced by a trigger; this enum is the one
// canonical spelling every caller maps through.
enum DiscoveredSenderState: string
{
    case Candidate = 'candidate';

    case Added = 'added';

    case Dismissed = 'dismissed';
}
