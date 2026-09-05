<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Enums;

// Reported verbatim on the health surface, so the two spellings are wire
// values a probe equality-checks — never prose, and never localised, because a
// body that changes with the reader's language cannot be compared across calls.
enum NetworkBoundaryState: string
{
    case Loopback = 'loopback';

    case Widened = 'widened';
}
