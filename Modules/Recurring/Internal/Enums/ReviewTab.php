<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Enums;

// Which slice of the detected-series queue the review page is showing. Each
// case selects a different query, so a value the enum does not name would show
// the reader an empty queue rather than the one they asked for.
enum ReviewTab: string
{
    case Pending = 'pending';

    case Rejected = 'rejected';

    case CadenceChanged = 'cadence_changed';

    // The value the page reads as when nothing sets the tab or the wire sends
    // something outside this enum, named so the property default and the
    // fallback cannot drift apart.
    public const string DEFAULT = 'pending';

    public function labelKey(): string
    {
        return 'recurring::review.tabs.'.$this->value;
    }

    public function emptyBodyKey(): string
    {
        return 'recurring::review.empty.'.$this->value;
    }
}
