<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// migration_runs.status stays a string column; this enum is the one canonical
// spelling every caller maps through.
enum MigrationRunStatus: string
{
    case Parsed = 'parsed';

    case Confirmed = 'confirmed';

    case NeedsAttention = 'needs_attention';

    case Discarded = 'discarded';
}
