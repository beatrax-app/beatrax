<?php

declare(strict_types=1);

namespace Modules\Goals\Public\Enums;

// The lifecycle of a goals row: `active` (default), then `completed` when
// the target is reached or `archived` when the user shelves it. The column
// stays string; this enum is the one canonical spelling callers map through.
enum GoalStatus: string
{
    case Active = 'active';

    case Completed = 'completed';

    case Archived = 'archived';
}
